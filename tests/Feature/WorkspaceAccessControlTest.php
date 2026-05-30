<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Goal;
use App\Models\Portfolio;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\TaskFlowNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkspaceAccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_workspace_settings(): void
    {
        [$owner, $workspace] = $this->workspaceContext('owner', 'owner');

        $this->actingAs($owner)
            ->get(route('settings.workspace.edit', ['workspace_id' => $workspace->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Workspace/Edit')
                ->where('workspace.id', $workspace->id)
                ->where('members.0.role', 'owner')
            );
    }

    public function test_admin_can_add_member_and_change_role_with_audit_log(): void
    {
        [$owner, $workspace] = $this->workspaceContext('owner', 'owner');
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $newMember = User::factory()->create(['email' => 'new@example.com']);
        $workspace->users()->attach($admin->id, ['role' => 'admin', 'joined_at' => now()]);

        $this->actingAs($admin)
            ->post(route('settings.workspace.members.store'), [
                'workspace_id' => $workspace->id,
                'email' => $newMember->email,
                'role' => 'viewer',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('workspace_users', [
            'workspace_id' => $workspace->id,
            'user_id' => $newMember->id,
            'role' => 'viewer',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'actor_id' => $admin->id,
            'action' => 'workspace_member_added',
        ]);

        $this->actingAs($admin)
            ->patch(route('settings.workspace.members.update', $newMember), [
                'workspace_id' => $workspace->id,
                'role' => 'member',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('workspace_users', [
            'workspace_id' => $workspace->id,
            'user_id' => $newMember->id,
            'role' => 'member',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'actor_id' => $admin->id,
            'action' => 'workspace_role_changed',
        ]);
        $this->assertNotNull($owner);
    }

    public function test_member_cannot_change_roles(): void
    {
        [$owner, $workspace] = $this->workspaceContext('owner', 'owner');
        $member = User::factory()->create(['email' => 'member@example.com']);
        $viewer = User::factory()->create(['email' => 'viewer@example.com']);
        $workspace->users()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);
        $workspace->users()->attach($viewer->id, ['role' => 'viewer', 'joined_at' => now()]);

        $this->actingAs($member)
            ->patch(route('settings.workspace.members.update', $viewer), [
                'workspace_id' => $workspace->id,
                'role' => 'admin',
            ])
            ->assertForbidden();

        $this->assertSame('viewer', $viewer->workspaceRole($workspace->id));
        $this->assertNotNull($owner);
    }

    public function test_last_owner_cannot_be_removed_or_demoted(): void
    {
        [$owner, $workspace] = $this->workspaceContext('owner', 'owner');

        $this->actingAs($owner)
            ->patch(route('settings.workspace.members.update', $owner), [
                'workspace_id' => $workspace->id,
                'role' => 'admin',
            ])
            ->assertStatus(422);

        $this->actingAs($owner)
            ->delete(route('settings.workspace.members.destroy', $owner), ['workspace_id' => $workspace->id])
            ->assertStatus(422);

        $this->assertSame('owner', $owner->workspaceRole($workspace->id));
    }

    public function test_viewer_can_view_allowed_data_but_cannot_write_comment_or_complete(): void
    {
        [$owner, $workspace, $team, $project] = $this->workspaceContext('owner', 'owner');
        $viewer = User::factory()->create(['email' => 'viewer@example.com']);
        $workspace->users()->attach($viewer->id, ['role' => 'viewer', 'joined_at' => now()]);
        $task = $this->task($workspace, $project, $owner);

        $this->actingAs($viewer)->get(route('projects.show', $project))->assertOk();
        $this->actingAs($viewer)->get(route('tasks.show', $task))->assertOk();

        $this->actingAs($viewer)
            ->post(route('tasks.comments.store', $task), ['body' => 'Read-only users cannot comment.'])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->patch(route('tasks.complete', $task))
            ->assertForbidden();

        $this->assertSame('todo', $task->refresh()->status);
        $this->assertNotNull($team);
    }

    public function test_user_cannot_access_another_workspace_leadership_or_settings_data(): void
    {
        [$owner, $workspace, , $project] = $this->workspaceContext('owner', 'owner');
        [$intruder, $otherWorkspace] = $this->workspaceContext('intruder', 'owner');
        $goal = Goal::create(['workspace_id' => $workspace->id, 'owner_id' => $owner->id, 'title' => 'Private goal', 'status' => 'on_track']);
        $portfolio = Portfolio::create(['workspace_id' => $workspace->id, 'owner_id' => $owner->id, 'name' => 'Private portfolio', 'slug' => 'private-portfolio', 'status' => 'active']);
        $this->task($workspace, $project, $owner);

        $this->actingAs($intruder)->get(route('goals.show', $goal))->assertForbidden();
        $this->actingAs($intruder)->get(route('portfolios.show', $portfolio))->assertForbidden();
        $this->actingAs($intruder)
            ->get(route('settings.workspace.edit', ['workspace_id' => $workspace->id]))
            ->assertNotFound();

        $this->actingAs($intruder)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total_open_tasks', 0)
                ->where('summary.active_goals', 0)
            );

        $this->assertNotSame($workspace->id, $otherWorkspace->id);
    }

    public function test_notifications_remain_recipient_scoped(): void
    {
        [$owner] = $this->workspaceContext('owner', 'owner');
        [$otherUser] = $this->workspaceContext('other', 'owner');
        $owner->notify(new TaskFlowNotification('Owner notice', 'Message'));
        $otherUser->notify(new TaskFlowNotification('Other notice', 'Message'));

        $this->actingAs($owner)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('unreadNotifications.0.title', 'Owner notice')
                ->missing('unreadNotifications.1')
            );
    }

    public function test_archiving_work_creates_audit_log(): void
    {
        [$owner, $workspace, , $project] = $this->workspaceContext('owner', 'owner');
        $task = $this->task($workspace, $project, $owner);

        $this->actingAs($owner)->patch(route('tasks.complete', $task))->assertRedirect();
        $this->actingAs($owner)->patch(route('projects.archive', $project))->assertRedirect(route('projects.index'));

        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'actor_id' => $owner->id,
            'action' => 'task_completed',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'actor_id' => $owner->id,
            'action' => 'project_archived',
        ]);
    }

    private function workspaceContext(string $slug, string $role): array
    {
        $user = User::factory()->create([
            'email' => "{$slug}@example.com",
            'name' => ucfirst($slug).' User',
        ]);
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

        $workspace->users()->attach($user->id, ['role' => $role, 'joined_at' => now()]);
        $team->users()->attach($user->id, ['role' => 'lead', 'joined_at' => now()]);

        return [$user, $workspace, $team, $project];
    }

    private function task(Workspace $workspace, Project $project, User $owner): Task
    {
        return Task::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Access controlled task',
            'status' => 'todo',
            'priority' => 'medium',
            'assignee_id' => $owner->id,
            'reporter_id' => $owner->id,
        ]);
    }
}
