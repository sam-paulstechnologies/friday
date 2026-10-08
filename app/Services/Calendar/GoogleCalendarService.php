<?php

namespace App\Services\Calendar;

use App\Models\CalendarConnection;
use App\Models\MedicationDoseLog;
use App\Models\MiriamReminder;
use App\Models\Task;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleCalendarService
{
    private ?int $requestBudget = null;

    private array $refreshed = [];

    public function __construct(private readonly CalendarConnectionHealthService $health) {}

    public function beginRun(): void
    {
        $this->requestBudget = 120;
        $this->refreshed = [];
    }

    public function endRun(): void
    {
        $this->requestBudget = null;
        $this->refreshed = [];
    }

    public function enabled(): bool
    {
        return (bool) config('services.google_calendar.enabled', false);
    }

    public function configured(): bool
    {
        return $this->enabled()
            && filled(config('services.google_calendar.client_id'))
            && filled(config('services.google_calendar.client_secret'))
            && filled(config('services.google_calendar.redirect_uri'));
    }

    public function authUrl(string $state): string
    {
        if (! $this->configured()) {
            throw new RuntimeException('Google Calendar is not configured.');
        }

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google_calendar.client_id'),
            'redirect_uri' => config('services.google_calendar.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', config('services.google_calendar.scopes', [])),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code): array
    {
        if (! $this->configured()) {
            throw new CalendarProviderException('not_configured');
        }
        $response = $this->request(fn () => Http::asForm()->timeout(8)->connectTimeout(3)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google_calendar.client_id'),
            'client_secret' => config('services.google_calendar.client_secret'),
            'code' => $code, 'grant_type' => 'authorization_code',
            'redirect_uri' => config('services.google_calendar.redirect_uri'),
        ]), 1);

        return $this->tokenPayload($response);
    }

    public function refreshIfNeeded(CalendarConnection $connection, bool $force = false): CalendarConnection
    {
        if (! $this->health->credentialsMatch($connection)) {
            throw new CalendarProviderException('connection_changed');
        }
        if ($this->health->blocked($connection) === 'auth_required') {
            throw new CalendarProviderException('auth_paused', true);
        }
        if (! $connection->is_active) {
            throw new CalendarProviderException('not_connected', true);
        }
        if (! $force && (! $connection->token_expires_at || $connection->token_expires_at->isFuture())) {
            if (blank($connection->access_token)) {
                throw new CalendarProviderException('missing_access_token', true);
            }

            return $connection;
        }
        $oldAccessToken = $connection->access_token;
        $lock = Cache::lock('jarvis:calendar:refresh:'.$connection->id, 90);
        if (! $lock->get()) {
            throw new CalendarProviderException('refresh_in_progress', false, true);
        }
        try {
            $connection->refresh();
            if ($connection->token_expires_at?->isFuture() && filled($connection->access_token)
                && (! $force || $connection->access_token !== $oldAccessToken)) {
                return $connection;
            }
            if (blank($connection->refresh_token)) {
                throw new CalendarProviderException('missing_refresh_token', true);
            }
            if (! $this->configured()) {
                throw new CalendarProviderException('not_configured');
            }
            $this->refreshed[$connection->id] = true;
            $response = $this->request(fn () => Http::asForm()->timeout(8)->connectTimeout(3)->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google_calendar.client_id'),
                'client_secret' => config('services.google_calendar.client_secret'),
                'refresh_token' => $connection->refresh_token, 'grant_type' => 'refresh_token',
            ]));
            $payload = $this->tokenPayload($response);
            $data = ['access_token' => $payload['access_token'],
                'token_expires_at' => now()->addSeconds(max(60, (int) ($payload['expires_in'] ?? 3600))),
                'scopes' => isset($payload['scope']) ? explode(' ', $payload['scope']) : $connection->scopes];
            if (filled($payload['refresh_token'] ?? null)) {
                $data['refresh_token'] = $payload['refresh_token'];
            }
            $connection->forceFill($data)->save();

            return $connection->refresh();
        } catch (CalendarProviderException $error) {
            if ($error->authRequired) {
                $this->health->record($connection, 'auth_required', $error->errorCode);
            }
            throw $error;
        } finally {
            $lock->release();
        }
    }

    public function upsertTaskEvent(CalendarConnection $connection, Task $task, ?string $providerEventId = null): array
    {
        return $this->upsertEvent($connection, $this->taskPayload($task), 'task:'.$task->id, $providerEventId);
    }

    public function upsertMedicationReminderEvent(CalendarConnection $connection, MedicationDoseLog $log, ?string $providerEventId = null): array
    {
        return $this->upsertEvent($connection, $this->medicationReminderPayload($log->loadMissing('schedule')), 'medication:'.$log->id, $providerEventId);
    }

    public function upsertMiriamReminderEvent(CalendarConnection $connection, MiriamReminder $reminder, ?string $providerEventId = null): array
    {
        return $this->upsertEvent($connection, $this->miriamReminderPayload($reminder), 'reminder:'.$reminder->id, $providerEventId);
    }

    private function upsertEvent(CalendarConnection $connection, array $payload, string $identity, ?string $providerEventId): array
    {
        $connection = $this->refreshIfNeeded($connection);
        $url = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';
        if ($providerEventId) {
            $response = $this->authorizedRequest($connection, fn () => Http::withToken($connection->access_token)->timeout(8)->connectTimeout(3)->patch($url.'/'.rawurlencode($providerEventId), $payload));
        } else {
            // Stable provider IDs make timeout/429 retries idempotent across processes/runs.
            $payload['id'] = 'miriam'.hash('sha256', $connection->id.':'.$identity);
            $response = $this->authorizedRequest($connection, fn () => Http::withToken($connection->access_token)->timeout(8)->connectTimeout(3)->post($url, $payload), 3, true);
            if ($response->status() === 409) {
                $response = $this->authorizedRequest($connection, fn () => Http::withToken($connection->access_token)->timeout(8)->connectTimeout(3)->get($url.'/'.$payload['id']));
                if ($response->json('extendedProperties.private') !== $payload['extendedProperties']['private']) {
                    throw new CalendarProviderException('event_identity_mismatch');
                }
                unset($payload['id']);
                $response = $this->authorizedRequest($connection, fn () => Http::withToken($connection->access_token)->timeout(8)->connectTimeout(3)->patch($url.'/miriam'.hash('sha256', $connection->id.':'.$identity), $payload));
            }
        }
        $event = $response->json();
        if (! is_array($event) || ! is_string($event['id'] ?? null) || blank($event['id'])) {
            throw new CalendarProviderException('invalid_event_response');
        }

        return $event;
    }

    public function pullEvents(CalendarConnection $connection, Carbon $start, Carbon $end): array
    {
        $connection = $this->refreshIfNeeded($connection);
        $items = [];
        $pageToken = null;
        for ($page = 0; $page < 10; $page++) {
            $response = $this->authorizedRequest($connection, fn () => Http::withToken($connection->access_token)->timeout(8)->connectTimeout(3)->get('https://www.googleapis.com/calendar/v3/calendars/primary/events', array_filter([
                'timeMin' => $start->copy()->startOfDay()->toRfc3339String(),
                'timeMax' => $end->copy()->endOfDay()->toRfc3339String(),
                'singleEvents' => 'true', 'orderBy' => 'startTime', 'pageToken' => $pageToken,
            ])));
            $data = $response->json();
            if (! is_array($data) || ! is_array($data['items'] ?? null)) {
                throw new CalendarProviderException('invalid_event_response');
            }
            $items = array_merge($items, $data['items']);
            $pageToken = $data['nextPageToken'] ?? null;
            if (! $pageToken) {
                return $items;
            }
        }
        throw new CalendarProviderException('page_budget_exhausted');
    }

    private function tokenPayload(Response $response): array
    {
        $payload = $response->json();
        if (! is_array($payload) || ! is_string($payload['access_token'] ?? null) || blank($payload['access_token'])) {
            throw new CalendarProviderException('invalid_token_response');
        }

        return $payload;
    }

    private function authorizedRequest(CalendarConnection $connection, callable $send, int $attempts = 3, bool $allowConflict = false): Response
    {
        try {
            return $this->request($send, $attempts, $allowConflict);
        } catch (CalendarProviderException $error) {
            if ($error->httpStatus === 401 && ! ($this->refreshed[$connection->id] ?? false) && filled($connection->refresh_token)) {
                $this->refreshed[$connection->id] = true;
                try {
                    $this->refreshIfNeeded($connection, true);

                    return $this->request($send, $attempts, $allowConflict);
                } catch (CalendarProviderException $retryError) {
                    $error = $retryError;
                }
            }
            if ($error->authRequired) {
                $this->health->record($connection, 'auth_required', $error->errorCode);
            }
            throw $error;
        }
    }

    private function request(callable $send, int $attempts = 3, bool $allowConflict = false): Response
    {
        $attempts = max(1, min(3, $attempts));
        $last = null;
        for ($attempt = 1; $attempt <= min(3, $attempts); $attempt++) {
            if ($this->requestBudget !== null && --$this->requestBudget < 0) {
                throw new CalendarProviderException('request_budget_exhausted');
            }
            $response = null;
            try {
                $response = $send();
                if ($response->successful() || $allowConflict && $response->status() === 409) {
                    return $response;
                }
                $rawCode = $response->json('error');
                $reason = $response->json('error.errors.0.reason');
                $auth = $response->status() === 401 || in_array($rawCode, ['invalid_grant', 'invalid_client', 'unauthorized_client', 'access_denied', 'invalid_scope'], true)
                    || in_array($reason, ['authError', 'insufficientPermissions'], true);
                $code = is_string($rawCode) && in_array($rawCode, ['invalid_grant', 'invalid_client', 'unauthorized_client', 'access_denied', 'invalid_scope'], true) ? $rawCode
                    : ($auth ? 'authentication_failed' : ($response->status() === 429 ? 'rate_limited' : 'http_'.$response->status()));
                $retryable = ! $auth && ($response->status() === 429 || $response->serverError()
                    || in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true));
                $header = $response->header('Retry-After');
                $retryAfter = is_string($header) && ctype_digit($header) ? min(86400, (int) $header) : 0;
                $last = new CalendarProviderException($code, $auth, $retryable, $response->status(), $retryAfter);
            } catch (ConnectionException) {
                $last = new CalendarProviderException('network_unavailable', false, true);
            }
            if (! $last->retryable || $attempt === $attempts || $last->retryAfterSeconds > 2) {
                throw $last;
            }
            $base = max(0, min(1000, (int) config('services.google_calendar.retry_delay_ms', 1000)));
            $delay = min(2000, $base * (2 ** ($attempt - 1)) + ($base ? random_int(0, 50) : 0));
            $retryAfter = $response?->header('Retry-After');
            if (is_string($retryAfter) && ctype_digit($retryAfter)) {
                $delay = max($delay, min(2000, (int) $retryAfter * 1000));
            }
            if ($delay > 0) {
                usleep($delay * 1000);
            }
        }
        throw $last ?? new CalendarProviderException('provider_failed');
    }

    private function taskPayload(Task $task): array
    {
        $date = $task->start_date ?? $task->due_date;
        $description = collect([
            $task->project?->name ? 'Project: '.$task->project->name : null,
            $task->priority ? 'Priority: '.$task->priority : null,
            route('tasks.show', $task, true),
        ])->filter()->implode("\n");

        return [
            'summary' => $task->title,
            'description' => $description,
            'start' => ['date' => $date?->toDateString()],
            'end' => ['date' => $date?->copy()->addDay()->toDateString()],
            'extendedProperties' => [
                'private' => [
                    'miriam_task_id' => (string) $task->id,
                    'miriam_workspace_id' => (string) $task->workspace_id,
                ],
            ],
        ];
    }

    private function medicationReminderPayload(MedicationDoseLog $log): array
    {
        $timezone = $log->scheduled_timezone ?: 'Asia/Dubai';
        $start = $log->scheduled_for
            ? Carbon::parse($log->scheduled_for)->setTimezone($timezone)
            : now($timezone);
        $end = $start->copy()->addMinutes(10);

        return [
            'summary' => $this->medicationReminderTitle($log),
            'description' => $this->medicationReminderDescription($log),
            'visibility' => 'private',
            'start' => [
                'dateTime' => $start->toRfc3339String(),
                'timeZone' => $timezone,
            ],
            'end' => [
                'dateTime' => $end->toRfc3339String(),
                'timeZone' => $timezone,
            ],
            'extendedProperties' => [
                'private' => [
                    'miriam_source' => 'medication_reminder',
                    'miriam_medication_dose_log_id' => (string) $log->id,
                    'miriam_medication_dose_schedule_id' => (string) $log->dose_schedule_id,
                ],
            ],
        ];
    }

    private function miriamReminderPayload(MiriamReminder $reminder): array
    {
        $timezone = $reminder->timezone ?: 'Asia/Dubai';
        $start = Carbon::parse($reminder->due_at)->setTimezone($timezone);
        $end = $start->copy()->addMinutes(15);

        return [
            'summary' => 'Reminder: '.$reminder->title,
            'description' => 'Captured by Miriam from Slack.',
            'visibility' => 'private',
            'start' => [
                'dateTime' => $start->toRfc3339String(),
                'timeZone' => $timezone,
            ],
            'end' => [
                'dateTime' => $end->toRfc3339String(),
                'timeZone' => $timezone,
            ],
            'extendedProperties' => [
                'private' => [
                    'miriam_source' => 'general_reminder',
                    'miriam_reminder_id' => (string) $reminder->id,
                ],
            ],
        ];
    }

    private function medicationReminderTitle(MedicationDoseLog $log): string
    {
        return match ($log->schedule?->dose_key) {
            'morning' => "Sam's Medication - Morning",
            'evening' => "Sam's Medication - Evening",
            'weekly_ozempic' => "Sam's Medication - Ozempic",
            default => "Sam's Medication",
        };
    }

    private function medicationReminderDescription(MedicationDoseLog $log): string
    {
        $items = $this->medicationItems($log);

        return "Medication due:\n"
            .collect($items)->map(fn (string $item): string => "- {$item}")->implode("\n")
            ."\n\nConfirm Taken, Snooze, or Skip in Miriam/Slack.";
    }

    private function medicationItems(MedicationDoseLog $log): array
    {
        $metadataItems = collect($log->schedule?->metadata['medication_items'] ?? [])
            ->pluck('name')
            ->filter()
            ->map(fn (string $name): string => trim($name))
            ->values()
            ->all();

        if ($metadataItems !== []) {
            return $metadataItems;
        }

        return collect(explode(';', (string) $log->schedule?->dosage_text))
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->values()
            ->all() ?: ['Scheduled medication'];
    }
}
