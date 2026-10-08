<?php

namespace Tests\Feature;

use App\Models\CalendarConnection;
use App\Models\CalendarSyncLog;
use App\Models\MiriamReminder;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Calendar\CalendarConnectionHealthService;
use App\Services\Calendar\CalendarSyncService;
use App\Services\System\SystemHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CalendarFailureReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-08 09:00:00');
        config(['services.google_calendar.enabled' => true, 'services.google_calendar.client_id' => 'fixture-client',
            'services.google_calendar.client_secret' => 'fixture-client-secret',
            'services.google_calendar.redirect_uri' => 'https://example.test/callback',
            'services.google_calendar.retry_delay_ms' => 0]);
        Http::preventStrayRequests();
    }

    public function test_refresh_rejection_is_auth_required_and_pauses_all_task_retries(): void
    {
        $connection = $this->connection(['token_expires_at' => now()->subMinute()]);
        for ($i = 0; $i < 5; $i++) {
            $this->task($connection);
        }
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant',
            'error_description' => 'fixture-sensitive-provider-body'], 400)]);
        $result = $this->sync($connection);
        $this->assertSame('auth_required', $result['outcome']);
        $this->assertSame(0, $result['push']['attempted']);
        $this->assertSame(5, $result['push']['skipped']);
        $this->assertSame(1, $result['authentication']['failed']);
        $this->assertNull($connection->fresh()->last_synced_at);
        $this->assertSame('auth_required', $this->sync($connection->fresh())['outcome']);
        Http::assertSentCount(1);
        $this->assertStringNotContainsString('fixture-sensitive-provider-body', CalendarSyncLog::all()->toJson());
        $this->assertDatabaseMissing('calendar_sync_logs', ['status' => 'success']);
    }

    public function test_expired_token_without_refresh_never_reaches_provider(): void
    {
        $connection = $this->connection(['token_expires_at' => now()->subMinute(), 'refresh_token' => null]);
        Http::fake();
        $this->assertSame('auth_required', $this->sync($connection)['outcome']);
        Http::assertNothingSent();
    }

    public static function omittedRefreshTokens(): array
    {
        return [[], [null], ['']];
    }

    #[DataProvider('omittedRefreshTokens')]
    public function test_callback_preserves_refresh_token_and_resets_auth_pause(?string $replacement = null): void
    {
        $connection = $this->connection();
        app(CalendarConnectionHealthService::class)->record($connection, 'auth_required', 'invalid_grant');
        $token = ['access_token' => 'fixture-new-access', 'expires_in' => 3600];
        if (func_num_args()) {
            $token['refresh_token'] = $replacement;
        }
        Http::fake(['oauth2.googleapis.com/*' => Http::response($token)]);
        $this->actingAs($connection->user)->withSession([
            'google_calendar_oauth_state' => 'fixture-state',
            'google_calendar_workspace_id' => $connection->workspace_id,
        ])->get(route('settings.integrations.google.callback', ['code' => 'fixture-code', 'state' => 'fixture-state']))
            ->assertSessionHas('success')->assertSessionMissing('_old_input');
        $this->assertSame('fixture-refresh', $connection->fresh()->refresh_token);
        $this->assertSame('unverified', app(CalendarConnectionHealthService::class)->state($connection)['sync_state']);
        $this->assertDatabaseCount('calendar_connections', 1);
    }

    public function test_partial_push_is_not_success_and_failed_task_has_no_mapping(): void
    {
        $connection = $this->connection();
        $this->task($connection, 'Good task');
        $failed = $this->task($connection, 'Bad task');
        Http::fake(fn ($request) => $request->method() === 'GET' ? Http::response(['items' => []])
            : ($request['summary'] === 'Bad task' ? Http::response(['error' => ['message' => 'fixture-sensitive-provider-body']], 400)
                : Http::response(['id' => $request['id']])));
        $result = $this->sync($connection);
        $this->assertSame('partial', $result['outcome']);
        $this->assertSame(1, $result['push']['succeeded']);
        $this->assertSame(1, $result['push']['failed']);
        $this->assertSame(1, $result['pull']['succeeded']);
        $this->assertDatabaseMissing('calendar_event_mappings', ['task_id' => $failed->id]);
        $this->assertNull($connection->fresh()->last_synced_at);
        $this->assertStringNotContainsString('fixture-sensitive-provider-body', CalendarSyncLog::all()->toJson());
    }

    public function test_pull_failure_is_partial_and_preserves_legacy_timestamp_without_verifying_it(): void
    {
        $connection = $this->connection(['last_synced_at' => now()->subDay()]);
        $this->task($connection);
        Http::fake(fn ($request) => $request->method() === 'GET' ? Http::response([], 503)
            : Http::response(['id' => $request['id']]));
        $result = $this->sync($connection);
        $this->assertSame('partial', $result['outcome']);
        $this->assertSame(1, $result['pull']['failed']);
        $this->assertEquals(now()->subDay(), $connection->fresh()->last_synced_at);
        $this->assertNull(app(CalendarConnectionHealthService::class)->state($connection)['last_successful_sync_at']);
        Http::assertSentCount(4);
        $this->assertSame('skipped', $this->sync($connection->fresh())['outcome']);
        Http::assertSentCount(4);
    }

    public function test_all_data_operations_failed_is_failed_even_if_token_validation_succeeded(): void
    {
        $connection = $this->connection();
        Http::fake(['www.googleapis.com/*' => Http::response([], 503)]);
        $result = $this->sync($connection);
        $this->assertSame('failed', $result['outcome']);
        Http::assertSentCount(3);
        $this->assertNull($connection->fresh()->last_synced_at);
    }

    public static function transientFailures(): array
    {
        return [[429], [503], [0]];
    }

    #[DataProvider('transientFailures')]
    public function test_transient_retry_is_bounded_and_uses_identical_event_identity(int $status): void
    {
        $connection = $this->connection();
        $this->task($connection);
        $postIds = [];
        Http::fake(function ($request) use ($status, &$postIds) {
            if ($request->method() === 'GET') {
                return Http::response(['items' => []]);
            }
            $postIds[] = $request['id'];
            if (count($postIds) === 1) {
                if ($status === 0) {
                    throw new ConnectionException('fixture-sensitive-provider-body');
                }

                return Http::response([], $status);
            }

            return Http::response(['id' => $request['id']]);
        });
        $this->assertSame('success', $this->sync($connection)['outcome']);
        $this->assertCount(2, $postIds);
        $this->assertSame($postIds[0], $postIds[1]);
        $this->assertDatabaseCount('calendar_event_mappings', 1);
    }

    public static function permanentFailures(): array
    {
        return [[401, []], [400, ['error' => 'invalid_client']],
            [403, ['error' => ['errors' => [['reason' => 'insufficientPermissions']]]]]];
    }

    #[DataProvider('permanentFailures')]
    public function test_permanent_auth_error_stops_remaining_pushes_and_pull(int $status, array $body): void
    {
        $connection = $this->connection();
        for ($i = 0; $i < 4; $i++) {
            $this->task($connection);
        }
        Http::fake(['www.googleapis.com/*' => Http::response($body, $status),
            'oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);
        $result = $this->sync($connection);
        $this->assertSame('auth_required', $result['outcome']);
        $this->assertSame(1, $result['push']['attempted']);
        $this->assertSame(3, $result['push']['skipped']);
        $this->assertSame(0, $result['pull']['attempted']);
        Http::assertSentCount($status === 401 ? 2 : 1);
        $this->assertSame('auth_required', $this->sync($connection->fresh())['outcome']);
        Http::assertSentCount($status === 401 ? 2 : 1);
    }

    public function test_conflict_after_unknown_network_outcome_reads_matching_existing_event(): void
    {
        $connection = $this->connection();
        $this->task($connection);
        $payload = null;
        $posts = 0;
        Http::fake(function ($request) use (&$payload, &$posts) {
            if ($request->method() === 'POST') {
                $posts++;
                $payload ??= $request->data();
                if ($posts === 1) {
                    throw new ConnectionException('Unknown fixture outcome');
                }
                $this->assertSame($payload['id'], $request['id']);

                return Http::response([], 409);
            }

            return str_contains($request->url(), '/events/miriam')
                ? Http::response(['id' => $payload['id'], 'extendedProperties' => $payload['extendedProperties']])
                : Http::response(['items' => []]);
        });
        $this->assertSame('success', $this->sync($connection)['outcome']);
        $this->assertSame(2, $posts);
        $this->assertDatabaseCount('calendar_event_mappings', 1);
    }

    public function test_invalid_provider_success_body_cannot_create_mapping(): void
    {
        $connection = $this->connection();
        $this->task($connection);
        Http::fake(fn ($request) => Http::response($request->method() === 'GET' ? ['items' => []] : ['invalid' => true]));
        $this->assertSame('partial', $this->sync($connection)['outcome']);
        $this->assertDatabaseCount('calendar_event_mappings', 0);
        $this->assertNull($connection->fresh()->last_synced_at);
    }

    public function test_disabled_or_inactive_sync_is_skipped_without_timestamp_or_requests(): void
    {
        $connection = $this->connection();
        config(['services.google_calendar.enabled' => false]);
        $this->assertSame('skipped', $this->sync($connection)['outcome']);
        Http::assertNothingSent();
        $this->assertNull($connection->fresh()->last_synced_at);
    }

    public function test_empty_push_with_successful_provider_pull_is_verified_success(): void
    {
        $connection = $this->connection();
        Http::fake(['www.googleapis.com/*' => Http::response(['items' => []])]);
        $result = $this->sync($connection);
        $this->assertSame('success', $result['outcome']);
        $this->assertSame(1, $result['pull']['attempted']);
        Http::assertSentCount(1);
        $this->assertNotNull($connection->fresh()->last_synced_at);
    }

    public function test_concurrent_manual_sync_cannot_enter_same_connection(): void
    {
        $connection = $this->connection();
        $this->task($connection);
        $nested = null;
        Http::fake(function ($request) use ($connection, &$nested) {
            if ($request->method() === 'GET') {
                return Http::response(['items' => []]);
            }
            $nested = $this->sync($connection->fresh());

            return Http::response(['id' => $request['id']]);
        });
        $this->assertSame('success', $this->sync($connection)['outcome']);
        $this->assertSame('skipped', $nested['outcome']);
        $this->assertSame('sync_in_progress', $nested['error_code']);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('calendar_event_mappings', 1);
    }

    public function test_manual_controller_never_flashes_success_on_auth_failure(): void
    {
        $connection = $this->connection(['token_expires_at' => now()->subMinute()]);
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->actingAs($connection->user)->post(route('settings.integrations.google.sync'))
            ->assertSessionHas('error')->assertSessionMissing('success');
    }

    public function test_command_fails_when_required_provider_work_fails(): void
    {
        $this->connection();
        Http::fake(['www.googleapis.com/*' => Http::response([], 503)]);
        $this->assertSame(1, Artisan::call('miriam:sync-google-calendar'));
    }

    public function test_legacy_timestamp_is_not_system_health_proof_and_failure_has_priority(): void
    {
        $this->connection(['last_synced_at' => now()]);
        $check = collect(app(SystemHealthService::class)->summary()['checks'])->firstWhere('name', 'Google Calendar');
        $this->assertSame('warning', $check['status']);
        $failed = $this->connection();
        app(CalendarConnectionHealthService::class)->record($failed, 'auth_required', 'invalid_grant');
        $check = collect(app(SystemHealthService::class)->summary()['checks'])->firstWhere('name', 'Google Calendar');
        $this->assertSame('failed', $check['status']);
        Http::assertNothingSent();
    }

    public function test_ui_and_health_projection_never_expose_legacy_log_or_cache_credentials(): void
    {
        $connection = $this->connection(['last_synced_at' => now()]);
        $secret = 'fixture-sensitive-provider-body';
        CalendarSyncLog::create(['calendar_connection_id' => $connection->id, 'user_id' => $connection->user_id,
            'direction' => 'manual', 'status' => 'success', 'message' => $secret, 'metadata' => ['access_token' => $secret]]);
        $health = app(CalendarConnectionHealthService::class);
        Cache::forever($health->key($connection), ['sync_state' => $secret, 'access_token' => $secret,
            'last_error_code' => $secret, 'last_successful_sync_at' => $secret]);
        $this->assertStringNotContainsString($secret, json_encode($health->state($connection)));
        $this->actingAs($connection->user)->get(route('settings.integrations.index'))
            ->assertOk()->assertDontSee($secret)->assertDontSee('fixture-access')
            ->assertInertia(fn ($page) => $page->where('googleCalendar.logs.0.status', 'unverified'));
    }

    public function test_pagination_is_bounded_and_incomplete_pull_never_verifies_success(): void
    {
        $connection = $this->connection();
        Http::fake(['www.googleapis.com/*' => Http::response(['items' => [], 'nextPageToken' => 'fixture-page'])]);
        $result = $this->sync($connection);
        $this->assertSame('failed', $result['outcome']);
        $this->assertSame('page_budget_exhausted', $result['error_code']);
        Http::assertSentCount(10);
        $this->assertNull($connection->fresh()->last_synced_at);
    }

    public function test_unprocessed_task_budget_is_partial_with_accurate_skipped_count(): void
    {
        $connection = $this->connection();
        for ($i = 0; $i < 102; $i++) {
            $this->task($connection);
        }
        Http::fake(fn ($request) => Http::response($request->method() === 'GET' ? ['items' => []] : ['id' => $request['id']]));
        $result = $this->sync($connection);
        $this->assertSame('partial', $result['outcome']);
        $this->assertSame(100, $result['push']['succeeded']);
        $this->assertSame(2, $result['push']['skipped']);
        $this->assertNull($connection->fresh()->last_synced_at);
    }

    public function test_prepared_migration_is_not_in_default_schema(): void
    {
        $this->assertFileExists(database_path('migrations/pending/2026_10_08_220001_add_calendar_sync_health_fields.php'));
        $this->assertFalse(Schema::hasColumn('calendar_connections', 'last_successful_sync_at'));
    }

    public function test_refresh_is_performed_once_for_multiple_tasks_and_pull(): void
    {
        $connection = $this->connection(['token_expires_at' => now()->subMinute()]);
        for ($i = 0; $i < 3; $i++) {
            $this->task($connection);
        }
        $refreshes = 0;
        Http::fake(function ($request) use (&$refreshes) {
            if (str_contains($request->url(), 'oauth2.googleapis.com')) {
                $refreshes++;

                return Http::response(['access_token' => 'fixture-new-access', 'expires_in' => 3600]);
            }

            return Http::response($request->method() === 'GET' ? ['items' => []] : ['id' => $request['id']]);
        });
        $this->assertSame('success', $this->sync($connection)['outcome']);
        $this->assertSame(1, $refreshes);
        $this->assertSame('fixture-refresh', $connection->fresh()->refresh_token);
        Http::assertSentCount(5);
    }

    public function test_unexpected_access_token_401_refreshes_once_and_recovers(): void
    {
        $connection = $this->connection();
        $this->task($connection);
        $posts = 0;
        Http::fake(function ($request) use (&$posts) {
            if (str_contains($request->url(), 'oauth2.googleapis.com')) {
                return Http::response(['access_token' => 'fixture-new-access', 'expires_in' => 3600]);
            }
            if ($request->method() === 'POST' && ++$posts === 1) {
                return Http::response([], 401);
            }

            return Http::response($request->method() === 'GET' ? ['items' => []] : ['id' => $request['id']]);
        });
        $this->assertSame('success', $this->sync($connection)['outcome']);
        $this->assertSame(2, $posts);
        Http::assertSentCount(4);
    }

    public function test_long_retry_after_pauses_entire_connection_without_repeated_pushes(): void
    {
        $connection = $this->connection();
        for ($i = 0; $i < 4; $i++) {
            $this->task($connection);
        }
        Http::fake(['www.googleapis.com/*' => Http::response([], 429, ['Retry-After' => '120'])]);
        $result = $this->sync($connection);
        $this->assertSame('failed', $result['outcome']);
        $this->assertSame(3, $result['push']['skipped']);
        $this->assertSame(0, $result['pull']['attempted']);
        $state = app(CalendarConnectionHealthService::class)->state($connection);
        $this->assertTrue(now()->addSeconds(120)->lte($state['next_retry_at']));
        $this->travel(90)->seconds();
        $this->assertSame('skipped', $this->sync($connection->fresh())['outcome']);
        Http::assertSentCount(1);
    }

    public function test_reminder_auth_failure_is_reported_and_subsequent_reminders_do_not_retry(): void
    {
        $connection = $this->connection();
        $reminder = MiriamReminder::create(['user_id' => $connection->user_id, 'category' => 'work',
            'title' => 'Synthetic reminder', 'timezone' => 'Asia/Dubai', 'due_at' => now()->addHour(),
            'status' => 'pending', 'next_reminder_at' => now()->addHour()]);
        Http::fake(['www.googleapis.com/*' => Http::response(['error' => ['errors' => [['reason' => 'insufficientPermissions']]]], 403)]);
        $service = app(CalendarSyncService::class);
        $this->assertSame('auth_required', $service->syncMiriamReminder($reminder)['outcome']);
        $this->assertSame('auth_required', $service->syncMiriamReminder($reminder->fresh())['outcome']);
        Http::assertSentCount(1);
        $this->assertSame(1, $reminder->events()->where('event_type', 'calendar_event_failed')->count());
    }

    public function test_cached_future_success_timestamp_is_not_health_proof(): void
    {
        $connection = $this->connection();
        $health = app(CalendarConnectionHealthService::class);
        Cache::forever($health->key($connection), ['sync_state' => 'success',
            'last_successful_sync_at' => now()->addDay()->toIso8601String()]);
        $this->assertSame('unverified', $health->state($connection)['sync_state']);
        Http::assertNothingSent();
    }

    public function test_duplicate_provider_identity_mismatch_cannot_be_claimed_or_updated(): void
    {
        $connection = $this->connection();
        $this->task($connection);
        Http::fake(function ($request) {
            if ($request->method() === 'POST') {
                return Http::response([], 409);
            }

            return str_contains($request->url(), '/events/miriam')
                ? Http::response(['id' => 'fixture-other-event', 'extendedProperties' => ['private' => ['miriam_task_id' => 'other']]])
                : Http::response(['items' => []]);
        });
        $this->assertSame('partial', $this->sync($connection)['outcome']);
        $this->assertDatabaseCount('calendar_event_mappings', 0);
        Http::assertNotSent(fn ($request) => $request->method() === 'PATCH');
    }

    public function test_reconnection_during_sync_cannot_verify_new_credentials_or_keep_pushing(): void
    {
        $connection = $this->connection();
        $this->task($connection);
        $this->task($connection);
        Http::fake(function ($request) use ($connection) {
            $current = $connection->fresh();
            $current->forceFill(['access_token' => 'fixture-rotated-access'])->save();
            app(CalendarConnectionHealthService::class)->reset($current);

            return Http::response(['id' => $request['id']]);
        });
        $result = $this->sync($connection);
        $this->assertSame('partial', $result['outcome']);
        $this->assertSame('connection_changed', $result['error_code']);
        $this->assertSame(1, $result['push']['skipped']);
        Http::assertSentCount(1);
        $this->assertNull($connection->fresh()->last_synced_at);
        $this->assertNull(app(CalendarConnectionHealthService::class)->state($connection)['last_successful_sync_at']);
    }

    private function connection(array $overrides = []): CalendarConnection
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Synthetic Calendar', 'slug' => 'calendar-'.$user->id, 'created_by' => $user->id]);
        $workspace->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);

        return CalendarConnection::create(array_merge(['user_id' => $user->id, 'workspace_id' => $workspace->id,
            'provider' => 'google', 'provider_account_email' => $user->email, 'access_token' => 'fixture-access',
            'refresh_token' => 'fixture-refresh', 'token_expires_at' => now()->addHour(), 'is_active' => true], $overrides));
    }

    private function task(CalendarConnection $connection, string $title = 'Synthetic Calendar task'): Task
    {
        return Task::create(['workspace_id' => $connection->workspace_id, 'title' => $title, 'status' => 'todo',
            'assignee_id' => $connection->user_id, 'reporter_id' => $connection->user_id, 'due_date' => now()->toDateString()]);
    }

    private function sync(CalendarConnection $connection): array
    {
        return app(CalendarSyncService::class)->syncConnection($connection);
    }
}
