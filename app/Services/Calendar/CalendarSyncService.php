<?php

namespace App\Services\Calendar;

use App\Models\CalendarConnection;
use App\Models\CalendarEventMapping;
use App\Models\CalendarSyncLog;
use App\Models\MedicationDoseLog;
use App\Models\MedicationReminderEvent;
use App\Models\MiriamReminder;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CalendarSyncService
{
    private ?string $lastErrorCode = null;

    private int $retryAfterSeconds = 0;

    public function __construct(private readonly GoogleCalendarService $googleCalendarService, private readonly CalendarConnectionHealthService $health) {}

    public function syncConnection(CalendarConnection $connection): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'failed' => 0, 'skipped' => 0, 'attempted' => 0, 'succeeded' => 0,
            'outcome' => 'skipped', 'error_code' => null,
            'authentication' => ['attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0],
            'push' => ['attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0],
            'pull' => ['attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0]];
        if (! $this->googleCalendarService->configured() || ! $connection->is_active) {
            $counts['skipped'] = 1;
            $counts['error_code'] = 'not_configured_or_connected';
            $this->log($connection, 'manual', 'skipped', 'Calendar sync was not attempted.', $counts);

            return $counts;
        }
        $blocked = $this->health->blocked($connection);
        if ($blocked) {
            $counts['outcome'] = $blocked === 'auth_required' ? 'auth_required' : 'skipped';
            $counts['error_code'] = $blocked;
            $counts['skipped'] = 1;

            return $counts;
        }
        $lock = Cache::lock('jarvis:calendar:connection:'.$connection->id, 1800);
        if (! $lock->get()) {
            $counts['skipped'] = 1;
            $counts['error_code'] = 'sync_in_progress';

            return $counts;
        }
        $this->googleCalendarService->beginRun();
        $this->lastErrorCode = null;
        $this->retryAfterSeconds = 0;
        $stage = 'authentication';
        $authFailure = false;
        $started = microtime(true);
        $eligibleCount = 0;
        try {
            $eligibleCount = $this->eligibleTaskQuery($connection)->count();
            $counts[$stage]['attempted']++;
            $this->googleCalendarService->refreshIfNeeded($connection);
            $counts[$stage]['succeeded']++;
            $tasks = $this->eligibleTasks($connection);
            $failures = 0;
            foreach ($tasks as $index => $task) {
                if (! $this->health->credentialsMatch($connection)) {
                    $this->lastErrorCode = 'connection_changed';
                    break;
                }
                if ($index >= 100 || microtime(true) - $started > 240 || $failures >= 3 || $this->retryAfterSeconds > 0) {
                    $counts['push']['skipped'] += $tasks->count() - $index;
                    $this->lastErrorCode ??= 'run_budget_exhausted';
                    break;
                }
                $stage = 'push';
                $counts[$stage]['attempted']++;
                $result = $this->syncTask($connection, $task);
                if ($result === 'failed') {
                    $counts[$stage]['failed']++;
                    $failures++;
                } elseif ($result === 'skipped') {
                    $counts[$stage]['skipped']++;
                } else {
                    $counts[$stage]['succeeded']++;
                    $counts[$result]++;
                    $failures = 0;
                }
            }
            if (microtime(true) - $started <= 240 && $this->retryAfterSeconds === 0 && $this->health->credentialsMatch($connection)) {
                $stage = 'pull';
                $counts[$stage]['attempted']++;
                $result = $this->pullExternalEvents($connection);
                $counts[$stage][$result === 'success' ? 'succeeded' : 'failed']++;
            } else {
                $counts['pull']['skipped']++;
                $this->lastErrorCode ??= 'run_budget_exhausted';
            }
        } catch (CalendarProviderException $error) {
            $counts[$stage]['failed']++;
            $this->lastErrorCode = $error->errorCode;
            $this->retryAfterSeconds = max($this->retryAfterSeconds, $error->retryAfterSeconds);
            $authFailure = $error->authRequired;
        } catch (Throwable) {
            $counts[$stage]['failed']++;
            $this->lastErrorCode = 'sync_failed';
        } finally {
            try {
                $counts['push']['skipped'] += max(0, $eligibleCount - $counts['push']['attempted'] - $counts['push']['skipped']);
                if ($counts['pull']['attempted'] === 0) {
                    $counts['pull']['skipped'] = 1;
                }
                foreach (['attempted', 'succeeded', 'failed', 'skipped'] as $key) {
                    $counts[$key] = $counts['authentication'][$key] + $counts['push'][$key] + $counts['pull'][$key];
                }
                $dataSucceeded = $counts['push']['succeeded'] + $counts['pull']['succeeded'];
                $counts['outcome'] = $authFailure ? 'auth_required'
                    : ($counts['failed'] > 0 || $counts['skipped'] > 0 ? ($dataSucceeded > 0 ? 'partial' : 'failed')
                        : ($dataSucceeded > 0 ? 'success' : 'skipped'));
                $counts['error_code'] = $this->lastErrorCode;
                if (! $this->health->credentialsMatch($connection)) {
                    $counts['outcome'] = $dataSucceeded > 0 ? 'partial' : 'skipped';
                    $counts['error_code'] = 'connection_changed';
                    $current = $connection->fresh();
                    if ($current?->is_active && $this->health->state($current)['sync_state'] !== 'auth_required') {
                        $this->health->record($current, $counts['outcome'], 'connection_changed');
                    }
                    if ($current) {
                        $this->log($current, 'manual', $counts['outcome'], 'Connection changed during sync.', $counts);
                    }
                } else {
                    $this->health->record($connection, $counts['outcome'], $this->lastErrorCode, $this->retryAfterSeconds);
                    $this->log($connection, 'manual', $counts['outcome'], 'Calendar sync outcome: '.$counts['outcome'].'.', $counts);
                }
            } finally {
                $this->googleCalendarService->endRun();
                $lock->release();
            }
        }

        return $counts;
    }

    public function syncTask(CalendarConnection $connection, Task $task): string
    {
        if (! $this->taskIsEligibleForConnection($connection, $task)) {
            return 'skipped';
        }

        $mapping = CalendarEventMapping::query()
            ->where('user_id', $connection->user_id)
            ->where('provider', 'google')
            ->where('task_id', $task->id)
            ->first();

        try {
            $event = $this->googleCalendarService->upsertTaskEvent($connection, $task->loadMissing('project'), $mapping?->provider_event_id);
            $created = ! $mapping;

            CalendarEventMapping::updateOrCreate(
                [
                    'user_id' => $connection->user_id,
                    'provider' => 'google',
                    'provider_event_id' => $event['id'],
                ],
                [
                    'task_id' => $task->id,
                    'project_id' => $task->project_id,
                    'provider_calendar_id' => $event['organizer']['email'] ?? 'primary',
                    'last_synced_at' => now(),
                    'metadata' => $this->safeEventMetadata($event, [
                        'source' => 'miriam_task',
                        'task_title' => $task->title,
                        'date' => ($task->start_date ?? $task->due_date)?->toDateString(),
                    ]),
                ],
            );

            return $created ? 'created' : 'updated';
        } catch (Throwable $exception) {
            if ($exception instanceof CalendarProviderException && $exception->authRequired) {
                throw $exception;
            }
            $this->retryAfterSeconds = max($this->retryAfterSeconds, $exception instanceof CalendarProviderException ? $exception->retryAfterSeconds : 0);
            $this->lastErrorCode = $exception instanceof CalendarProviderException ? $exception->errorCode : 'task_push_failed';
            $this->log($connection, 'push', 'failed', 'Calendar task push failed.', ['task_id' => $task->id, 'error_code' => $this->lastErrorCode]);

            return 'failed';
        }
    }

    public function syncMedicationReminder(MedicationDoseLog $log): array
    {
        if (! $this->googleCalendarService->configured()) {
            return ['status' => 'skipped', 'outcome' => 'skipped', 'reason' => 'not_configured'];
        }

        $connection = CalendarConnection::query()
            ->where('user_id', $log->user_id)
            ->where('provider', 'google')
            ->where('is_active', true)
            ->latest()
            ->first();

        if (! $connection) {
            return ['status' => 'skipped', 'outcome' => 'skipped', 'reason' => 'not_connected'];
        }

        if ($blocked = $this->health->blocked($connection)) {
            return ['status' => $blocked === 'auth_required' ? 'auth_required' : 'skipped', 'outcome' => $blocked === 'auth_required' ? 'auth_required' : 'skipped', 'reason' => $blocked];
        }

        $existingProviderEventId = $this->existingMedicationProviderEventId($log);

        try {
            $event = $this->googleCalendarService->upsertMedicationReminderEvent($connection, $log->loadMissing('schedule'), $existingProviderEventId);

            CalendarEventMapping::updateOrCreate(
                [
                    'user_id' => $connection->user_id,
                    'provider' => 'google',
                    'provider_event_id' => $event['id'],
                ],
                [
                    'task_id' => null,
                    'project_id' => null,
                    'provider_calendar_id' => $event['organizer']['email'] ?? 'primary',
                    'last_synced_at' => now(),
                    'metadata' => $this->safeEventMetadata($event, [
                        'source' => 'miriam_medication_reminder',
                        'dose_log_id' => (string) $log->id,
                        'dose_schedule_id' => (string) $log->dose_schedule_id,
                        'scheduled_for' => $log->scheduled_for?->toIso8601String(),
                    ]),
                ],
            );

            return [
                'status' => $existingProviderEventId ? 'updated' : 'created',
                'outcome' => 'success',
                'provider_event_id' => $event['id'],
            ];
        } catch (CalendarProviderException $exception) {
            $outcome = $exception->authRequired ? 'auth_required' : 'failed';
            $this->health->record($connection, $outcome, $exception->errorCode, $exception->retryAfterSeconds);

            return ['status' => $outcome, 'outcome' => $outcome, 'reason' => $exception->errorCode];
        } catch (Throwable $exception) {
            return [
                'status' => 'failed',
                'outcome' => 'failed',
                'reason' => 'exception',
                'exception' => class_basename($exception),
            ];
        }
    }

    public function syncMiriamReminder(MiriamReminder $reminder): array
    {
        if (! $this->googleCalendarService->configured()) {
            return ['status' => 'skipped', 'outcome' => 'skipped', 'reason' => 'not_configured'];
        }

        if (! $reminder->user_id || ! $reminder->due_at) {
            return ['status' => 'skipped', 'outcome' => 'skipped', 'reason' => 'missing_user_or_time'];
        }

        $connection = CalendarConnection::query()
            ->where('user_id', $reminder->user_id)
            ->where('provider', 'google')
            ->where('is_active', true)
            ->latest()
            ->first();

        if (! $connection) {
            return ['status' => 'skipped', 'outcome' => 'skipped', 'reason' => 'not_connected'];
        }

        if ($blocked = $this->health->blocked($connection)) {
            return ['status' => $blocked === 'auth_required' ? 'auth_required' : 'skipped', 'outcome' => $blocked === 'auth_required' ? 'auth_required' : 'skipped', 'reason' => $blocked];
        }

        try {
            $event = $this->googleCalendarService->upsertMiriamReminderEvent($connection, $reminder, $reminder->google_calendar_event_id);

            $reminder->forceFill(['google_calendar_event_id' => $event['id'] ?? $reminder->google_calendar_event_id])->save();

            CalendarEventMapping::updateOrCreate(
                [
                    'user_id' => $connection->user_id,
                    'provider' => 'google',
                    'provider_event_id' => $event['id'],
                ],
                [
                    'task_id' => null,
                    'project_id' => null,
                    'provider_calendar_id' => $event['organizer']['email'] ?? 'primary',
                    'last_synced_at' => now(),
                    'metadata' => $this->safeEventMetadata($event, [
                        'source' => 'miriam_general_reminder',
                        'miriam_reminder_id' => (string) $reminder->id,
                    ]),
                ],
            );

            $reminder->events()->create([
                'event_type' => $reminder->wasChanged('google_calendar_event_id') ? 'calendar_event_created' : 'calendar_event_updated',
                'channel' => 'google_calendar',
                'occurred_at' => CarbonImmutable::now('UTC'),
                'metadata' => ['provider_event_id' => $event['id'] ?? null],
            ]);

            return [
                'status' => $reminder->wasChanged('google_calendar_event_id') ? 'created' : 'updated',
                'outcome' => 'success',
                'provider_event_id' => $event['id'] ?? null,
            ];
        } catch (CalendarProviderException $exception) {
            $outcome = $exception->authRequired ? 'auth_required' : 'failed';
            $this->health->record($connection, $outcome, $exception->errorCode, $exception->retryAfterSeconds);
            $reminder->events()->create(['event_type' => 'calendar_event_failed', 'channel' => 'google_calendar',
                'occurred_at' => CarbonImmutable::now('UTC'), 'metadata' => ['outcome' => $outcome, 'error_code' => $exception->errorCode]]);

            return ['status' => $outcome, 'outcome' => $outcome, 'reason' => $exception->errorCode];
        } catch (Throwable $exception) {
            $reminder->events()->create([
                'event_type' => 'calendar_event_failed',
                'channel' => 'google_calendar',
                'occurred_at' => CarbonImmutable::now('UTC'),
                'metadata' => ['exception' => class_basename($exception)],
            ]);

            return ['status' => 'failed', 'outcome' => 'failed', 'reason' => 'exception', 'exception' => class_basename($exception)];
        }
    }

    public function externalEventsForUser(User $user, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return CalendarEventMapping::query()
            ->where('user_id', $user->id)
            ->where('provider', 'google')
            ->whereNull('task_id')
            ->whereBetween('last_synced_at', [$start->subYear(), $end->addYear()])
            ->get()
            ->map(function (CalendarEventMapping $mapping) use ($start, $end): ?array {
                $metadata = $mapping->metadata ?? [];
                $date = $metadata['date'] ?? $metadata['start_date'] ?? null;

                if (! $date || $date < $start->toDateString() || $date > $end->toDateString()) {
                    return null;
                }

                return [
                    'type' => 'google_event',
                    'label' => 'Google Calendar',
                    'title' => $metadata['title'] ?? 'External event',
                    'date' => $date,
                    'url' => $metadata['html_link'] ?? null,
                    'status' => null,
                    'priority' => null,
                    'completed' => false,
                    'overdue' => false,
                    'external' => true,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function pullExternalEvents(CalendarConnection $connection): string
    {
        try {
            $items = $this->googleCalendarService->pullEvents($connection, now()->startOfMonth(), now()->endOfMonth());
            collect($items)
                ->reject(fn (array $event) => isset($event['extendedProperties']['private']['miriam_task_id']))
                ->each(function (array $event) use ($connection): void {
                    if (blank($event['id'] ?? null)) {
                        return;
                    }

                    CalendarEventMapping::updateOrCreate(
                        [
                            'user_id' => $connection->user_id,
                            'provider' => 'google',
                            'provider_event_id' => $event['id'],
                        ],
                        [
                            'task_id' => null,
                            'project_id' => null,
                            'provider_calendar_id' => $event['organizer']['email'] ?? 'primary',
                            'last_synced_at' => now(),
                            'metadata' => $this->safeEventMetadata($event, ['source' => 'google_external']),
                        ],
                    );
                });

            return 'success';
        } catch (CalendarProviderException $error) {
            if ($error->authRequired) {
                throw $error;
            }
            $this->lastErrorCode = $error->errorCode;
            $this->retryAfterSeconds = max($this->retryAfterSeconds, $error->retryAfterSeconds);
            $this->log($connection, 'pull', 'failed', 'Calendar read failed.', ['error_code' => $error->errorCode]);

            return 'failed';
        } catch (Throwable) {
            $this->lastErrorCode = 'calendar_read_failed';
            $this->log($connection, 'pull', 'failed', 'Calendar read failed.');

            return 'failed';
        }
    }

    private function existingMedicationProviderEventId(MedicationDoseLog $log): ?string
    {
        return MedicationReminderEvent::query()
            ->where('dose_log_id', $log->id)
            ->whereIn('event_type', ['calendar_event_created', 'calendar_event_updated'])
            ->latest('occurred_at')
            ->get()
            ->map(fn (MedicationReminderEvent $event) => $event->metadata['provider_event_id'] ?? null)
            ->first(fn (?string $providerEventId) => filled($providerEventId));
    }

    private function eligibleTasks(CalendarConnection $connection): Collection
    {
        return $this->eligibleTaskQuery($connection)->limit(100)->get();
    }

    private function eligibleTaskQuery(CalendarConnection $connection): Builder
    {
        $workspaceIds = $connection->user->accessibleWorkspaceIds();

        return Task::query()
            ->with('project')
            ->when(
                $workspaceIds !== [],
                fn (Builder $query) => $query->whereIn('workspace_id', $workspaceIds),
                fn (Builder $query) => $query->whereRaw('1 = 0'),
            )
            ->where(function (Builder $query) use ($connection): void {
                $query->where('assignee_id', $connection->user_id)
                    ->orWhere('reporter_id', $connection->user_id);
            })
            ->where(function (Builder $query): void {
                $query->whereNotNull('due_date')
                    ->orWhereNotNull('start_date');
            })
            ->whereNotIn('status', ['completed', 'archived'])
            ->orderBy('due_date');
    }

    private function taskIsEligibleForConnection(CalendarConnection $connection, Task $task): bool
    {
        return $connection->user->canAccessWorkspace($task->workspace_id)
            && in_array($connection->user_id, [(int) $task->assignee_id, (int) $task->reporter_id], true)
            && ! in_array($task->status, ['completed', 'archived'], true)
            && ($task->due_date || $task->start_date);
    }

    private function safeEventMetadata(array $event, array $extra = []): array
    {
        $date = $event['start']['date'] ?? (isset($event['start']['dateTime']) ? Carbon::parse($event['start']['dateTime'])->toDateString() : null);

        return array_filter([
            ...$extra,
            'title' => $event['summary'] ?? null,
            'date' => $date,
            'start_date' => $date,
            'html_link' => $event['htmlLink'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function log(CalendarConnection $connection, string $direction, string $status, string $message, array $metadata = []): CalendarSyncLog
    {
        return CalendarSyncLog::create([
            'calendar_connection_id' => $connection->id,
            'user_id' => $connection->user_id,
            'workspace_id' => $connection->workspace_id,
            'direction' => $direction,
            'status' => $status,
            'message' => $message,
            'metadata' => $metadata,
        ]);
    }
}
