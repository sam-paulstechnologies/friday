<?php

namespace App\Services\Miriam;

use App\Models\CalendarEventMapping;
use App\Models\MedicationDoseLog;
use App\Models\MiriamDevelopmentLedger;
use App\Models\MiriamReminder;
use App\Models\MiriamSlackConversationContext;
use App\Models\MiriamToolAudit;
use App\Models\Task;
use App\Models\User;
use App\Services\Calendar\CalendarSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class MiriamToolExecutor
{
    public const DEFAULT_TIMEZONE = 'Asia/Dubai';

    public function __construct(
        private readonly MiriamToolRegistry $registry,
        private readonly CalendarSyncService $calendarSync,
    ) {}

    public function execute(string $toolName, array $input, array $context = []): array
    {
        $user = $context['user'] ?? null;
        $slackUserId = $context['slack_user_id'] ?? null;
        $channelId = $context['slack_channel_id'] ?? null;

        $this->audit('tool_selected', $toolName, $input, [], $context, 'selected');

        $tool = $this->registry->get($toolName);

        if (! $tool) {
            $output = $this->failed("I do not have a safe internal tool for that yet.");
            $this->audit('tool_failed', $toolName, $input, $output, $context, 'failed');

            return $output;
        }

        if (! $this->registry->allowsAutomatic(
            $toolName,
            (float) ($context['confidence'] ?? 1.0),
            (string) ($context['risk_level'] ?? 'low')
        )) {
            $output = [
                'ok' => false,
                'approval_required' => true,
                'message' => 'I can help with that, but I need confirmation before making that change.',
                'tool' => $toolName,
            ];
            $this->audit('approval_required', $toolName, $input, $output, $context, 'approval_required');

            return $output;
        }

        try {
            $output = match ($toolName) {
                'read_today_agenda' => $this->readAgenda($user, CarbonImmutable::now(self::DEFAULT_TIMEZONE)->startOfDay()),
                'read_tomorrow_agenda' => $this->readAgenda($user, CarbonImmutable::now(self::DEFAULT_TIMEZONE)->addDay()->startOfDay()),
                'read_calendar_range' => $this->readCalendarRange($user, $input),
                'list_reminders' => $this->listReminders($user, $input),
                'create_reminder' => $this->createReminder($user, $slackUserId, $channelId, $input, $context),
                'update_reminder_status' => $this->updateReminderStatus($user, $input),
                'list_pending_tasks' => $this->listPendingTasks($user),
                'create_task' => $this->createTaskReminder($user, $slackUserId, $channelId, $input, $context),
                'read_medication_status' => $this->readMedicationStatus($user, $input),
                'send_medication_action_card' => [
                    'ok' => false,
                    'approval_required' => true,
                    'message' => 'Medication actions must use the existing Taken, Snooze, or Skip card.',
                ],
                'read_health_summary' => $this->readHealthSummary($user),
                'read_development_status' => $this->readDevelopmentStatus(),
                'read_recent_miriam_activity' => $this->readRecentMiriamActivity($user),
                'search_miriam_memory' => $this->searchMiriamMemory($user, $input),
                default => $this->failed('That Miriam tool is not implemented yet.'),
            };

            $this->audit(($output['ok'] ?? false) ? 'tool_executed' : 'tool_failed', $toolName, $input, $output, $context, ($output['ok'] ?? false) ? 'completed' : 'failed');

            return $output;
        } catch (Throwable $exception) {
            $output = $this->failed('I hit a safe tool error while doing that. I stored the failure in Miriam.');
            $this->audit('tool_failed', $toolName, $input, $output, $context + [
                'exception' => class_basename($exception),
            ], 'failed');

            return $output;
        }
    }

    public function audit(string $eventType, ?string $toolName = null, array $input = [], array $output = [], array $context = [], ?string $status = null): void
    {
        if (! Schema::hasTable('miriam_tool_audits')) {
            return;
        }

        $user = $context['user'] ?? null;

        MiriamToolAudit::create([
            'user_id' => $user instanceof User ? $user->id : null,
            'slack_user_id' => $context['slack_user_id'] ?? null,
            'slack_channel_id' => $context['slack_channel_id'] ?? null,
            'event_type' => $eventType,
            'tool_name' => $toolName,
            'status' => $status,
            'summary' => Str::limit((string) ($output['message'] ?? $output['summary'] ?? ''), 500),
            'input' => $this->safePayload($input),
            'output' => $this->safePayload($output),
            'metadata' => $this->safePayload(collect($context)->except(['user'])->all()),
            'occurred_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    private function readAgenda(?User $user, CarbonImmutable $dayStart): array
    {
        $end = $dayStart->endOfDay();
        $label = $dayStart->isSameDay(CarbonImmutable::now(self::DEFAULT_TIMEZONE)) ? 'Today' : 'Tomorrow';
        $events = $this->calendarEventsBetween($user, $dayStart, $end);
        $reminders = $this->remindersBetween($user, $dayStart, $end);

        return [
            'ok' => true,
            'message' => $this->agendaSummary($label, $events, $reminders),
            'detail' => $this->agendaDetail($label, $events, $reminders),
            'context_type' => 'agenda',
            'payload' => [
                'date' => $dayStart->toDateString(),
                'event_count' => $events->count(),
                'reminder_count' => $reminders->count(),
            ],
        ];
    }

    private function readCalendarRange(?User $user, array $input): array
    {
        $start = CarbonImmutable::parse((string) ($input['start_date'] ?? 'today'), self::DEFAULT_TIMEZONE)->startOfDay();
        $end = CarbonImmutable::parse((string) ($input['end_date'] ?? $start->toDateString()), self::DEFAULT_TIMEZONE)->endOfDay();
        $events = $this->calendarEventsBetween($user, $start, $end);

        return [
            'ok' => true,
            'message' => $events->isEmpty() ? 'No calendar events found in that range.' : $events->count().' calendar events found. Reply "show me" for details.',
            'detail' => $this->calendarDetail('Calendar events', $events),
            'context_type' => 'agenda',
            'payload' => ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()],
        ];
    }

    private function listReminders(?User $user, array $input): array
    {
        $period = (string) ($input['period'] ?? 'today');
        $start = Str::contains($period, 'tomorrow')
            ? CarbonImmutable::now(self::DEFAULT_TIMEZONE)->addDay()->startOfDay()
            : CarbonImmutable::now(self::DEFAULT_TIMEZONE)->startOfDay();
        $end = $start->endOfDay();
        $reminders = $this->remindersBetween($user, $start, $end);
        $label = $start->isSameDay(CarbonImmutable::now(self::DEFAULT_TIMEZONE)->addDay()) ? 'Tomorrow' : 'Today';
        $detail = $reminders->isEmpty()
            ? "{$label}: no Miriam reminders found."
            : "{$label} reminders:\n".$reminders->map(fn (MiriamReminder $reminder): string => '- '.$this->localTime($reminder->due_at, $reminder->timezone ?: self::DEFAULT_TIMEZONE).' - '.$reminder->title)->implode("\n");

        return [
            'ok' => true,
            'message' => $detail,
            'detail' => $detail,
            'context_type' => 'reminders',
            'payload' => [
                'date' => $start->toDateString(),
                'reminder_count' => $reminders->count(),
                'last_reminder_id' => $reminders->first()?->id,
            ],
        ];
    }

    private function createReminder(?User $user, ?string $slackUserId, ?string $channelId, array $input, array $context): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $timezone = (string) ($input['timezone'] ?? self::DEFAULT_TIMEZONE);
        $dueAt = filled($input['due_at_local'] ?? null) ? CarbonImmutable::parse((string) $input['due_at_local'], $timezone) : null;

        if ($title === '' || ! $dueAt) {
            return $this->failed('I need a title and a future time before saving that reminder.');
        }

        if ($dueAt->lte(CarbonImmutable::now($timezone))) {
            return $this->failed('That time is in the past. What future time should I use?');
        }

        $reminder = MiriamReminder::create([
            'user_id' => $user?->id,
            'category' => $input['category'] ?? 'unknown',
            'item_type' => $input['item_type'] ?? 'reminder',
            'title' => $title,
            'timezone' => $timezone,
            'confidence' => $context['confidence'] ?? 1,
            'due_at' => $dueAt->utc(),
            'status' => 'pending',
            'next_reminder_at' => $dueAt->utc(),
            'slack_user_id' => $slackUserId,
            'slack_channel_id' => $channelId,
            'source_message_ts' => $context['source_message_ts'] ?? null,
            'metadata' => [
                'source' => 'miriam_tool_gateway',
                'original_text' => $context['original_text'] ?? null,
            ],
        ]);

        $reminder->events()->create([
            'event_type' => 'captured',
            'channel' => 'slack',
            'occurred_at' => CarbonImmutable::now('UTC'),
            'metadata' => ['source' => 'miriam_tool_gateway'],
        ]);

        $this->calendarSync->syncMiriamReminder($reminder);

        return [
            'ok' => true,
            'message' => 'Saved reminder: '.$title.' at '.$dueAt->format('M j, g:i A').'.',
            'detail' => 'Saved reminder: '.$title.' at '.$dueAt->format('M j, g:i A').'.',
            'context_type' => 'reminder',
            'payload' => ['reminder_id' => $reminder->id],
            'reminder_id' => $reminder->id,
        ];
    }

    private function updateReminderStatus(?User $user, array $input): array
    {
        $reminderId = (int) ($input['reminder_id'] ?? 0);
        $status = (string) ($input['status'] ?? '');
        $reminder = MiriamReminder::query()
            ->when($user, fn ($query) => $query->where('user_id', $user->id))
            ->find($reminderId);

        if (! $reminder || ! in_array($status, ['done', 'cancelled'], true)) {
            return $this->failed('I could not safely identify the reminder to update.');
        }

        if ($status === 'done' && $reminder->status !== 'done') {
            $reminder->forceFill([
                'status' => 'done',
                'completed_at' => CarbonImmutable::now('UTC'),
                'next_reminder_at' => null,
            ])->save();
        }

        if ($status === 'cancelled' && $reminder->status !== 'cancelled') {
            $reminder->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => CarbonImmutable::now('UTC'),
                'next_reminder_at' => null,
            ])->save();
        }

        $message = $status === 'done'
            ? 'Done - '.$reminder->title
            : 'Cancelled - '.$reminder->title;

        return [
            'ok' => true,
            'message' => $message,
            'detail' => $message,
            'context_type' => 'reminder',
            'payload' => ['reminder_id' => $reminder->id, 'status' => $status],
            'reminder_id' => $reminder->id,
        ];
    }

    private function listPendingTasks(?User $user): array
    {
        if (! Schema::hasTable('tasks')) {
            return $this->failed('Tasks are not available yet.');
        }

        $tasks = Task::query()
            ->when($user, fn ($query) => $query->where(function ($inner) use ($user): void {
                $inner->where('assignee_id', $user->id)->orWhere('reporter_id', $user->id);
            }))
            ->whereNotIn('status', ['completed', 'archived'])
            ->orderBy('due_date')
            ->limit(10)
            ->get();

        $message = $tasks->isEmpty()
            ? 'No pending TaskFlow tasks found.'
            : "Pending tasks:\n".$tasks->map(fn (Task $task): string => '- '.$task->title)->implode("\n");

        return [
            'ok' => true,
            'message' => $message,
            'detail' => $message,
            'context_type' => 'tasks',
            'payload' => ['task_count' => $tasks->count()],
        ];
    }

    private function createTaskReminder(?User $user, ?string $slackUserId, ?string $channelId, array $input, array $context): array
    {
        return $this->createReminder($user, $slackUserId, $channelId, $input + [
            'item_type' => 'task',
            'category' => 'work',
        ], $context);
    }

    private function readMedicationStatus(?User $user, array $input): array
    {
        if (! $user) {
            return $this->failed('I could not find your Miriam user for medication status.');
        }

        $today = CarbonImmutable::now(self::DEFAULT_TIMEZONE)->toDateString();
        $doseKey = $input['dose_key'] ?? null;
        $logs = MedicationDoseLog::query()
            ->with('schedule')
            ->where('user_id', $user->id)
            ->whereDate('dose_date', $today)
            ->get()
            ->filter(fn (MedicationDoseLog $log): bool => ! $doseKey || $log->schedule?->dose_key === $doseKey)
            ->values();

        $message = $logs->isEmpty()
            ? 'No medication dose log found for today.'
            : $logs->map(fn (MedicationDoseLog $log): string => ($log->schedule?->label ?: ucfirst((string) $log->schedule?->dose_key)).': '.$log->status)->implode("\n");

        return [
            'ok' => true,
            'message' => $message,
            'detail' => $message,
            'context_type' => 'health_status',
            'payload' => ['dose_key' => $doseKey, 'log_count' => $logs->count()],
        ];
    }

    private function readHealthSummary(?User $user): array
    {
        $status = $this->readMedicationStatus($user, []);

        return [
            'ok' => $status['ok'],
            'message' => $status['ok'] ? "Health summary:\n".$status['message'] : $status['message'],
            'detail' => $status['detail'] ?? $status['message'],
            'context_type' => 'health_status',
            'payload' => $status['payload'] ?? [],
        ];
    }

    private function readDevelopmentStatus(): array
    {
        if (! Schema::hasTable('miriam_development_ledgers')) {
            return $this->failed('Development ledger is not available yet.');
        }

        $rows = MiriamDevelopmentLedger::query()->latest()->limit(5)->get();
        $message = $rows->isEmpty()
            ? 'No recent Miriam development ledger entries found.'
            : "Recent development:\n".$rows->map(fn (MiriamDevelopmentLedger $ledger): string => '- '.($ledger->app_name ?: 'Miriam').': '.$ledger->status.' - '.Str::limit((string) $ledger->summary, 80))->implode("\n");

        return [
            'ok' => true,
            'message' => $message,
            'detail' => $message,
            'context_type' => 'development_status',
            'payload' => ['ledger_count' => $rows->count()],
        ];
    }

    private function readRecentMiriamActivity(?User $user): array
    {
        $reminders = MiriamReminder::query()
            ->when($user, fn ($query) => $query->where('user_id', $user->id))
            ->latest()
            ->limit(5)
            ->get();

        $message = $reminders->isEmpty()
            ? 'No recent Miriam reminder activity found.'
            : "Recent Miriam activity:\n".$reminders->map(fn (MiriamReminder $reminder): string => '- '.$reminder->status.' - '.$reminder->title)->implode("\n");

        return [
            'ok' => true,
            'message' => $message,
            'detail' => $message,
            'context_type' => 'activity',
            'payload' => ['reminder_count' => $reminders->count()],
        ];
    }

    private function searchMiriamMemory(?User $user, array $input): array
    {
        $query = trim((string) ($input['query'] ?? ''));

        if ($query === '') {
            return $this->failed('What should I search for?');
        }

        $reminders = MiriamReminder::query()
            ->when($user, fn ($builder) => $builder->where('user_id', $user->id))
            ->where('title', 'like', '%'.$query.'%')
            ->latest()
            ->limit(8)
            ->get();

        $message = $reminders->isEmpty()
            ? 'No matching Miriam memory found.'
            : "Miriam memory matches:\n".$reminders->map(fn (MiriamReminder $reminder): string => '- '.$reminder->title.' ('.$reminder->status.')')->implode("\n");

        return [
            'ok' => true,
            'message' => $message,
            'detail' => $message,
            'context_type' => 'memory_search',
            'payload' => ['query' => $query, 'match_count' => $reminders->count()],
        ];
    }

    public function storeContext(string $slackUserId, string $channelId, ?User $user, array $result): void
    {
        if (! ($result['context_type'] ?? null)) {
            return;
        }

        MiriamSlackConversationContext::create([
            'user_id' => $user?->id,
            'slack_user_id' => $slackUserId,
            'slack_channel_id' => $channelId,
            'context_type' => (string) $result['context_type'],
            'summary' => $result['message'] ?? null,
            'detail' => $result['detail'] ?? $result['message'] ?? null,
            'payload' => $result['payload'] ?? [],
            'expires_at' => CarbonImmutable::now('UTC')->addHours(6),
        ]);
    }

    public function latestContext(string $slackUserId, string $channelId, ?string $type = null): ?MiriamSlackConversationContext
    {
        return MiriamSlackConversationContext::query()
            ->where('slack_user_id', $slackUserId)
            ->where('slack_channel_id', $channelId)
            ->when($type, fn ($query) => $query->where('context_type', $type))
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', CarbonImmutable::now('UTC'));
            })
            ->latest()
            ->first();
    }

    private function remindersBetween(?User $user, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return MiriamReminder::query()
            ->when($user, fn ($query) => $query->where('user_id', $user->id))
            ->whereNotIn('status', ['done', 'cancelled'])
            ->whereBetween('due_at', [$start->utc(), $end->utc()])
            ->orderBy('due_at')
            ->get();
    }

    private function calendarEventsBetween(?User $user, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        if (! $user) {
            return collect();
        }

        return CalendarEventMapping::query()
            ->where('user_id', $user->id)
            ->where('provider', 'google')
            ->get()
            ->filter(function (CalendarEventMapping $mapping) use ($start, $end): bool {
                $metadata = $mapping->metadata ?? [];
                $date = $metadata['date'] ?? $metadata['start_date'] ?? null;

                return is_string($date) && $date >= $start->toDateString() && $date <= $end->toDateString();
            })
            ->sortBy(fn (CalendarEventMapping $mapping): string => (string) (($mapping->metadata ?? [])['date'] ?? ''))
            ->values();
    }

    private function agendaSummary(string $label, Collection $events, Collection $reminders): string
    {
        if ($events->isEmpty() && $reminders->isEmpty()) {
            return "{$label}: no calendar events or Miriam reminders found.";
        }

        $parts = [];

        if ($events->isNotEmpty()) {
            $parts[] = $events->count().' calendar '.Str::plural('event', $events->count());
        }

        if ($reminders->isNotEmpty()) {
            $parts[] = $reminders->count().' Miriam '.Str::plural('reminder', $reminders->count());
        }

        return "{$label}: ".implode(', ', $parts).'. Reply "show me" for details.';
    }

    private function agendaDetail(string $label, Collection $events, Collection $reminders): string
    {
        $lines = [$label.' agenda:'];
        $lines[] = $this->calendarDetail('Calendar', $events);
        $lines[] = $reminders->isEmpty()
            ? 'Miriam reminders: none found.'
            : "Miriam reminders:\n".$reminders->map(fn (MiriamReminder $reminder): string => '- '.$this->localTime($reminder->due_at, $reminder->timezone ?: self::DEFAULT_TIMEZONE).' - '.$reminder->title)->implode("\n");

        return implode("\n", $lines);
    }

    private function calendarDetail(string $label, Collection $events): string
    {
        if ($events->isEmpty()) {
            return $label.': none found.';
        }

        return $label.":\n".$events->map(function (CalendarEventMapping $event): string {
            $metadata = $event->metadata ?? [];
            $time = $metadata['time'] ?? $metadata['start_time'] ?? null;

            return '- '.($time ? $time.' - ' : '').($metadata['title'] ?? 'Calendar event');
        })->implode("\n");
    }

    private function localTime($value, string $timezone): string
    {
        return CarbonImmutable::parse($value)->setTimezone($timezone)->format('g:i A');
    }

    private function failed(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'detail' => $message];
    }

    private function safePayload(array $payload): array
    {
        return collect($payload)
            ->except(['api_key', 'token', 'secret', 'authorization', 'password'])
            ->map(function ($value) {
                if ($value instanceof User) {
                    return ['id' => $value->id];
                }

                return $value;
            })
            ->all();
    }
}
