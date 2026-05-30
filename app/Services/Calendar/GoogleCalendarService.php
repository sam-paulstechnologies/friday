<?php

namespace App\Services\Calendar;

use App\Models\CalendarConnection;
use App\Models\Task;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleCalendarService
{
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
            throw new RuntimeException('Google Calendar is not configured.');
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google_calendar.client_id'),
            'client_secret' => config('services.google_calendar.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('services.google_calendar.redirect_uri'),
        ]);

        $this->throwCleanlyWhenFailed($response, 'Google Calendar authorization failed.');

        return $response->json();
    }

    public function refreshIfNeeded(CalendarConnection $connection): CalendarConnection
    {
        if (! $connection->token_expires_at || $connection->token_expires_at->isFuture() || blank($connection->refresh_token)) {
            return $connection;
        }

        if (! $this->configured()) {
            throw new RuntimeException('Google Calendar is not configured.');
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google_calendar.client_id'),
            'client_secret' => config('services.google_calendar.client_secret'),
            'refresh_token' => $connection->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        $this->throwCleanlyWhenFailed($response, 'Google Calendar token refresh failed.');
        $payload = $response->json();

        $connection->forceFill([
            'access_token' => $payload['access_token'] ?? $connection->access_token,
            'token_expires_at' => isset($payload['expires_in']) ? now()->addSeconds((int) $payload['expires_in']) : $connection->token_expires_at,
            'scopes' => isset($payload['scope']) ? explode(' ', $payload['scope']) : $connection->scopes,
        ])->save();

        return $connection->refresh();
    }

    public function upsertTaskEvent(CalendarConnection $connection, Task $task, ?string $providerEventId = null): array
    {
        $connection = $this->refreshIfNeeded($connection);
        $payload = $this->taskPayload($task);
        $calendarId = 'primary';

        $response = $providerEventId
            ? Http::withToken($connection->access_token)->patch("https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events/{$providerEventId}", $payload)
            : Http::withToken($connection->access_token)->post("https://www.googleapis.com/calendar/v3/calendars/{$calendarId}/events", $payload);

        $this->throwCleanlyWhenFailed($response, $providerEventId ? 'Google Calendar event update failed.' : 'Google Calendar event creation failed.');

        return $response->json();
    }

    public function pullEvents(CalendarConnection $connection, Carbon $start, Carbon $end): array
    {
        $connection = $this->refreshIfNeeded($connection);

        $response = Http::withToken($connection->access_token)->get('https://www.googleapis.com/calendar/v3/calendars/primary/events', [
            'timeMin' => $start->copy()->startOfDay()->toRfc3339String(),
            'timeMax' => $end->copy()->endOfDay()->toRfc3339String(),
            'singleEvents' => 'true',
            'orderBy' => 'startTime',
        ]);

        $this->throwCleanlyWhenFailed($response, 'Google Calendar event read failed.');

        return $response->json('items', []);
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

    private function throwCleanlyWhenFailed(Response $response, string $message): void
    {
        if ($response->failed()) {
            throw new RuntimeException($message);
        }
    }
}
