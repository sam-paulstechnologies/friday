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
    public const ACKNOWLEDGED_STATUSES = ['taken', 'skipped', 'not_responded', 'missed', 'superseded', 'stale'];
    public const DUE_REMINDER_STATUSES = ['pending', 'overdue', 'snoozed'];
    private const MORNING_DEADLINE_TIME = '10:00:00';
    private const MORNING_FINAL_CUTOFF_TIME = '12:00:00';
    private const EVENING_FINAL_CUTOFF_TIME = '23:30:00';
    private const DEFAULT_RESPONSE_WINDOW_MINUTES = 120;
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

        $logs = $this->doseLogsForIdentity($schedule->id, $schedule->user_id, $schedule->workspace_id, $doseDate)
            ->with('schedule')
            ->get();

        if ($logs->isNotEmpty()) {
            $log = $this->canonicalLogFrom($logs);
            $this->closeDuplicateActiveLogsForCanonical($log, $now, 'duplicate_dose_log_superseded');

            return $log->fresh(['schedule']);
        }

        if ($this->finalResponseCutoffForSchedule($schedule, $doseDate)->lessThanOrEqualTo($now)) {
            return null;
        }

        $log = MedicationDoseLog::query()
            ->where('dose_schedule_id', $schedule->id)
            ->where('user_id', $schedule->user_id)
            ->when(
                $schedule->workspace_id === null,
                fn ($query) => $query->whereNull('workspace_id'),
                fn ($query) => $query->where('workspace_id', $schedule->workspace_id),
            )
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
        $now = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $nowUtc = $now->toDateTimeString();

        $this->ensureLogsForActiveSchedules($now);
        $staleCleanup = $this->closeStaleOverdueLogs($now);

        $logs = MedicationDoseLog::query()
            ->with(['schedule', 'user'])
            ->whereIn('status', self::DUE_REMINDER_STATUSES)
            ->whereNull('acknowledged_at')
            ->whereNotNull('next_reminder_at')
            ->where('next_reminder_at', '<=', $nowUtc)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();
        $dueCandidateCount = $logs->count();
        $logs = $this->deduplicateDueLogs($logs, $now);

        $queued = 0;
        $suppressed = 0;
        $skipped = [];

        foreach ($logs as $log) {
            if (! $log->schedule || ! $log->user) {
                $skipped[] = [
                    'dose_log_id' => $log->id,
                    'status' => $log->status,
                    'next_reminder_at' => $log->next_reminder_at?->utc()?->toDateTimeString(),
                    'reason' => ! $log->schedule ? 'missing schedule' : 'missing user',
                ];

                continue;
            }

            if ($this->closeIfFinalResponseCutoffPassed($log, $now)) {
                $skipped[] = [
                    'dose_log_id' => $log->id,
                    'status' => 'not_responded',
                    'next_reminder_at' => null,
                    'reason' => 'final response cutoff passed',
                ];

                continue;
            }

            if ($this->closeIfStaleOverdue($log, $now)) {
                $skipped[] = [
                    'dose_log_id' => $log->id,
                    'status' => 'skipped',
                    'next_reminder_at' => null,
                    'reason' => 'stale overdue closed',
                ];

                continue;
            }

            if ($this->isQuietTime($log->schedule, $now, $log)) {
                if ($this->suppressForQuietHours($log, $now)) {
                    $suppressed++;
                }
                $skipped[] = [
                    'dose_log_id' => $log->id,
                    'status' => $log->status,
                    'next_reminder_at' => $log->next_reminder_at?->utc()?->toDateTimeString(),
                    'reason' => $log->fresh()->next_reminder_at ? 'quiet hours' : 'stale overdue closed',
                ];
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

        return [
            'queued' => $queued,
            'quiet_hours_suppressed' => $suppressed,
            'due_candidate_count' => $dueCandidateCount,
            'current_utc' => $nowUtc,
            'skipped' => $skipped,
            'stale_overdue_closed' => $staleCleanup['closed'],
        ];
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

            $latest = $this->latestDoseLogForIdentity($log);
            if ($latest && $latest->id !== $log->id) {
                if ($this->isActiveReminderLog($log)) {
                    $this->closeSupersededDoseLog($log, $now, 'queued_job_latest_log_recheck', $latest);
                }
                $this->recordEvent($log->fresh(['schedule']), 'duplicate_prevented', $channel, metadata: [
                    'reason' => 'newer_dose_log_exists',
                    'latest_dose_log_id' => $latest->id,
                    'latest_status' => $latest->status,
                ]);

                return $log->fresh(['schedule']);
            }

            if (! $this->isActiveReminderLog($log)) {
                $this->recordEvent($log, 'duplicate_prevented', $channel);

                return $log;
            }

            if ($log->next_reminder_at && $log->next_reminder_at->greaterThan($now)) {
                $this->recordEvent($log, 'duplicate_prevented', $channel);

                return $log;
            }

            if ($this->closeIfFinalResponseCutoffPassed($log, $now)) {
                return $log->refresh();
            }

            if ($this->closeIfStaleOverdue($log, $now)) {
                return $log->refresh();
            }

            if ($this->isQuietTime($log->schedule, $now, $log)) {
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
        return $this->acknowledge($log, 'skipped', $source, $channel, $reason);
    }

    public function snooze(MedicationDoseLog $log, int $minutes = 30, string $source = 'web', string $channel = 'web'): MedicationDoseLog
    {
        $log = MedicationDoseLog::query()
            ->with('schedule')
            ->whereKey($log->id)
            ->firstOrFail();

        $now = CarbonImmutable::now('UTC');
        if (! $this->isActiveReminderLog($log) || $this->closeIfFinalResponseCutoffPassed($log, $now)) {
            $fresh = $log->fresh(['schedule']);
            $this->recordEvent($fresh, 'snooze_ignored', $channel, metadata: [
                'source' => $source,
                'status' => $fresh->status,
                'reason' => 'dose_not_active',
            ]);

            return $fresh;
        }

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
            $active = $this->isActiveReminderLog($log);
            $overdue = $log->scheduled_for && $log->scheduled_for->lessThan($now) && $active;
            $critical = $this->isPastHardDeadline($log, $now) && $active;

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
                'status' => $overdue && $log->status === 'pending' ? 'overdue' : $log->status,
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

    public function closeStaleOverdueLogs(?CarbonImmutable $now = null, bool $dryRun = false, ?int $scheduleId = null): array
    {
        $now = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $query = MedicationDoseLog::query()
            ->with('schedule')
            ->whereIn('status', self::DUE_REMINDER_STATUSES)
            ->whereNull('acknowledged_at');

        if ($scheduleId !== null) {
            $query->where('dose_schedule_id', $scheduleId);
        }

        $inspected = 0;
        $closed = 0;
        $skipped = 0;
        $closedIds = [];

        $query->orderBy('id')->chunkById(100, function (Collection $logs) use ($now, $dryRun, &$inspected, &$closed, &$skipped, &$closedIds): void {
            foreach ($logs as $log) {
                $inspected++;

                if (! $this->finalResponseCutoffHasPassed($log, $now)) {
                    $skipped++;

                    continue;
                }

                if (! $dryRun) {
                    $this->closeUnrespondedDoseLog($log, $now, 'final_response_window_closed');
                }

                $closed++;
                $closedIds[] = $log->id;
            }
        });

        return [
            'inspected' => $inspected,
            'closed' => $closed,
            'skipped' => $skipped,
            'closed_ids' => $closedIds,
            'dry_run' => $dryRun,
        ];
    }

    public function closeOlderStaleLogsForSchedule(MedicationDoseLog $newerLog, ?CarbonImmutable $now = null, string $source = 'stale_overdue_cleanup'): array
    {
        $now = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $newerLog->loadMissing('schedule');

        if (! $newerLog->schedule) {
            return ['inspected' => 0, 'closed' => 0, 'skipped' => 0, 'closed_ids' => []];
        }

        $logs = MedicationDoseLog::query()
            ->with('schedule')
            ->where('dose_schedule_id', $newerLog->dose_schedule_id)
            ->where('user_id', $newerLog->user_id)
            ->when(
                $newerLog->workspace_id === null,
                fn ($query) => $query->whereNull('workspace_id'),
                fn ($query) => $query->where('workspace_id', $newerLog->workspace_id),
            )
            ->whereKeyNot($newerLog->id)
            ->whereIn('status', self::DUE_REMINDER_STATUSES)
            ->whereNull('acknowledged_at')
            ->whereDate('dose_date', '<=', $newerLog->dose_date)
            ->get();

        $closedIds = [];

        foreach ($logs as $log) {
            $this->closeSupersededDoseLog($log, $now, $source, $newerLog);
            $closedIds[] = $log->id;
        }

        return [
            'inspected' => $logs->count(),
            'closed' => count($closedIds),
            'skipped' => $logs->count() - count($closedIds),
            'closed_ids' => $closedIds,
        ];
    }

    public function repairDuplicateActiveDoseLogs(?CarbonImmutable $now = null, bool $dryRun = false): array
    {
        $now = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $logs = MedicationDoseLog::query()
            ->with('schedule')
            ->whereIn('status', self::DUE_REMINDER_STATUSES)
            ->whereNull('acknowledged_at')
            ->orderBy('dose_schedule_id')
            ->orderBy('user_id')
            ->orderBy('workspace_id')
            ->orderBy('dose_date')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        $inspected = $logs->count();
        $closed = 0;
        $skipped = 0;
        $closedIds = [];

        $logs->groupBy(fn (MedicationDoseLog $log): string => $this->identityKey($log))
            ->each(function (Collection $group) use ($now, $dryRun, &$closed, &$skipped, &$closedIds): void {
                if ($group->count() < 2) {
                    $skipped += $group->count();

                    return;
                }

                $canonical = $this->canonicalLogFrom($group);

                if ($this->finalResponseCutoffHasPassed($canonical, $now)) {
                    foreach ($group as $log) {
                        if (! $dryRun) {
                            $this->closeUnrespondedDoseLog($log, $now, 'duplicate_cleanup_final_cutoff');
                        }
                        $closed++;
                        $closedIds[] = $log->id;
                    }

                    return;
                }

                foreach ($group as $log) {
                    if ($log->id === $canonical->id) {
                        $skipped++;

                        continue;
                    }

                    if (! $dryRun) {
                        $this->closeSupersededDoseLog($log, $now, 'duplicate_cleanup', $canonical);
                    }
                    $closed++;
                    $closedIds[] = $log->id;
                }

                $canonical->refresh();
            });

        return [
            'inspected' => $inspected,
            'closed' => $closed,
            'skipped' => $skipped,
            'closed_ids' => $closedIds,
            'dry_run' => $dryRun,
        ];
    }

    private function acknowledge(MedicationDoseLog $log, string $status, string $source, string $channel, ?string $reason = null): MedicationDoseLog
    {
        return DB::transaction(function () use ($log, $status, $source, $channel, $reason): MedicationDoseLog {
            $locked = MedicationDoseLog::query()
                ->with('schedule')
                ->whereKey($log->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->isActiveReminderLog($locked)) {
                return $locked->fresh(['schedule']);
            }

            $now = CarbonImmutable::now('UTC');
            $locked->update([
                'status' => $status,
                'acknowledged_at' => $now,
                'next_reminder_at' => null,
                'acknowledgement_source' => $source,
                'acknowledgement_channel' => $channel,
                'skip_reason' => $status === 'skipped' ? $reason : null,
            ]);

            $fresh = $locked->fresh(['schedule']);
            $this->recordEvent($fresh, $status, $channel, metadata: [
                'source' => $source,
                'has_skip_reason' => filled($reason),
            ]);

            $this->closeDuplicateActiveLogsForCanonical($fresh, $now, $status.'_duplicate_closure');
            $this->closeOlderStaleLogsForSchedule($fresh, $now, $status.'_stale_closure');
            $this->syncDailyHealthStatus($fresh, $status);

            return $fresh->fresh(['schedule']);
        });
    }

    private function suppressForQuietHours(MedicationDoseLog $log, CarbonImmutable $now): bool
    {
        if ($this->closeIfFinalResponseCutoffPassed($log, $now)) {
            return false;
        }

        if ($this->closeIfStaleOverdue($log, $now)) {
            return false;
        }

        $quietEndsAt = $this->quietHoursEndAt($log->schedule, $now);
        $log->update([
            'status' => 'overdue',
            'next_reminder_at' => $quietEndsAt,
        ]);

        $this->recordEvent($log->fresh(['schedule']), 'reminder_suppressed_quiet_hours', metadata: [
            'next_reminder_at' => $quietEndsAt->toIso8601String(),
        ]);

        return true;
    }

    private function closeIfStaleOverdue(MedicationDoseLog $log, CarbonImmutable $now): bool
    {
        $latest = $this->latestDoseLogForIdentity($log);

        if (! $latest || $latest->id === $log->id) {
            return false;
        }

        $this->closeSupersededDoseLog($log, $now, 'stale_duplicate_cleanup', $latest);

        return true;
    }

    private function closeIfFinalResponseCutoffPassed(MedicationDoseLog $log, CarbonImmutable $now): bool
    {
        if (! $this->finalResponseCutoffHasPassed($log, $now)) {
            return false;
        }

        $this->closeUnrespondedDoseLog($log, $now, 'final_response_window_closed');

        return true;
    }

    private function finalResponseCutoffHasPassed(MedicationDoseLog $log, CarbonImmutable $now): bool
    {
        $cutoff = $this->finalResponseCutoffAt($log);

        return $cutoff->lessThanOrEqualTo($now);
    }

    private function closeSupersededDoseLog(MedicationDoseLog $log, CarbonImmutable $now, string $source, ?MedicationDoseLog $canonicalLog = null): MedicationDoseLog
    {
        if (! $this->isActiveReminderLog($log)) {
            return $log->fresh(['schedule']);
        }

        $metadata = array_merge($log->metadata ?? [], [
            'superseded_closed' => true,
            'superseded_source' => $source,
            'closed_because' => 'duplicate_active_dose_log',
            'canonical_dose_log_id' => $canonicalLog?->id,
        ]);

        $log->update([
            'status' => 'superseded',
            'acknowledgement_source' => $source,
            'acknowledgement_channel' => 'system',
            'next_reminder_at' => null,
            'metadata' => $metadata,
        ]);

        $fresh = $log->fresh(['schedule']);
        $this->recordEvent($fresh, 'duplicate_dose_log_superseded', 'system', metadata: [
            'source' => $source,
            'canonical_dose_log_id' => $canonicalLog?->id,
            'dose_date' => $fresh->dose_date?->toDateString(),
        ]);

        return $fresh;
    }

    private function closeUnrespondedDoseLog(MedicationDoseLog $log, CarbonImmutable $now, string $source): MedicationDoseLog
    {
        if (! $this->isActiveReminderLog($log)) {
            return $log->fresh(['schedule']);
        }

        $metadata = array_merge($log->metadata ?? [], [
            'final_response_window_closed' => true,
            'final_response_window_source' => $source,
            'final_response_cutoff_at' => $this->finalResponseCutoffAt($log)->toIso8601String(),
        ]);

        $log->update([
            'status' => 'not_responded',
            'acknowledgement_source' => $source,
            'acknowledgement_channel' => 'system',
            'next_reminder_at' => null,
            'metadata' => $metadata,
        ]);

        $fresh = $log->fresh(['schedule']);
        $this->recordEvent($fresh, 'dose_marked_not_responded', 'system', metadata: [
            'source' => $source,
            'dose_date' => $fresh->dose_date?->toDateString(),
            'final_response_cutoff_at' => $this->finalResponseCutoffAt($fresh)->toIso8601String(),
        ]);

        return $fresh;
    }

    private function deduplicateDueLogs(Collection $logs, CarbonImmutable $now): Collection
    {
        return $logs
            ->groupBy(fn (MedicationDoseLog $log): string => $this->identityKey($log))
            ->map(function (Collection $group) use ($now): MedicationDoseLog {
                $canonical = $this->canonicalLogFrom($group);
                $this->closeDuplicateActiveLogsForCanonical($canonical, $now, 'due_query_duplicate_superseded');

                return $canonical->fresh(['schedule', 'user']);
            })
            ->filter(fn (MedicationDoseLog $log): bool => $this->isActiveReminderLog($log))
            ->values();
    }

    private function closeDuplicateActiveLogsForCanonical(MedicationDoseLog $canonicalLog, CarbonImmutable $now, string $source): array
    {
        $canonicalLog->loadMissing('schedule');

        if (! $canonicalLog->dose_date) {
            return ['closed' => 0, 'closed_ids' => []];
        }

        $duplicates = $this->doseLogsForIdentity(
            $canonicalLog->dose_schedule_id,
            $canonicalLog->user_id,
            $canonicalLog->workspace_id,
            $canonicalLog->dose_date->toDateString()
        )
            ->with('schedule')
            ->whereKeyNot($canonicalLog->id)
            ->whereIn('status', self::DUE_REMINDER_STATUSES)
            ->whereNull('acknowledged_at')
            ->get();

        $closedIds = [];

        foreach ($duplicates as $duplicate) {
            $this->closeSupersededDoseLog($duplicate, $now, $source, $canonicalLog);
            $closedIds[] = $duplicate->id;
        }

        return ['closed' => count($closedIds), 'closed_ids' => $closedIds];
    }

    private function canonicalLogFrom(Collection $logs): MedicationDoseLog
    {
        $active = $logs->filter(fn (MedicationDoseLog $log): bool => $this->isActiveReminderLog($log));
        $pool = $active->isNotEmpty() ? $active : $logs;

        return $pool
            ->sortByDesc(fn (MedicationDoseLog $log): string => sprintf(
                '%s-%010d',
                $log->updated_at?->format('YmdHis.u') ?? '00000000000000.000000',
                $log->id
            ))
            ->first();
    }

    private function latestDoseLogForIdentity(MedicationDoseLog $log): ?MedicationDoseLog
    {
        if (! $log->dose_date) {
            return null;
        }

        return $this->doseLogsForIdentity(
            $log->dose_schedule_id,
            $log->user_id,
            $log->workspace_id,
            $log->dose_date->toDateString()
        )
            ->with('schedule')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
    }

    private function doseLogsForIdentity(int $scheduleId, int $userId, ?int $workspaceId, string $doseDate)
    {
        return MedicationDoseLog::query()
            ->where('dose_schedule_id', $scheduleId)
            ->where('user_id', $userId)
            ->when(
                $workspaceId === null,
                fn ($query) => $query->whereNull('workspace_id'),
                fn ($query) => $query->where('workspace_id', $workspaceId),
            )
            ->whereDate('dose_date', $doseDate);
    }

    private function identityKey(MedicationDoseLog $log): string
    {
        return implode('|', [
            $log->dose_schedule_id,
            $log->user_id,
            $log->workspace_id ?? 'null',
            $log->dose_date?->toDateString() ?? 'none',
        ]);
    }

    private function isActiveReminderLog(MedicationDoseLog $log): bool
    {
        return $log->acknowledged_at === null
            && in_array($log->status, self::DUE_REMINDER_STATUSES, true);
    }

    private function finalResponseCutoffAt(MedicationDoseLog $log): CarbonImmutable
    {
        $log->loadMissing('schedule');

        if (! $log->schedule) {
            $scheduledFor = $this->immutableUtc($log->scheduled_for ?? CarbonImmutable::now('UTC'));

            return $scheduledFor->addMinutes(self::DEFAULT_RESPONSE_WINDOW_MINUTES);
        }

        $timezone = $log->schedule->timezone ?: $log->scheduled_timezone ?: self::DEFAULT_TIMEZONE;
        $doseDate = $log->dose_date?->toDateString()
            ?: $this->immutableUtc($log->scheduled_for ?? CarbonImmutable::now('UTC'))->setTimezone($timezone)->toDateString();

        return $this->finalResponseCutoffForSchedule($log->schedule, $doseDate);
    }

    private function finalResponseCutoffForSchedule(MedicationDoseSchedule $schedule, string $doseDate): CarbonImmutable
    {
        $timezone = $schedule->timezone ?: self::DEFAULT_TIMEZONE;
        $configured = $schedule->metadata['final_response_cutoff_time'] ?? null;
        $cutoffTime = filled($configured) ? $this->normalizeTime($configured) : match ($schedule->dose_key) {
            'morning' => self::MORNING_FINAL_CUTOFF_TIME,
            'evening' => self::EVENING_FINAL_CUTOFF_TIME,
            default => null,
        };

        if ($cutoffTime) {
            return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $doseDate.' '.$cutoffTime, $timezone)->utc();
        }

        return $this->scheduledFor($schedule, $doseDate)
            ->setTimezone($timezone)
            ->addMinutes(self::DEFAULT_RESPONSE_WINDOW_MINUTES)
            ->utc();
    }

    private function normalizeTime(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : $time;
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

    private function isQuietTime(MedicationDoseSchedule $schedule, CarbonImmutable $now, ?MedicationDoseLog $log = null): bool
    {
        if (! $schedule->quiet_hours_start || ! $schedule->quiet_hours_end) {
            return false;
        }

        if ($log && $schedule->dose_key === 'evening' && $now->lessThan($this->finalResponseCutoffAt($log))) {
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
            return 'overdue';
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

    private function nextReminderAt(MedicationDoseLog $log, CarbonImmutable $now): ?CarbonImmutable
    {
        $deadline = $this->hardDeadlineAt($log);
        $finalCutoff = $this->finalResponseCutoffAt($log);

        if ($now->greaterThanOrEqualTo($finalCutoff)) {
            return null;
        }

        if (! $deadline || ! $log->scheduled_for) {
            return $this->clampToFinalCutoff(
                $now->addMinutes(max(5, (int) $log->schedule->repeat_interval_minutes)),
                $finalCutoff
            );
        }

        if ($now->greaterThanOrEqualTo($deadline)) {
            return $this->clampToFinalCutoff($now->addMinutes(self::CRITICAL_REPEAT_MINUTES), $finalCutoff);
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

        return $this->clampToFinalCutoff($candidates->first() ?: $deadline, $finalCutoff);
    }

    private function snoozeUntil(MedicationDoseLog $log, CarbonImmutable $now, int $minutes): CarbonImmutable
    {
        $requested = $now->addMinutes($minutes);
        $finalCutoff = $this->finalResponseCutoffAt($log);

        return $requested->lessThanOrEqualTo($finalCutoff) ? $requested : $finalCutoff;
    }

    private function clampToFinalCutoff(CarbonImmutable $candidate, CarbonImmutable $finalCutoff): CarbonImmutable
    {
        return $candidate->lessThanOrEqualTo($finalCutoff) ? $candidate : $finalCutoff;
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
        $eventType = match ($status) {
            'created' => 'calendar_event_created',
            'updated' => 'calendar_event_updated',
            'failed' => 'calendar_event_failed',
            default => 'calendar_event_skipped',
        };
        $reason = $result['reason'] ?? null;

        if (in_array($eventType, ['calendar_event_failed', 'calendar_event_skipped'], true)
            && $this->calendarEventAlreadyRecorded($log, $eventType, $reason)) {
            return;
        }

        $this->recordEvent($log, $eventType, 'google_calendar', metadata: array_filter([
            'provider_event_id' => $result['provider_event_id'] ?? null,
            'reason' => $reason,
            'exception' => $result['exception'] ?? null,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    private function calendarEventAlreadyRecorded(MedicationDoseLog $log, string $eventType, ?string $reason): bool
    {
        return $log->events()
            ->where('event_type', $eventType)
            ->get()
            ->contains(fn (MedicationReminderEvent $event): bool => ($event->metadata['reason'] ?? null) === $reason);
    }

    private function slackReminderMessage(MedicationDoseSchedule $schedule, string $escalationLevel): string
    {
        if ($escalationLevel === 'overdue') {
            return $schedule->hide_details_in_notifications
                ? 'Medication is overdue. Please mark Taken or Skip.'
                : trim("Medication is overdue. Please mark Taken or Skip. {$schedule->label}: {$schedule->dosage_text} {$schedule->timing_note}.");
        }

        if ($schedule->hide_details_in_notifications) {
            return 'Please take your medication.';
        }

        return trim("Please take your medication. {$schedule->label}: {$schedule->dosage_text} {$schedule->timing_note}.");
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
