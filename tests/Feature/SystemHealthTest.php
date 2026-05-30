<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_check_command_runs_without_exposing_secrets(): void
    {
        config([
            'services.google_calendar.enabled' => true,
            'services.google_calendar.client_id' => 'calendar-client-id-secret',
            'services.google_calendar.client_secret' => 'calendar-client-secret',
            'services.google_calendar.redirect_uri' => 'https://example.com/callback',
            'services.ai_assistant.enabled' => true,
            'services.ai_assistant.provider' => 'openai',
            'services.ai_assistant.api_key' => 'ai-api-key-secret',
        ]);

        $exitCode = Artisan::call('miriam:health-check', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Google Calendar', $output);
        $this->assertStringContainsString('AI Assistant', $output);
        $this->assertStringNotContainsString('calendar-client-id-secret', $output);
        $this->assertStringNotContainsString('calendar-client-secret', $output);
        $this->assertStringNotContainsString('ai-api-key-secret', $output);
    }

    public function test_system_health_page_is_owner_and_admin_only(): void
    {
        [$owner, $workspace] = $this->workspaceContext('system-owner', 'owner');
        $admin = User::factory()->create(['email' => 'system-admin@example.com']);
        $member = User::factory()->create(['email' => 'system-member@example.com']);
        $viewer = User::factory()->create(['email' => 'system-viewer@example.com']);

        $workspace->users()->attach($admin->id, ['role' => 'admin', 'joined_at' => now()]);
        $workspace->users()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);
        $workspace->users()->attach($viewer->id, ['role' => 'viewer', 'joined_at' => now()]);

        $this->actingAs($owner)
            ->get(route('settings.system-health.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/SystemHealth/Index')
                ->has('health.overall')
                ->has('health.checks')
            );

        $this->actingAs($admin)
            ->get(route('settings.system-health.index'))
            ->assertOk();

        $this->actingAs($member)
            ->get(route('settings.system-health.index'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get(route('settings.system-health.index'))
            ->assertForbidden();
    }

    public function test_readiness_docs_exist_and_backups_are_ignored(): void
    {
        $this->assertFileExists(base_path('docs/UAT_CHECKLIST.md'));
        $this->assertFileExists(base_path('docs/DEPLOYMENT_CHECKLIST.md'));
        $this->assertFileExists(base_path('docs/SMOKE_TEST.md'));
        $this->assertStringContainsString('backups/', file_get_contents(base_path('.gitignore')));
    }

    private function workspaceContext(string $slug, string $role): array
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

        $workspace->users()->attach($user->id, ['role' => $role, 'joined_at' => now()]);
        $team->users()->attach($user->id, ['role' => 'lead', 'joined_at' => now()]);

        return [$user, $workspace, $team];
    }
}
