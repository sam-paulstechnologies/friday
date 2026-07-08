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
use Throwable;

class CalendarSyncService
{
    public function __construct(private readonly GoogleCalendarService $googleCalendarService) {}

    public function syncConnection(CalendarConnection $connection): array
    {
        if (! $this->googleCalendarService->configured()) {
            $this->log($connection, 'manual', 'skipped', 'Google Calendar is disabled or not configured.');

            return ['created' => 0, 'updated' => 0, 'skipped' => 1, 'failed' => 0];
        }

        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        $this->eligibleTasks($connection)->each(function (Task $task) use ($connection, &$counts): void {
            $result = $this->syncTask($connection, $task);
            $counts[$result]++;
        });

        $this->pullExternalEvents($connection);
        $connection->forceFill(['last_synced_at' => now()])->save();
        $this->log($connection, 'manual', 'success', 'Google Calendar sync completed.', $counts);

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
            $this->log($connection, 'push', 'failed', $exception->getMessage(), ['task_id' => $task->id]);

            return 'failed';
        }
    }

    public function syncMedicationReminder(MedicationDoseLog $log): array
    {
        if (! $this->googleCalendarService->configured()) {
            return ['status' => 'skipped', 'reason' => 'not_configured'];
        }

        $connection = CalendarConnection::query()
            ->where('user_id', $log->user_id)
            ->where('provider', 'google')
            ->where('is_active', true)
            ->latest()
            ->first();

        if (! $connection) {
            return ['status' => 'skipped', 'reason' => 'not_connected'];
        }

        if (blank($connection->access_token)) {
            return ['status' => 'skipped', 'reason' => 'missing_access_token'];
        }

        if ($connection->token_expires_at && $connection->token_expires_at->isPast() && blank($connection->refresh_token)) {
            return ['status' => 'skipped', 'reason' => 'expired_without_refresh_token'];
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
                'provider_event_id' => $event['id'],
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'failed',
                'reason' => 'exception',
                'exception' => class_basename($exception),
            ];
        }
    }

    public function syncMiriamReminder(MiriamReminder $reminder): array
    {
        if (! $this->googleCalendarService->configured()) {
            return ['status' => 'skipped', 'reason' => 'not_configured'];
        }

        if (! $reminder->user_id || ! $reminder->due_at) {
            return ['status' => 'skipped', 'reason' => 'missing_user_or_time'];
        }

        $connection = CalendarConnection::query()
            ->where('user_id', $reminder->user_id)
            ->where('provider', 'google')
            ->where('is_active', true)
            ->latest()
            ->first();

        if (! $connection) {
            return ['status' => 'skipped', 'reason' => 'not_connected'];
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
                'provider_event_id' => $event['id'] ?? null,
            ];
        } catch (Throwable $exception) {
            $reminder->events()->create([
                'event_type' => 'calendar_event_failed',
                'channel' => 'google_calendar',
                'occurred_at' => CarbonImmutable::now('UTC'),
                'metadata' => ['exception' => class_basename($exception)],
            ]);

            return ['status' => 'failed', 'reason' => 'exception', 'exception' => class_basename($exception)];
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

    private function pullExternalEvents(CalendarConnection $connection): void
    {
        try {
            $items = $this->googleCalendarService->pullEvents($connection, now()->startOfMonth(), now()->endOfMonth());
        } catch (Throwable $exception) {
            $this->log($connection, 'pull', 'failed', $exception->getMessage());

            return;
        }

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
            ->orderBy('due_date')
            ->limit(100)
            ->get();
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
