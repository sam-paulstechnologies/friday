<?php

namespace App\Services\Mobile;

use App\Models\CalendarEventMapping;
use App\Models\MedicationDoseLog;
use App\Models\MiriamDevelopmentFailure;
use App\Models\MiriamDevelopmentJob;
use App\Models\MiriamDevelopmentLedger;
use App\Models\MiriamReminder;
use App\Models\MiriamSlackConversationContext;
use App\Models\User;
use App\Services\Health\MedicationReminderService;
use App\Services\Miriam\MiriamBrainService;
use App\Services\Miriam\MiriamToolExecutor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MiriamMobileApiService
{
    public const DEFAULT_TIMEZONE = 'Asia/Dubai';

    public function __construct(
        private readonly MedicationReminderService $medication,
        private readonly MiriamBrainService $brain,
        private readonly MiriamToolExecutor $tools,
    ) {}

    public function dashboard(User $user): array
    {
        $todayAgenda = $this->agenda($user, 'today');
        $tomorrowAgenda = $this->agenda($user, 'tomorrow');
        $reminders = $this->pendingReminders($user, 5);
        $medication = $this->medicationStatus($user);
        $development = $this->developmentStatus($user);

        return [
            'today_summary' => [
                'agenda_count' => count($todayAgenda['items']),
                'next_reminder' => $reminders[0] ?? null,
                'medication_pending' => collect($medication['today'])->whereNotIn('status', ['taken', 'skipped'])->count(),
                'development_status' => $development['summary'],
            ],
            'next_reminders' => $reminders,
            'medication' => $medication,
            'shortcuts' => [
                'tomorrow_agenda' => [
                    'label' => 'Tomorrow agenda',
                    'count' => count($tomorrowAgenda['items']),
                ],
                'development_status' => [
                    'label' => 'Codex status',
                    'summary' => $development['summary'],
                ],
            ],
        ];
    }

    public function chat(User $user, string $message): array
    {
        $message = trim($message);

        if ($message === '') {
            return ['reply' => 'What would you like Miriam to do?', 'action' => 'clarify'];
        }

        if (preg_match('/\b(mark|set)\s+(that|it)\s+(done|complete|completed)\b/i', $message)) {
            $reminder = MiriamReminder::query()
                ->where('user_id', $user->id)
                ->whereNotIn('status', ['done', 'cancelled'])
                ->orderBy('due_at')
                ->first();

            if (! $reminder) {
                return ['reply' => 'I could not find a pending reminder to mark done.', 'action' => 'none'];
            }

            $reminder->forceFill([
                'status' => 'done',
                'completed_at' => CarbonImmutable::now('UTC'),
                'next_reminder_at' => null,
            ])->save();

            $reminder->events()->create([
                'event_type' => 'mobile_done',
                'channel' => 'mobile',
                'occurred_at' => CarbonImmutable::now('UTC'),
                'metadata' => ['source' => 'miriam_mobile_chat'],
            ]);

            return ['reply' => 'Done - '.$reminder->title, 'action' => 'update_reminder_status', 'reminder' => $this->reminderResource($reminder->fresh())];
        }

        $selection = $this->brain->selectTool($message, $user);
        $tool = $selection['tool'] ?? null;

        if ($tool) {
            $result = $this->tools->execute((string) $tool, $selection['arguments'] ?? [], [
                'user' => $user,
                'original_text' => $message,
                'confidence' => $selection['confidence'] ?? 1.0,
                'risk_level' => $selection['risk_level'] ?? 'low',
            ]);

            return [
                'reply' => $result['message'] ?? 'Done.',
                'action' => $tool,
                'result' => collect($result)->except(['detail'])->all(),
            ];
        }

        return ['reply' => 'I can show your agenda, meds, reminders, development status, or save a reminder.', 'action' => 'clarify'];
    }

    public function pendingReminders(User $user, int $limit = 25): array
    {
        return MiriamReminder::query()
            ->where('user_id', $user->id)
            ->whereNotIn('status', ['done', 'cancelled'])
            ->orderBy('due_at')
            ->limit($limit)
            ->get()
            ->map(fn (MiriamReminder $reminder) => $this->reminderResource($reminder))
            ->all();
    }

    public function updateReminder(User $user, MiriamReminder $reminder, string $action): MiriamReminder
    {
        abort_unless((int) $reminder->user_id === (int) $user->id, 403);

        match ($action) {
            'done' => $reminder->forceFill([
                'status' => 'done',
                'completed_at' => CarbonImmutable::now('UTC'),
                'next_reminder_at' => null,
            ])->save(),
            'snooze' => $reminder->forceFill([
                'status' => 'snoozed',
                'next_reminder_at' => CarbonImmutable::now('UTC')->addMinutes(15),
            ])->save(),
            'cancel' => $reminder->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => CarbonImmutable::now('UTC'),
                'next_reminder_at' => null,
            ])->save(),
            default => abort(422, 'Unsupported reminder action.'),
        };

        $reminder->events()->create([
            'event_type' => 'mobile_'.$action,
            'channel' => 'mobile',
            'occurred_at' => CarbonImmutable::now('UTC'),
            'metadata' => [],
        ]);

        return $reminder->fresh();
    }

    public function medicationStatus(User $user): array
    {
        $this->medication->ensureLogsForActiveSchedules();
        $status = $this->medication->statusForUser($user);

        return [
            'routine' => collect($status['routine'] ?? [])->map(fn (array $schedule) => [
                'id' => $schedule['id'],
                'dose_key' => $schedule['dose_key'],
                'label' => $schedule['label'],
                'time' => $schedule['schedule_time'],
                'timing_note' => $schedule['timing_note'],
                'items' => $schedule['medication_items'] ?? [],
                'hard_deadline_time' => $schedule['hard_deadline_time'] ?? null,
            ])->values()->all(),
            'today' => collect($status['items'] ?? [])->map(fn (array $log) => [
                'id' => $log['id'],
                'dose_key' => $log['dose_key'] ?? null,
                'label' => $log['label'],
                'scheduled_for' => $log['scheduled_for'],
                'status' => $log['status'],
                'next_reminder_at' => $log['next_reminder_at'] ?? null,
                'items' => $log['medication_items'] ?? [],
            ])->values()->all(),
        ];
    }

    public function updateDose(User $user, MedicationDoseLog $doseLog, string $action, array $payload = []): MedicationDoseLog
    {
        abort_unless((int) $doseLog->user_id === (int) $user->id, 403);

        return match ($action) {
            'taken' => $this->medication->markTaken($doseLog, 'mobile', 'mobile'),
            'snooze' => $this->medication->snooze($doseLog, (int) ($payload['minutes'] ?? 15), 'mobile', 'mobile'),
            'skip' => $this->medication->skip($doseLog, (string) ($payload['reason'] ?? ''), 'mobile', 'mobile'),
            default => abort(422, 'Unsupported medication action.'),
        };
    }

    public function agenda(User $user, string $period): array
    {
        $start = match ($period) {
            'tomorrow' => CarbonImmutable::now(self::DEFAULT_TIMEZONE)->addDay()->startOfDay(),
            'upcoming' => CarbonImmutable::now(self::DEFAULT_TIMEZONE)->startOfDay(),
            default => CarbonImmutable::now(self::DEFAULT_TIMEZONE)->startOfDay(),
        };
        $end = $period === 'upcoming' ? $start->addDays(14)->endOfDay() : $start->endOfDay();
        $reminders = $this->remindersBetween($user, $start, $end);
        $events = $this->calendarEventsBetween($user, $start, $end);

        return [
            'period' => $period,
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'items' => $events
                ->concat($reminders)
                ->sortBy('starts_at')
                ->values()
                ->all(),
        ];
    }

    public function developmentStatus(User $user): array
    {
        $jobs = MiriamDevelopmentJob::query()
            ->with(['managedApp', 'currentPhase'])
            ->whereIn('status', ['queued', 'waiting_for_runner', 'preparing', 'running', 'waiting_for_approval', 'waiting_for_manual_fix', 'blocked', 'failed'])
            ->latest()
            ->limit(10)
            ->get();
        $ledgers = MiriamDevelopmentLedger::query()->latest()->limit(10)->get();
        $attention = MiriamDevelopmentFailure::query()
            ->with(['job.managedApp'])
            ->whereIn('status', ['manual_attention_required', 'failed'])
            ->where(function ($query): void {
                $query->where('needs_user_at_system', true)->orWhere('severity', 'critical');
            })
            ->latest()
            ->limit(5)
            ->get();

        return [
            'summary' => $jobs->isEmpty() ? 'No active Codex development jobs.' : $jobs->count().' active Codex development job(s).',
            'jobs' => $jobs->map(fn (MiriamDevelopmentJob $job) => [
                'id' => $job->id,
                'app' => $job->managedApp?->name ?: 'Miriam',
                'title' => $job->title,
                'phase' => $job->currentPhase?->title,
                'status' => $job->status,
                'started_at' => $job->started_at?->toIso8601String(),
            ])->all(),
            'recent' => $ledgers->map(fn (MiriamDevelopmentLedger $ledger) => [
                'id' => $ledger->id,
                'app' => $ledger->app_name ?: 'Miriam',
                'development_name' => $ledger->development_name,
                'summary' => $ledger->summary,
                'status' => $ledger->status,
                'commit' => $ledger->commit_hash,
            ])->all(),
            'needs_attention' => $attention->map(fn (MiriamDevelopmentFailure $failure) => [
                'id' => $failure->id,
                'app' => $failure->job?->managedApp?->name ?: 'Miriam',
                'reason' => $failure->summary ?: $failure->title,
                'status' => $failure->status,
            ])->all(),
        ];
    }

    private function reminderResource(?MiriamReminder $reminder): ?array
    {
        if (! $reminder) {
            return null;
        }

        return [
            'id' => $reminder->id,
            'title' => $reminder->title,
            'category' => $reminder->category,
            'item_type' => $reminder->item_type,
            'status' => $reminder->status,
            'due_at' => $reminder->due_at?->toIso8601String(),
            'due_at_local' => $reminder->due_at?->setTimezone($reminder->timezone ?: self::DEFAULT_TIMEZONE)->format('Y-m-d H:i:s'),
            'timezone' => $reminder->timezone ?: self::DEFAULT_TIMEZONE,
            'next_reminder_at' => $reminder->next_reminder_at?->toIso8601String(),
        ];
    }

    private function remindersBetween(User $user, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return MiriamReminder::query()
            ->where('user_id', $user->id)
            ->whereBetween('due_at', [$start->utc(), $end->utc()])
            ->whereNotIn('status', ['cancelled'])
            ->get()
            ->map(fn (MiriamReminder $reminder) => [
                'id' => 'reminder-'.$reminder->id,
                'source' => 'miriam_reminder',
                'title' => $reminder->title,
                'starts_at' => $reminder->due_at?->toIso8601String(),
                'local_time' => $reminder->due_at?->setTimezone($reminder->timezone ?: self::DEFAULT_TIMEZONE)->format('g:i A'),
                'status' => $reminder->status,
            ]);
    }

    private function calendarEventsBetween(User $user, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return CalendarEventMapping::query()
            ->where('user_id', $user->id)
            ->where('provider', 'google')
            ->get()
            ->filter(function (CalendarEventMapping $mapping) use ($start, $end): bool {
                $metadata = $mapping->metadata ?? [];

                if (($metadata['source'] ?? null) === 'miriam_general_reminder') {
                    return false;
                }

                $date = $metadata['date'] ?? $metadata['start_date'] ?? null;

                return is_string($date) && $date >= $start->toDateString() && $date <= $end->toDateString();
            })
            ->map(function (CalendarEventMapping $mapping): array {
                $metadata = $mapping->metadata ?? [];

                return [
                    'id' => 'calendar-'.$mapping->id,
                    'source' => 'google_calendar',
                    'title' => $metadata['title'] ?? 'Calendar event',
                    'starts_at' => $metadata['date'] ?? $metadata['start_date'] ?? null,
                    'local_time' => $metadata['time'] ?? $metadata['start_time'] ?? null,
                    'status' => null,
                ];
            });
    }
}
