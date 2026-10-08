<?php

namespace App\Services\Calendar;

use App\Models\CalendarConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class CalendarConnectionHealthService
{
    private const FIELDS = ['last_attempted_at', 'last_successful_sync_at', 'sync_state',
        'consecutive_failures', 'next_retry_at', 'last_error_code'];

    public function state(CalendarConnection $connection): array
    {
        $current = $connection->fresh();
        $cached = $current ? Cache::get($this->key($current)) : null;
        $stored = is_array($cached) ? $cached : ($current && Schema::hasColumns('calendar_connections', self::FIELDS)
            ? $current->only(self::FIELDS) : []);

        $stored = array_intersect_key(is_array($stored) ? $stored : [], array_fill_keys(self::FIELDS, true));
        $state = array_merge([
            'sync_state' => 'unverified', 'last_attempted_at' => null, 'last_successful_sync_at' => null,
            'consecutive_failures' => 0, 'next_retry_at' => null, 'last_error_code' => null,
        ], $stored);
        if (! in_array($state['sync_state'], ['success', 'partial', 'failed', 'auth_required', 'skipped', 'unverified'], true)) {
            $state['sync_state'] = 'unverified';
        }
        foreach (['last_attempted_at', 'last_successful_sync_at', 'next_retry_at'] as $field) {
            $date = $state[$field];
            if ($date instanceof \DateTimeInterface) {
                $state[$field] = $date->format(DATE_ATOM);
            } elseif ($date !== null && (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/', $date))) {
                $state[$field] = null;
            }
        }
        $state['consecutive_failures'] = is_numeric($state['consecutive_failures']) ? min(100, max(0, (int) $state['consecutive_failures'])) : 0;
        $state['last_error_code'] = $this->safeCode($state['last_error_code']);
        if (! is_array($cached)) {
            $state['last_successful_sync_at'] = null;
            if ($state['sync_state'] === 'success') {
                $state['sync_state'] = 'unverified';
            }
        }
        if ($state['sync_state'] === 'success' && (! $state['last_successful_sync_at']
            || now()->lt($state['last_successful_sync_at']))) {
            $state['sync_state'] = 'unverified';
            $state['last_successful_sync_at'] = null;
        }

        return $state;
    }

    public function blocked(CalendarConnection $connection): ?string
    {
        $state = $this->state($connection);
        if ($state['sync_state'] === 'auth_required') {
            return 'auth_required';
        }
        if ($state['next_retry_at'] && now()->lt($state['next_retry_at'])) {
            return 'backoff';
        }

        return null;
    }

    public function record(CalendarConnection $connection, string $outcome, ?string $errorCode = null, int $retryAfterSeconds = 0): void
    {
        if (! $this->credentialsMatch($connection)) {
            return; // A stale request must not overwrite reconnect/disconnect health.
        }
        if (! in_array($outcome, ['success', 'partial', 'failed', 'auth_required', 'skipped', 'unverified'], true)) {
            throw new \InvalidArgumentException('Invalid Calendar outcome.');
        }
        $previous = $this->state($connection);
        $failure = in_array($outcome, ['failed', 'partial', 'auth_required'], true);
        $failures = $failure ? min(100, (int) $previous['consecutive_failures'] + ($outcome === 'auth_required' && $previous['sync_state'] === 'auth_required' ? 0 : 1)) : 0;
        $state = [
            'last_attempted_at' => $outcome === 'unverified' ? null : now()->toIso8601String(),
            'last_successful_sync_at' => $outcome === 'success' ? now()->toIso8601String()
                : ($outcome === 'unverified' ? null : $previous['last_successful_sync_at']),
            'sync_state' => $outcome, 'consecutive_failures' => $failures, 'last_error_code' => $this->safeCode($errorCode),
            'next_retry_at' => $failure && $outcome !== 'auth_required'
                ? now()->addSeconds(max(min(86400, $retryAfterSeconds), min(3600, 60 * (2 ** min(6, $failures - 1))) + random_int(0, 10)))->toIso8601String() : null,
        ];
        // Legacy-schema compatibility: persistent shared cache until the additive migration is approved.
        Cache::forever($this->key($connection), $state);
        if (Schema::hasColumns('calendar_connections', self::FIELDS)) {
            $connection->forceFill($state)->save();
        }
        if ($outcome === 'success') {
            $connection->forceFill(['last_synced_at' => now()])->save();
        }
    }

    public function markAttempted(CalendarConnection $connection): void
    {
        if (! $this->credentialsMatch($connection)) {
            return;
        }
        $state = $this->state($connection);
        $state['last_attempted_at'] = now()->toIso8601String();
        $state['sync_state'] = 'unverified';
        // Preserve failure/backoff counters and historical success while work is in flight.
        Cache::forever($this->key($connection), $state);
        if (Schema::hasColumns('calendar_connections', self::FIELDS)) {
            $connection->forceFill($state)->save();
        }
    }

    public function reset(CalendarConnection $connection): void
    {
        $this->record($connection, 'unverified');
    }

    public function key(CalendarConnection $connection): string
    {
        $version = [];
        foreach (['access_token', 'refresh_token', 'provider', 'provider_account_email'] as $field) {
            $version[$field] = (string) $connection->getRawOriginal($field);
        }
        $version['active'] = (bool) $connection->getRawOriginal('is_active');
        $version['user_id'] = (int) $connection->user_id;
        $version['workspace_id'] = (int) $connection->workspace_id;

        // Separate credential epochs atomically; a stale cache write cannot validate a reconnect.
        return 'jarvis:calendar:health:'.$connection->id.':'.hash('sha256', json_encode($version));
    }

    public function credentialsMatch(CalendarConnection $connection): bool
    {
        $current = $connection->fresh();
        if (! $current) {
            return false;
        }
        foreach (['access_token', 'refresh_token', 'provider', 'provider_account_email'] as $field) {
            if ((string) $connection->getRawOriginal($field) !== (string) $current->getRawOriginal($field)) {
                return false;
            }
        }

        return (bool) $connection->getRawOriginal('is_active') === (bool) $current->getRawOriginal('is_active')
            && (int) $connection->user_id === (int) $current->user_id
            && (int) $connection->workspace_id === (int) $current->workspace_id;
    }

    private function safeCode(mixed $code): ?string
    {
        if (! is_string($code)) {
            return null;
        }

        return preg_match('/^http_[1-5]\d\d$/', $code) || in_array($code, [
            'invalid_grant', 'invalid_client', 'unauthorized_client', 'access_denied', 'invalid_scope',
            'authentication_failed', 'rate_limited', 'network_unavailable', 'missing_access_token',
            'missing_refresh_token', 'auth_paused', 'not_connected', 'not_configured', 'refresh_in_progress',
            'invalid_event_response', 'invalid_token_response', 'page_budget_exhausted', 'request_budget_exhausted',
            'event_identity_mismatch', 'task_push_failed', 'calendar_read_failed', 'sync_failed', 'run_budget_exhausted', 'connection_changed',
        ], true) ? $code : null;
    }
}
