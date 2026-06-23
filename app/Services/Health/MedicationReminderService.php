<?php

namespace App\Services\Health;

use App\Jobs\SendMedicationReminderJob;
use App\Models\DailyHealthLog;
use App\Models\MedicationDoseLog;
use App\Models\MedicationDoseSchedule;
use App\Models\MedicationReminderEvent;
use App\Models\User;
use App\Notifications\TaskFlowNotification;
use App\Services\Calendar\CalendarSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class MedicationReminderService
{
    public const DEFAULT_TIMEZONE = 'Asia/Dubai';
    public const ACKNOWLEDGED_STATUSES = ['taken', 'skipped'];
    private const MORNING_DEADLINE_TIME = '10:00:00';
    private const CRITICAL_REPEAT_MINUTES = 5;
    private const WEDNESDAY_ISO = 3;

    public function __construct(private readonly CalendarSyncService $calendarSyncService) {}

    public function configureDailyRoutine(User $user, string $breakfastTime, string $dinnerTime): Collection
    {
        $workspaceId = collect($user->accessibleWorkspaceIds())->first();

        return collect([
            [
                'dose_key' => 'morning',
                'label' => 'Morning medications',
                'dosage_text' => 'Xigduo XR 5mg/1000mg; Physiotens 0.2mg; Lodiva 10mg/160mg; Aterpen 10mg/20mg',
                'timing_note' => 'after breakfast',
                'schedule_time' => $breakfastTime,
                'hard_deadline_time' => self::MORNING_DEADLINE_TIME,
                'metadata' => [
                    'frequency' => 'daily',
                    'medication_items' => [
                        ['name' => 'Xigduo XR 5mg/1000mg', 'instruction' => 'Take twice daily', 'timing' => 'Morning after breakfast'],
                        ['name' => 'Physiotens 0.2mg', 'timing' => 'Morning after breakfast'],
                        ['name' => 'Lodiva 10mg/160mg', 'timing' => 'Morning after breakfast'],
                        ['name' => 'Aterpen 10mg/20mg', 'timing' => 'Morning after breakfast'],
                    ],
                ],
            ],
            [
                'dose_key' => 'evening',
                'label' => 'Evening medication',
                'dosage_text' => 'Xigduo XR 5mg/1000mg',
                'timing_note' => 'after dinner',
                'schedule_time' => $dinnerTime,
                'hard_deadline_time' => null,
                'metadata' => [
                    'frequency' => 'daily',
                    'medication_items' => [
                        ['name' => 'Xigduo XR 5mg/1000mg', 'instruction' => 'Take twice daily', 'timing' => 'Night after dinner'],
                    ],
                ],
            ],
            [
                'dose_key' => 'weekly_ozempic',
                'label' => 'Weekly medication',
                'dosage_text' => 'Ozempic',
                'timing_note' => 'every Wednesday at 07:00',
                'schedule_time' => '07:00',
                'hard_deadline_time' => null,
                'metadata' => [
                    'frequency' => 'weekly',
                    'weekday' => self::WEDNESDAY_ISO,
                    'weekday_name' => 'Wednesday',
                    'medication_items' => [
                        ['name' => 'Ozempic', 'timing' => 'Every Wednesday at 07:00 Asia/Dubai'],
                    ],
                ],
            ],
        ])->map(fn (array $dose) => MedicationDoseSchedule::updateOrCreate(
            [
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'dose_key' => $dose['dose_key'],
            ],
            [
                ...$dose,
                'timezone' => self::DEFAULT_TIMEZONE,
                'active' => true,
                'repeat_interval_minutes' => 30,
                'quiet_hours_start' => '22:00:00',
                'quiet_hours_end' => '07:00:00',
                'hide_details_in_notifications' => true,
                'default_channel' => 'database',
                'metadata' => [
                    'routine_source' => 'miriam_medication_routine_configure',
                    'medical_guidance_locked' => true,
                    ...($dose['metadata'] ?? []),
                ],
            ]
        ));
    }

    public function ensureLogsForActiveSchedules(?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now('UTC');

        return MedicationDoseSchedule::query()
            ->with('user')
            ->where('active', true)
            ->whereNotNull('schedule_time')
            ->get()
            ->map(fn (MedicationDoseSchedule $schedule) => $this->ensureLogForSchedule($schedule, $now))
            ->filter()
            ->values();
    }

    public function ensureLogForSchedule(MedicationDoseSchedule $schedule, ?CarbonImmutable $now = null): ?MedicationDoseLog
    {
        $now ??= CarbonImmutable::now('UTC');
        $timezone = $schedule->timezone ?: self::DEFAULT_TIMEZONE;
        $localNow = $now->setTimezone($timezone);

        if (! $this->scheduleIsDueOnDate($schedule, $localNow)) {
            return null;
        }

        $doseDate = $localNow->toDateString();
        $scheduledFor = $this->scheduledFor($schedule, $doseDate);

        $log = MedicationDoseLog::query()
            ->where('dose_schedule_id', $schedule->id)
            ->whereDate('dose_date', $doseDate)
            ->first();

        if (! $log) {
            $log = MedicationDoseLog::create([
                'user_id' => $schedule->user_id,
                'workspace_id' => $schedule->workspace_id,
                'dose_schedule_id' => $schedule->id,
                'dose_date' => $doseDate,
                'scheduled_for' => $scheduledFor,
                'scheduled_timezone' => $timezone,
                'status' => 'pending',
                'next_reminder_at' => $scheduledFor,
            ]);
        }

        if ($log->wasRecentlyCreated) {
            $this->recordEvent($log, 'scheduled', metadata: [
                'scheduled_for' => $scheduledFor->toIso8601String(),
                'timezone' => $timezone,
            ]);
        }

        return $log;
    }

    public function queueDueReminders(?CarbonImmutable $now = null, bool $sync = false, ?string $channel = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $this->ensureLogsForActiveSchedules($now);

        $logs = MedicationDoseLog::query()
            ->with(['schedule', 'user'])
            ->whereIn('status', ['pending', 'snoozed', 'overdue', 'critical_overdue'])
            ->whereNotNull('next_reminder_at')
            ->where('next_reminder_at', '<=', $now)
            ->get();

        $queued = 0;
        $suppressed = 0;

        foreach ($logs as $log) {
            if (! $log->schedule || ! $log->user) {
                continue;
            }

            if ($this->isQuietTime($log->schedule, $now)) {
                $this->suppressForQuietHours($log, $now);
                $suppressed++;
                continue;
            }

            if ($sync) {
                $this->deliverReminder($log->id, $channel ?: $log->schedule->default_channel, $now);
            } else {
                SendMedicationReminderJob::dispatch($log->id, $channel ?: $log->schedule->default_channel);
            }

            $queued++;
            $this->recordEvent($log, 'reminder_queued', $channel ?: $log->schedule->default_channel);
        }

        return ['queued' => $queued, 'quiet_hours_suppressed' => $suppressed];
    }

    public function deliverReminder(int $doseLogId, string $channel = 'database', ?CarbonImmutable $now = null): ?MedicationDoseLog
    {
        $now ??= CarbonImmutable::now('UTC');

        return DB::transaction(function () use ($doseLogId, $channel, $now): ?MedicationDoseLog {
            /** @var MedicationDoseLog|null $log */
            $log = MedicationDoseLog::query()
                ->with(['schedule', 'user'])
                ->whereKey($doseLogId)
                ->lockForUpdate()
                ->first();

            if (! $log || ! $log->schedule || ! $log->user) {
                return null;
            }

            if (in_array($log->status, self::ACKNOWLEDGED_STATUSES, true)) {
                $this->recordEvent($log, 'duplicate_prevented', $channel);

                return $log;
            }

            if ($log->next_reminder_at && $log->next_reminder_at->greaterThan($now)) {
                $this->recordEvent($log, 'duplicate_prevented', $channel);

                return $log;
            }

            if ($this->isQuietTime($log->schedule, $now)) {
                $this->suppressForQuietHours($log, $now);

                return $log->refresh();
            }

            $attempts = $log->reminder_attempts + 1;
            $escalationLevel = $this->escalationLevel($log, $now);
            $nextReminderAt = $this->nextReminderAt($log, $now);
            $log->update([
                'status' => $this->reminderStatus($log, $now),
                'reminder_attempts' => $attempts,
                'first_reminded_at' => $log->first_reminded_at ?: $now,
                'last_reminded_at' => $now,
                'next_reminder_at' => $nextReminderAt,
                'last_delivery_channel' => $channel,
            ]);

            $log->user->notify(new TaskFlowNotification(
                'Medication reminder',
                $this->notificationMessage($log->schedule),
                actionUrl: route('health.index', absolute: false),
                eventType: 'medication_reminder'
            ));

            $this->recordEvent($log->fresh(['schedule']), 'reminder_sent', $channel, metadata: [
                'attempt' => $attempts,
                'escalation_level' => $escalationLevel,
                'next_reminder_at' => $nextReminderAt?->toIso8601String(),
                'preview_hidden' => (bool) $log->schedule->hide_details_in_notifications,
            ]);

            $freshLog = $log->fresh(['schedule']);
            $this->sendSlackReminder($freshLog, $attempts, $escalationLevel);
            $this->syncCalendarReminder($freshLog);

            return $freshLog;
        });
    }

    public function markTaken(MedicationDoseLog $log, string $source = 'web', string $channel = 'web'): MedicationDoseLog
    {
        return $this->acknowledge($log, 'taken', $source, $channel);
    }

    public function skip(MedicationDoseLog $log, ?string $reason = null, string $source = 'web', string $channel = 'web'): MedicationDoseLog
    {
        abort_if(blank($reason), 422, 'A skip reason is required.');

        return $this->acknowledge($log, 'skipped', $source, $channel, $reason);
    }

    public function snooze(MedicationDoseLog $log, int $minutes = 30, string $source = 'web', string $channel = 'web'): MedicationDoseLog
    {
        $log->loadMissing('schedule');
        abort_if(in_array($log->status, self::ACKNOWLEDGED_STATUSES, true), 422, 'This dose is already acknowledged.');

        $now = CarbonImmutable::now('UTC');
        $snoozedUntil = $this->snoozeUntil($log, $now, max(5, min($minutes, 240)));
        $log->update([
            'status' => 'snoozed',
            'next_reminder_at' => $snoozedUntil,
            'acknowledgement_source' => $source,
            'acknowledgement_channel' => $channel,
        ]);

        $this->recordEvent($log->fresh(['schedule']), 'snoozed', $channel, metadata: [
            'source' => $source,
            'next_reminder_at' => $snoozedUntil->toIso8601String(),
        ]);

        $this->syncDailyHealthStatus($log->fresh(), 'snoozed');

        return $log->fresh(['schedule']);
    }

    public function statusForUser(User $user, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $schedules = MedicationDoseSchedule::query()
            ->where('user_id', $user->id)
            ->where('active', true)
            ->orderBy('schedule_time')
            ->get();

        $logs = $schedules
            ->map(fn (MedicationDoseSchedule $schedule) => $this->ensureLogForSchedule($schedule, $now)?->fresh(['schedule', 'events']))
            ->filter()
            ->values();

        $items = $logs->map(function (MedicationDoseLog $log) use ($now): array {
            $schedule = $log->schedule;
            $overdue = $log->scheduled_for && $log->scheduled_for->lessThan($now) && ! in_array($log->status, self::ACKNOWLEDGED_STATUSES, true);
            $critical = $this->isPastHardDeadline($log, $now) && ! in_array($log->status, self::ACKNOWLEDGED_STATUSES, true);

            return [
                'id' => $log->id,
                'schedule_id' => $schedule?->id,
                'label' => $schedule?->label,
                'dosage_text' => $schedule?->dosage_text,
                'timing_note' => $schedule?->timing_note,
                'medication_items' => $this->medicationItems($schedule),
                'medication_count' => count($this->medicationItems($schedule)),
                'schedule_time' => $schedule?->schedule_time,
                'timezone' => $log->scheduled_timezone,
                'status' => $critical ? 'critical_overdue' : ($overdue && $log->status === 'pending' ? 'overdue' : $log->status),
                'scheduled_for' => $log->scheduled_for?->toDateTimeString(),
                'scheduled_for_local' => $log->scheduled_for?->copy()->setTimezone($log->scheduled_timezone)->format('Y-m-d H:i'),
                'reminder_attempts' => $log->reminder_attempts,
                'last_reminded_at' => $log->last_reminded_at?->toDateTimeString(),
                'next_reminder_at' => $log->next_reminder_at?->toDateTimeString(),
                'acknowledged_at' => $log->acknowledged_at?->toDateTimeString(),
                'acknowledgement_channel' => $log->acknowledgement_channel,
                'last_delivery_channel' => $log->last_delivery_channel,
                'skip_reason' => $log->skip_reason,
                'overdue' => $overdue,
                'critical_overdue' => $critical,
                'history' => $log->events
                    ->sortByDesc('occurred_at')
                    ->take(6)
                    ->map(fn ($event) => [
                        'event_type' => $event->event_type,
                        'channel' => $event->channel,
                        'occurred_at' => $event->occurred_at?->toDateTimeString(),
                    ])
                    ->values(),
            ];
        });

        $pending = $items->whereNotIn('status', self::ACKNOWLEDGED_STATUSES);

        return [
            'items' => $items,
            'routine' => $schedules->map(fn (MedicationDoseSchedule $schedule) => [
                'id' => $schedule->id,
                'dose_key' => $schedule->dose_key,
                'label' => $schedule->label,
                'dosage_text' => $schedule->dosage_text,
                'timing_note' => $schedule->timing_note,
                'schedule_time' => $schedule->schedule_time,
                'timezone' => $schedule->timezone ?: self::DEFAULT_TIMEZONE,
                'frequency' => $schedule->metadata['frequency'] ?? 'daily',
                'weekday' => $schedule->metadata['weekday_name'] ?? null,
                'medication_items' => $this->medicationItems($schedule),
                'medication_count' => count($this->medicationItems($schedule)),
            ])->values(),
            'pending_count' => $pending->count(),
            'taken_count' => $items->where('status', 'taken')->count(),
            'overdue_count' => $items->where('overdue', true)->count(),
            'status_label' => $schedules->isEmpty()
                ? 'No medication routine configured'
                : ($pending->count() > 0 ? 'Medication pending' : 'Medication confirmed'),
        ];
    }

    public function recordSlackButtonClick(MedicationDoseLog $log, string $eventType, array $metadata = []): void
    {
        $this->recordEvent($log->fresh(['schedule']), $eventType, 'slack', metadata: $metadata);
    }

    private function acknowledge(MedicationDoseLog $log, string $status, string $source, string $channel, ?string $reason = null): MedicationDoseLog
    {
        $log->loadMissing('schedule');
        $now = CarbonImmutable::now('UTC');
        $log->update([
            'status' => $status,
            'acknowledged_at' => $now,
            'next_reminder_at' => null,
            'acknowledgement_source' => $source,
            'acknowledgement_channel' => $channel,
            'skip_reason' => $status === 'skipped' ? $reason : null,
        ]);

        $this->recordEvent($log->fresh(['schedule']), $status, $channel, metadata: [
            'source' => $source,
            'has_skip_reason' => filled($reason),
        ]);

        $this->syncDailyHealthStatus($log->fresh(), $status);

        return $log->fresh(['schedule']);
    }

    private function suppressForQuietHours(MedicationDoseLog $log, CarbonImmutable $now): void
    {
        $quietEndsAt = $this->quietHoursEndAt($log->schedule, $now);
        $log->update([
            'status' => 'overdue',
            'next_reminder_at' => $quietEndsAt,
        ]);

        $this->recordEvent($log->fresh(['schedule']), 'reminder_suppressed_quiet_hours', metadata: [
            'next_reminder_at' => $quietEndsAt->toIso8601String(),
        ]);
    }

    private function scheduledFor(MedicationDoseSchedule $schedule, string $doseDate): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $doseDate.' '.($schedule->schedule_time ?: '00:00:00'),
            $schedule->timezone ?: self::DEFAULT_TIMEZONE
        )->utc();
    }

    private function scheduleIsDueOnDate(MedicationDoseSchedule $schedule, CarbonImmutable $localDate): bool
    {
        $metadata = $schedule->metadata ?? [];
        $frequency = $metadata['frequency'] ?? 'daily';

        if ($frequency !== 'weekly') {
            return true;
        }

        return (int) ($metadata['weekday'] ?? 0) === $localDate->dayOfWeekIso;
    }

    private function medicationItems(?MedicationDoseSchedule $schedule): array
    {
        if (! $schedule) {
            return [];
        }

        return collect($schedule->metadata['medication_items'] ?? [])
            ->map(fn (array $item) => array_filter([
                'name' => $item['name'] ?? null,
                'instruction' => $item['instruction'] ?? null,
                'timing' => $item['timing'] ?? null,
            ], fn ($value) => filled($value)))
            ->filter(fn (array $item) => filled($item['name'] ?? null))
            ->values()
            ->all();
    }

    private function isQuietTime(MedicationDoseSchedule $schedule, CarbonImmutable $now): bool
    {
        if (! $schedule->quiet_hours_start || ! $schedule->quiet_hours_end) {
            return false;
        }

        $local = $now->setTimezone($schedule->timezone ?: self::DEFAULT_TIMEZONE);
        $time = $local->format('H:i:s');
        $start = $schedule->quiet_hours_start;
        $end = $schedule->quiet_hours_end;

        if ($start <= $end) {
            return $time >= $start && $time < $end;
        }

        return $time >= $start || $time < $end;
    }

    private function quietHoursEndAt(MedicationDoseSchedule $schedule, CarbonImmutable $now): CarbonImmutable
    {
        $timezone = $schedule->timezone ?: self::DEFAULT_TIMEZONE;
        $local = $now->setTimezone($timezone);
        $end = $schedule->quiet_hours_end ?: '07:00:00';
        $endToday = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $local->toDateString().' '.$end, $timezone);

        if ($local->lessThan($endToday)) {
            return $endToday->utc();
        }

        return $endToday->addDay()->utc();
    }

    private function notificationMessage(MedicationDoseSchedule $schedule): string
    {
        if ($schedule->hide_details_in_notifications) {
            return 'A scheduled medication is due. Open Miriam to confirm Taken, Snooze, or Skip.';
        }

        return trim("{$schedule->label}: {$schedule->dosage_text} {$schedule->timing_note}. Open Miriam to confirm Taken, Snooze, or Skip.");
    }

    private function reminderStatus(MedicationDoseLog $log, CarbonImmutable $now): string
    {
        if ($this->isPastHardDeadline($log, $now)) {
            return 'critical_overdue';
        }

        if ($log->scheduled_for && $this->immutableUtc($log->scheduled_for)->lessThanOrEqualTo($now)) {
            return 'overdue';
        }

        return $log->status;
    }

    private function escalationLevel(MedicationDoseLog $log, CarbonImmutable $now): string
    {
        $deadline = $this->hardDeadlineAt($log);

        if (! $deadline || ! $log->scheduled_for) {
            return 'normal';
        }

        $scheduledFor = $this->immutableUtc($log->scheduled_for);

        if ($now->greaterThanOrEqualTo($deadline)) {
            return 'critical_overdue';
        }

        if ($now->greaterThanOrEqualTo($deadline->subMinutes(5))) {
            return 'final_pre_deadline';
        }

        $minutesAfterSchedule = $scheduledFor->diffInMinutes($now, false);

        if ($minutesAfterSchedule >= 45) {
            return 'urgent';
        }

        if ($minutesAfterSchedule >= 30) {
            return 'stronger';
        }

        if ($minutesAfterSchedule >= 15) {
            return 'reminder';
        }

        return 'normal';
    }

    private function nextReminderAt(MedicationDoseLog $log, CarbonImmutable $now): CarbonImmutable
    {
        $deadline = $this->hardDeadlineAt($log);

        if (! $deadline || ! $log->scheduled_for) {
            return $now->addMinutes(max(5, (int) $log->schedule->repeat_interval_minutes));
        }

        if ($now->greaterThanOrEqualTo($deadline)) {
            return $now->addMinutes(self::CRITICAL_REPEAT_MINUTES);
        }

        $scheduledFor = $this->immutableUtc($log->scheduled_for);
        $candidates = collect([
            $scheduledFor->addMinutes(15),
            $scheduledFor->addMinutes(30),
            $scheduledFor->addMinutes(45),
            $deadline->subMinutes(5),
            $deadline,
        ])
            ->filter(fn (CarbonImmutable $candidate) => $candidate->greaterThan($now))
            ->sortBy(fn (CarbonImmutable $candidate) => $candidate->getTimestamp())
            ->values();

        return $candidates->first() ?: $deadline;
    }

    private function snoozeUntil(MedicationDoseLog $log, CarbonImmutable $now, int $minutes): CarbonImmutable
    {
        $requested = $now->addMinutes($minutes);
        $deadline = $this->hardDeadlineAt($log);

        if (! $deadline) {
            return $requested;
        }

        if ($now->greaterThanOrEqualTo($deadline)) {
            return $now->addMinutes(self::CRITICAL_REPEAT_MINUTES);
        }

        return $requested->lessThanOrEqualTo($deadline) ? $requested : $deadline;
    }

    private function isPastHardDeadline(MedicationDoseLog $log, CarbonImmutable $now): bool
    {
        $deadline = $this->hardDeadlineAt($log);

        return $deadline && $now->greaterThanOrEqualTo($deadline);
    }

    private function hardDeadlineAt(MedicationDoseLog $log): ?CarbonImmutable
    {
        $log->loadMissing('schedule');
        $schedule = $log->schedule;

        if (! $schedule) {
            return null;
        }

        $deadlineTime = $this->hardDeadlineTime($schedule);

        if (! $deadlineTime) {
            return null;
        }

        $timezone = $schedule->timezone ?: self::DEFAULT_TIMEZONE;
        $doseDate = $log->dose_date?->toDateString()
            ?: $this->immutableUtc($log->scheduled_for ?? CarbonImmutable::now('UTC'))->setTimezone($timezone)->toDateString();

        return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $doseDate.' '.$deadlineTime, $timezone)->utc();
    }

    private function hardDeadlineTime(MedicationDoseSchedule $schedule): ?string
    {
        if ($schedule->hard_deadline_time) {
            return strlen((string) $schedule->hard_deadline_time) === 5
                ? $schedule->hard_deadline_time.':00'
                : (string) $schedule->hard_deadline_time;
        }

        if ($schedule->dose_key === 'morning') {
            return self::MORNING_DEADLINE_TIME;
        }

        return null;
    }

    private function immutableUtc(mixed $value): CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value->utc();
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        return CarbonImmutable::parse($value)->utc();
    }

    private function sendSlackReminder(MedicationDoseLog $log, int $attempt, string $escalationLevel): void
    {
        $botToken = config('services.slack.bot_token');
        $miriamChannel = config('services.slack.miriam_channel_id') ?: env('SLACK_MIRIAM_CHANNEL_ID');
        $webhookUrl = config('services.slack.webhook_url');
        $canUseMiriamBot = filled($botToken) && filled($miriamChannel);

        if (! $canUseMiriamBot && ! filled($webhookUrl)) {
            $this->recordEvent($log, 'slack_reminder_skipped', 'slack', metadata: [
                'attempt' => $attempt,
                'escalation_level' => $escalationLevel,
                'reason' => 'slack_missing',
            ]);

            return;
        }

        try {
            $payload = $this->slackReminderPayload($log, $escalationLevel);
            $response = $canUseMiriamBot
                ? Http::withToken($botToken)
                    ->acceptJson()
                    ->timeout(5)
                    ->post('https://slack.com/api/chat.postMessage', $payload + ['channel' => $miriamChannel])
                : Http::asJson()
                    ->timeout(5)
                    ->post($webhookUrl, $payload);

            $slackOk = $canUseMiriamBot
                ? (bool) ($response->json('ok') ?? false)
                : $response->successful();

            if ($slackOk) {
                $this->recordEvent($log, 'slack_reminder_sent', 'slack', metadata: [
                    'attempt' => $attempt,
                    'escalation_level' => $escalationLevel,
                    'status' => $response->status(),
                    'channel' => $canUseMiriamBot ? 'miriam' : 'webhook',
                ]);

                return;
            }

            $this->recordEvent($log, 'slack_reminder_failed', 'slack', metadata: [
                'attempt' => $attempt,
                'escalation_level' => $escalationLevel,
                'reason' => 'http_error',
                'status' => $response->status(),
                'slack_error' => $response->json('error') ?? null,
            ]);
        } catch (Throwable $exception) {
            $this->recordEvent($log, 'slack_reminder_failed', 'slack', metadata: [
                'attempt' => $attempt,
                'escalation_level' => $escalationLevel,
                'reason' => 'exception',
                'exception' => class_basename($exception),
            ]);
        }
    }

    private function syncCalendarReminder(MedicationDoseLog $log): void
    {
        $result = $this->calendarSyncService->syncMedicationReminder($log);
        $status = $result['status'] ?? 'skipped';

        $this->recordEvent($log, match ($status) {
            'created' => 'calendar_event_created',
            'updated' => 'calendar_event_updated',
            'failed' => 'calendar_event_failed',
            default => 'calendar_event_skipped',
        }, 'google_calendar', metadata: array_filter([
            'provider_event_id' => $result['provider_event_id'] ?? null,
            'reason' => $result['reason'] ?? null,
            'exception' => $result['exception'] ?? null,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    private function slackReminderMessage(MedicationDoseSchedule $schedule, string $escalationLevel): string
    {
        if ($schedule->hide_details_in_notifications) {
            return match ($escalationLevel) {
                'critical_overdue' => 'Critical Miriam medication reminder: this is overdue. Please confirm Taken or Skip.',
                'urgent', 'final_pre_deadline' => 'Urgent Miriam medication reminder: this is still pending. Please confirm before 10:00.',
                default => 'Medication reminder: scheduled medication is due.',
            };
        }

        return match ($escalationLevel) {
            'critical_overdue' => trim("Critical Miriam medication reminder: {$schedule->label} is overdue. {$schedule->dosage_text} {$schedule->timing_note}. Please confirm Taken or Skip."),
            'urgent', 'final_pre_deadline' => trim("Urgent Miriam medication reminder: {$schedule->label} is still pending. {$schedule->dosage_text} {$schedule->timing_note}. Please confirm before 10:00."),
            default => trim("Miriam medication reminder: {$schedule->label}: {$schedule->dosage_text} {$schedule->timing_note}. Please confirm Taken, Snooze, or Skip."),
        };
    }

    private function slackReminderPayload(MedicationDoseLog $log, string $escalationLevel): array
    {
        $message = $this->slackReminderMessage($log->schedule, $escalationLevel);

        return [
            'text' => $message,
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => $message,
                    ],
                ],
                [
                    'type' => 'actions',
                    'elements' => [
                        [
                            'type' => 'button',
                            'text' => [
                                'type' => 'plain_text',
                                'text' => 'Taken',
                            ],
                            'style' => 'primary',
                            'action_id' => 'medication_taken',
                            'value' => (string) $log->id,
                        ],
                        [
                            'type' => 'button',
                            'text' => [
                                'type' => 'plain_text',
                                'text' => 'Snooze 15 min',
                            ],
                            'action_id' => 'medication_snooze_15',
                            'value' => (string) $log->id,
                        ],
                        [
                            'type' => 'button',
                            'text' => [
                                'type' => 'plain_text',
                                'text' => 'Skip',
                            ],
                            'style' => 'danger',
                            'action_id' => 'medication_skip',
                            'value' => (string) $log->id,
                            'url' => route('health.index'),
                        ],
                    ],
                ],
            ],
        ];
    }

    private function recordEvent(MedicationDoseLog $log, string $type, ?string $channel = null, ?string $device = null, array $metadata = []): void
    {
        MedicationReminderEvent::create([
            'dose_log_id' => $log->id,
            'dose_schedule_id' => $log->dose_schedule_id,
            'user_id' => $log->user_id,
            'workspace_id' => $log->workspace_id,
            'event_type' => $type,
            'channel' => $channel,
            'device' => $device,
            'occurred_at' => CarbonImmutable::now('UTC'),
            'metadata' => $metadata,
        ]);
    }

    private function syncDailyHealthStatus(MedicationDoseLog $log, string $status): void
    {
        DailyHealthLog::query()
            ->where('user_id', $log->user_id)
            ->whereDate('log_date', CarbonImmutable::now(self::DEFAULT_TIMEZONE)->toDateString())
            ->latest()
            ->first()
            ?->update(['medication_status' => $status]);
    }
}
