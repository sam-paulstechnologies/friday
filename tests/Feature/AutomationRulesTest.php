<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Automation\AutomationRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AutomationRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-05-30 09:00:00');
    }

    public function test_owner_and_admin_can_view_automation_settings(): void
    {
        [$owner, $workspace] = $this->context('owner');
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $workspace->users()->attach($admin->id, ['role' => 'admin', 'joined_at' => now()]);

        $this->actingAs($owner)
            ->get(route('settings.automations.index', ['workspace_id' => $workspace->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Automations/Index')
                ->where('workspace.id', $workspace->id)
                ->has('rules', 6)
            );

        $this->actingAs($admin)
            ->get(route('settings.automations.index', ['workspace_id' => $workspace->id]))
            ->assertOk();
    }

    public function test_member_and_viewer_cannot_manage_automation_settings(): void
    {
        [, $workspace] = $this->context('owner');
        $member = User::factory()->create(['email' => 'member@example.com']);
        $viewer = User::factory()->create(['email' => 'viewer@example.com']);
        $workspace->users()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);
        $workspace->users()->attach($viewer->id, ['role' => 'viewer', 'joined_at' => now()]);

        $this->actingAs($member)
            ->get(route('settings.automations.index', ['workspace_id' => $workspace->id]))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('settings.automations.store'), [
                'workspace_id' => $workspace->id,
                'name' => 'Viewer rule',
                'trigger_type' => 'task_overdue',
                'action_type' => 'notify_assignee',
                'is_active' => true,
            ])
            ->assertForbidden();
    }

    public function test_automation_rule_can_be_created_updated_and_disabled(): void
    {
        [$owner, $workspace] = $this->context('owner');

        $this->actingAs($owner)
            ->post(route('settings.automations.store'), [
                'workspace_id' => $workspace->id,
                'name' => 'Custom overdue nudge',
                'description' => 'Notify people about overdue tasks.',
                'trigger_type' => 'task_overdue',
                'action_type' => 'notify_assignee',
                'conditions' => [],
                'action_payload' => [],
                'is_active' => true,
            ])
            ->assertRedirect();

        $rule = AutomationRule::where('name', 'Custom overdue nudge')->firstOrFail();

        $this->actingAs($owner)
            ->patch(route('settings.automations.update', $rule), [
                'name' => 'Updated overdue nudge',
                'description' => 'Updated.',
                'trigger_type' => 'task_overdue',
                'action_type' => 'notify_assignee',
                'conditions' => [],
                'action_payload' => [],
                'is_active' => true,
            ])
            ->assertRedirect();

        $this->actingAs($owner)
            ->patch(route('settings.automations.toggle', $rule), ['is_active' => false])
            ->assertRedirect();

        $this->assertFalse($rule->refresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['workspace_id' => $workspace->id, 'action' => 'automation_rule_toggled']);
    }

    public function test_overdue_and_due_today_automations_create_notifications_once(): void
    {
        [$owner, $workspace, , $project, $assignee] = $this->context('owner');
        $this->task($workspace, $project, $owner, $assignee, ['title' => 'Overdue task', 'due_date' => now()->subDay()]);
        $this->task($workspace, $project, $owner, $assignee, ['title' => 'Due today task', 'due_date' => now()]);
        $service = app(AutomationRuleService::class);
        $service->ensurePresets($workspace, $owner->id);
        $this->activateOnly($workspace, ['task_overdue', 'task_due_today']);

        $service->run($workspace);
        $service->run($workspace);

        $this->assertSame(1, $assignee->notifications()->where('data->event_type', 'automation_overdue')->count());
        $this->assertSame(1, $assignee->notifications()->where('data->event_type', 'automation_due_today')->count());
        $this->assertSame(2, AutomationRun::count());
        $this->assertDatabaseHas('audit_logs', ['workspace_id' => $workspace->id, 'action' => 'automation_notification_created']);
    }

    public function test_task_completed_automation_notifies_relevant_participant(): void
    {
        [$owner, $workspace, , $project, $assignee] = $this->context('owner');
        $this->task($workspace, $project, $owner, $assignee, [
            'title' => 'Completed task',
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        $service = app(AutomationRuleService::class);
        $service->ensurePresets($workspace, $owner->id);

        $service->run($workspace);

        $this->assertSame(1, $owner->notifications()->where('data->event_type', 'automation_task_completed')->count());
        $this->assertSame(0, $assignee->notifications()->where('data->event_type', 'automation_task_completed')->count());
    }

    public function test_project_at_risk_and_morning_briefing_automations_are_scoped(): void
    {
        [$owner, $workspace, , $project, $assignee] = $this->context('owner');
        [$otherOwner, $otherWorkspace, , $otherProject, $otherAssignee] = $this->context('other');
        $this->task($workspace, $project, $owner, $assignee, ['due_date' => now()->subDay()]);
        $this->task($otherWorkspace, $otherProject, $otherOwner, $otherAssignee, ['due_date' => now()->subDay()]);
        $service = app(AutomationRuleService::class);
        $service->ensurePresets($workspace, $owner->id);

        $service->run($workspace);

        $this->assertGreaterThan(0, $owner->notifications()->where('data->event_type', 'automation_project_at_risk')->count());
        $this->assertGreaterThan(0, $owner->notifications()->where('data->event_type', 'automation_morning_briefing')->count());
        $this->assertSame(0, $otherOwner->notifications()->count());
    }

    public function test_automation_command_runs_successfully(): void
    {
        [$owner, $workspace, , $project, $assignee] = $this->context('owner');
        $this->task($workspace, $project, $owner, $assignee, ['due_date' => now()->subDay()]);

        $this->artisan('miriam:run-automations', ['--workspace_id' => $workspace->id])
            ->assertSuccessful();

        $this->assertGreaterThan(0, AutomationRun::count());
    }

    public function test_user_cannot_manage_another_workspace_automation_rule(): void
    {
        [$owner, $workspace] = $this->context('owner');
        [$intruder] = $this->context('intruder');
        app(AutomationRuleService::class)->ensurePresets($workspace, $owner->id);
        $rule = AutomationRule::where('workspace_id', $workspace->id)->firstOrFail();

        $this->actingAs($intruder)
            ->patch(route('settings.automations.toggle', $rule), ['is_active' => false])
            ->assertForbidden();

        $this->assertTrue($rule->refresh()->is_active);
    }

    private function context(string $slug): array
    {
        $owner = User::factory()->create(['email' => "{$slug}@example.com", 'name' => ucfirst($slug).' Owner']);
        $assignee = User::factory()->create(['email' => "{$slug}-assignee@example.com", 'name' => ucfirst($slug).' Assignee']);
        $workspace = Workspace::create(['name' => ucfirst($slug).' Workspace', 'slug' => "{$slug}-workspace", 'created_by' => $owner->id]);
        $team = Team::create(['workspace_id' => $workspace->id, 'name' => ucfirst($slug).' Team', 'slug' => "{$slug}-team"]);
        $project = Project::create([
            'workspace_id' => $workspace->id,
            'team_id' => $team->id,
            'owner_id' => $owner->id,
            'name' => ucfirst($slug).' Project',
            'slug' => "{$slug}-project",
            'status' => 'active',
            'visibility' => 'workspace',
            'due_date' => now()->addDays(3),
        ]);

        foreach ([$owner, $assignee] as $user) {
            $workspace->users()->attach($user->id, ['role' => $user->is($owner) ? 'owner' : 'member', 'joined_at' => now()]);
            $team->users()->attach($user->id, ['role' => $user->is($owner) ? 'lead' : 'member', 'joined_at' => now()]);
        }

        return [$owner, $workspace, $team, $project, $assignee];
    }

    private function task(Workspace $workspace, Project $project, User $owner, User $assignee, array $overrides = []): Task
    {
        return Task::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Automation task',
            'status' => 'todo',
            'priority' => 'medium',
            'assignee_id' => $assignee->id,
            'reporter_id' => $owner->id,
            'due_date' => now(),
            ...$overrides,
        ]);
    }

    private function activateOnly(Workspace $workspace, array $triggerTypes): void
    {
        AutomationRule::query()
            ->where('workspace_id', $workspace->id)
            ->update(['is_active' => false]);

        AutomationRule::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('trigger_type', $triggerTypes)
            ->update(['is_active' => true]);
    }
}
