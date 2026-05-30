<?php

namespace Tests\Feature;

use App\Models\CalendarConnection;
use App\Models\CalendarEventMapping;
use App\Models\CalendarSyncLog;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GoogleCalendarIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-05-30 10:00:00');
    }

    public function test_integration_settings_page_loads_with_disabled_message(): void
    {
        [$user] = $this->context('settings');
        config(['services.google_calendar.enabled' => false]);

        $this->actingAs($user)
            ->get(route('settings.integrations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Integrations/Index')
                ->where('googleCalendar.enabled', false)
                ->where('googleCalendar.configured', false)
            );
    }

    public function test_user_can_start_connect_flow_when_configured(): void
    {
        [$user] = $this->context('connect');
        $this->enableGoogleCalendar();

        $this->actingAs($user)
            ->get(route('settings.integrations.google.connect'))
            ->assertRedirectContains('https://accounts.google.com/o/oauth2/v2/auth');
    }

    public function test_oauth_callback_stores_encrypted_connection_without_exposing_tokens(): void
    {
        [$user, $workspace] = $this->context('callback');
        $this->enableGoogleCalendar();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fixture-access-value',
                'refresh_token' => 'fixture-refresh-value',
                'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/calendar.events',
                'provider_account_email' => 'calendar@example.com',
            ]),
        ]);

        $this->actingAs($user)
            ->withSession([
                'google_calendar_oauth_state' => 'state-token',
                'google_calendar_workspace_id' => $workspace->id,
            ])
            ->get(route('settings.integrations.google.callback', ['code' => 'auth-code', 'state' => 'state-token']))
            ->assertRedirect(route('settings.integrations.index'));

        $connection = CalendarConnection::firstOrFail();
        $raw = DB::table('calendar_connections')->where('id', $connection->id)->first();

        $this->assertSame('fixture-access-value', $connection->access_token);
        $this->assertNotSame('fixture-access-value', $raw->access_token);
        $this->assertSame('calendar@example.com', $connection->provider_account_email);

        $this->actingAs($user)
            ->get(route('settings.integrations.index'))
            ->assertOk()
            ->assertDontSee('fixture-access-value')
            ->assertDontSee('fixture-refresh-value')
            ->assertInertia(fn (Assert $page) => $page
                ->missing('googleCalendar.connection.access_token')
                ->missing('googleCalendar.connection.refresh_token')
            );
    }

    public function test_user_can_disconnect_own_calendar_connection(): void
    {
        [$user, $workspace] = $this->context('disconnect');
        $connection = $this->connection($user, $workspace);

        $this->actingAs($user)
            ->patch(route('settings.integrations.google.disconnect', $connection))
            ->assertRedirect();

        $this->assertFalse($connection->refresh()->is_active);
        $this->assertNull($connection->access_token);
    }

    public function test_user_cannot_manage_another_users_calendar_connection(): void
    {
        [$owner, $workspace] = $this->context('owner');
        [$intruder] = $this->context('intruder');
        $connection = $this->connection($owner, $workspace);

        $this->actingAs($intruder)
            ->patch(route('settings.integrations.google.disconnect', $connection))
            ->assertForbidden();

        $this->assertTrue($connection->refresh()->is_active);
    }

    public function test_manual_sync_creates_log_and_calendar_event_mapping(): void
    {
        [$user, $workspace, $project] = $this->context('sync');
        $connection = $this->connection($user, $workspace);
        $task = $this->task($workspace, $project, $user, ['title' => 'Sync task', 'due_date' => '2026-05-31']);
        $this->enableGoogleCalendar();
        $this->fakeGoogleCalendarEvent('google-event-1');

        $this->actingAs($user)
            ->post(route('settings.integrations.google.sync'))
            ->assertRedirect();

        $this->assertDatabaseHas('calendar_event_mappings', [
            'user_id' => $user->id,
            'task_id' => $task->id,
            'provider_event_id' => 'google-event-1',
        ]);
        $this->assertDatabaseHas('calendar_sync_logs', [
            'calendar_connection_id' => $connection->id,
            'status' => 'success',
        ]);
    }

    public function test_duplicate_sync_updates_existing_mapping_instead_of_creating_duplicate(): void
    {
        [$user, $workspace, $project] = $this->context('duplicate');
        $this->connection($user, $workspace);
        $this->task($workspace, $project, $user, ['title' => 'Duplicate safe task', 'due_date' => '2026-05-31']);
        $this->enableGoogleCalendar();
        $this->fakeGoogleCalendarEvent('google-event-1');

        $this->actingAs($user)->post(route('settings.integrations.google.sync'));
        $this->actingAs($user)->post(route('settings.integrations.google.sync'));

        $this->assertSame(1, CalendarEventMapping::where('provider_event_id', 'google-event-1')->count());
    }

    public function test_sync_command_runs_safely_when_disabled(): void
    {
        config(['services.google_calendar.enabled' => false]);

        $this->assertSame(0, Artisan::call('miriam:sync-google-calendar'));
        $this->assertStringContainsString('disabled or not configured', Artisan::output());
    }

    public function test_planner_includes_external_google_calendar_events(): void
    {
        [$user, $workspace] = $this->context('planner-google');
        $this->connection($user, $workspace);
        CalendarEventMapping::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_event_id' => 'external-event-1',
            'last_synced_at' => now(),
            'metadata' => [
                'source' => 'google_external',
                'title' => 'External planning hold',
                'date' => '2026-05-30',
            ],
        ]);

        $this->actingAs($user)
            ->get(route('planner.index', ['month' => '2026-05']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('calendar.events.0.type', 'google_event')
                ->where('calendar.events.0.title', 'External planning hold')
            );
    }

    private function enableGoogleCalendar(): void
    {
        config([
            'services.google_calendar.enabled' => true,
            'services.google_calendar.client_id' => 'test-client-id',
            'services.google_calendar.client_secret' => 'test-client-secret',
            'services.google_calendar.redirect_uri' => 'https://example.com/settings/integrations/google/callback',
        ]);
    }

    private function fakeGoogleCalendarEvent(string $eventId): void
    {
        Http::fake(function ($request) use ($eventId) {
            if ($request->method() === 'GET') {
                return Http::response(['items' => []]);
            }

            return Http::response([
                'id' => $eventId,
                'summary' => 'Synced task',
                'start' => ['date' => '2026-05-31'],
                'organizer' => ['email' => 'primary'],
            ]);
        });
    }

    private function connection(User $user, Workspace $workspace): CalendarConnection
    {
        return CalendarConnection::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'provider' => 'google',
            'provider_account_email' => $user->email,
            'access_token' => 'fixture-access-value',
            'refresh_token' => 'fixture-refresh-value',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['https://www.googleapis.com/auth/calendar.events'],
            'is_active' => true,
        ]);
    }

    private function context(string $slug): array
    {
        $user = User::factory()->create(['email' => "{$slug}@example.com"]);
        $workspace = Workspace::create([
            'name' => ucfirst($slug).' Workspace',
            'slug' => "{$slug}-workspace",
            'created_by' => $user->id,
        ]);
        $team = Team::create([
            'workspace_id' => $workspace->id,
            'name' => ucfirst($slug).' Team',
            'slug' => "{$slug}-team",
        ]);
        $project = Project::create([
            'workspace_id' => $workspace->id,
            'team_id' => $team->id,
            'owner_id' => $user->id,
            'name' => ucfirst($slug).' Project',
            'slug' => "{$slug}-project",
            'status' => 'active',
            'visibility' => 'workspace',
        ]);

        $workspace->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $team->users()->attach($user->id, ['role' => 'lead', 'joined_at' => now()]);

        return [$user, $workspace, $project];
    }

    private function task(Workspace $workspace, Project $project, User $user, array $overrides = []): Task
    {
        return Task::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Calendar sync task',
            'status' => 'todo',
            'priority' => 'medium',
            'assignee_id' => $user->id,
            'reporter_id' => $user->id,
            ...$overrides,
        ]);
    }
}
