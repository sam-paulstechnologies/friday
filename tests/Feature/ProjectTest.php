<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_index_loads(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('projects.index'));

        $response->assertOk();
    }

    public function test_project_can_be_created(): void
    {
        [$user, $workspace, $team] = $this->projectContext();

        $response = $this->actingAs($user)->post(route('projects.store'), [
            'workspace_id' => $workspace->id,
            'team_id' => $team->id,
            'name' => 'Customer Portal',
            'description' => 'Build a customer-facing account portal.',
            'status' => 'active',
            'visibility' => 'team',
            'start_date' => '2026-06-01',
            'due_date' => '2026-07-01',
            'color' => '#2563eb',
        ]);

        $project = Project::where('name', 'Customer Portal')->first();

        $this->assertNotNull($project);
        $this->assertSame($user->id, $project->owner_id);
        $this->assertSame('customer-portal', $project->slug);
        $response->assertRedirect(route('projects.show', $project));
    }

    public function test_project_can_be_updated(): void
    {
        [$user, $workspace, $team] = $this->projectContext();
        $project = Project::create([
            'workspace_id' => $workspace->id,
            'team_id' => $team->id,
            'owner_id' => $user->id,
            'name' => 'Customer Portal',
            'slug' => 'customer-portal',
            'status' => 'active',
            'visibility' => 'team',
        ]);

        $response = $this->actingAs($user)->patch(route('projects.update', $project), [
            'workspace_id' => $workspace->id,
            'team_id' => $team->id,
            'name' => 'Customer Portal Refresh',
            'description' => 'Updated project direction.',
            'status' => 'on_hold',
            'visibility' => 'workspace',
            'start_date' => '2026-06-01',
            'due_date' => '2026-08-01',
            'color' => '#7c3aed',
        ]);

        $project->refresh();

        $this->assertSame('Customer Portal Refresh', $project->name);
        $this->assertSame('on_hold', $project->status);
        $this->assertSame('workspace', $project->visibility);
        $this->assertSame('customer-portal-refresh', $project->slug);
        $response->assertRedirect(route('projects.show', $project));
    }

    public function test_project_can_be_archived(): void
    {
        [$user, $workspace, $team] = $this->projectContext();
        $project = Project::create([
            'workspace_id' => $workspace->id,
            'team_id' => $team->id,
            'owner_id' => $user->id,
            'name' => 'Customer Portal',
            'slug' => 'customer-portal',
            'status' => 'active',
            'visibility' => 'team',
        ]);

        $response = $this->actingAs($user)->patch(route('projects.archive', $project));

        $this->assertSame('archived', $project->refresh()->status);
        $response->assertRedirect(route('projects.index'));
    }

    public function test_user_cannot_view_another_users_project(): void
    {
        [$owner, $workspace, $team] = $this->isolatedProjectContext('owner');
        [$intruder] = $this->isolatedProjectContext('intruder');
        $project = $this->project($owner, $workspace, $team);

        $this->actingAs($intruder)
            ->get(route('projects.show', $project))
            ->assertForbidden();
    }

    public function test_user_cannot_update_or_archive_another_users_project(): void
    {
        [$owner, $workspace, $team] = $this->isolatedProjectContext('owner');
        [$intruder, $intruderWorkspace, $intruderTeam] = $this->isolatedProjectContext('intruder');
        $project = $this->project($owner, $workspace, $team);

        $this->actingAs($intruder)
            ->patch(route('projects.update', $project), [
                'workspace_id' => $intruderWorkspace->id,
                'team_id' => $intruderTeam->id,
                'name' => 'Unauthorized Project',
                'status' => 'on_hold',
                'visibility' => 'workspace',
            ])
            ->assertForbidden();

        $this->actingAs($intruder)
            ->patch(route('projects.archive', $project))
            ->assertForbidden();

        $project->refresh();
        $this->assertNotSame('Unauthorized Project', $project->name);
        $this->assertSame('active', $project->status);
    }

    public function test_project_forms_do_not_show_other_users_workspace_or_team_options(): void
    {
        [$user, $workspace, $team] = $this->isolatedProjectContext('owner');
        [$otherUser, $otherWorkspace, $otherTeam] = $this->isolatedProjectContext('other');
        $project = $this->project($user, $workspace, $team);

        $this->actingAs($user)
            ->get(route('projects.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Projects/Create')
                ->has('workspaces', 1)
                ->where('workspaces.0.id', $workspace->id)
                ->has('teams', 1)
                ->where('teams.0.id', $team->id)
            );

        $this->actingAs($user)
            ->get(route('projects.edit', $project))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Projects/Edit')
                ->where('workspaces.0.id', $workspace->id)
                ->where('teams.0.id', $team->id)
            );

        $this->assertNotSame($otherWorkspace->id, $workspace->id);
        $this->assertNotSame($otherTeam->id, $team->id);
        $this->assertNotSame($otherUser->id, $user->id);
    }

    public function test_archived_project_is_hidden_from_active_index_and_can_be_restored(): void
    {
        [$user, $workspace, $team] = $this->projectContext();
        $project = $this->project($user, $workspace, $team);

        $this->actingAs($user)->patch(route('projects.archive', $project))->assertRedirect(route('projects.index'));

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'status' => 'archived']);
        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('projects', 0));

        $this->actingAs($user)->patch(route('projects.restore', $project))->assertRedirect(route('projects.show', $project));

        $this->assertSame('active', $project->refresh()->status);
    }

    public function test_user_cannot_restore_another_users_project(): void
    {
        [$owner, $workspace, $team] = $this->isolatedProjectContext('owner');
        [$intruder] = $this->isolatedProjectContext('intruder');
        $project = $this->project($owner, $workspace, $team);
        $project->update(['status' => 'archived']);

        $this->actingAs($intruder)->patch(route('projects.restore', $project))->assertForbidden();

        $this->assertSame('archived', $project->refresh()->status);
    }

    public function test_authorized_user_can_add_and_remove_workspace_project_member(): void
    {
        [$user, $workspace, $team] = $this->projectContext();
        $member = User::factory()->create(['name' => 'Workspace Member']);
        $workspace->users()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);
        $project = $this->project($user, $workspace, $team);

        $this->actingAs($user)
            ->post(route('projects.members.store', $project), [
                'user_id' => $member->id,
                'role' => 'contributor',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $member->id,
            'role' => 'contributor',
        ]);
        $this->assertDatabaseHas('project_activities', [
            'project_id' => $project->id,
            'action' => 'member_added',
        ]);

        $this->actingAs($user)
            ->delete(route('projects.members.destroy', [$project, $member]))
            ->assertRedirect();

        $this->assertDatabaseMissing('project_members', [
            'project_id' => $project->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_non_workspace_user_cannot_be_added_to_project(): void
    {
        [$user, $workspace, $team] = $this->projectContext();
        $outsider = User::factory()->create();
        $project = $this->project($user, $workspace, $team);

        $this->actingAs($user)
            ->post(route('projects.members.store', $project), ['user_id' => $outsider->id])
            ->assertInvalid(['user_id']);

        $this->assertDatabaseMissing('project_members', [
            'project_id' => $project->id,
            'user_id' => $outsider->id,
        ]);
    }

    public function test_user_cannot_see_or_add_users_from_another_workspace(): void
    {
        [$user, $workspace, $team] = $this->isolatedProjectContext('owner');
        [$otherUser] = $this->isolatedProjectContext('other');
        $project = $this->project($user, $workspace, $team);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('availableMembers', []));

        $this->actingAs($user)
            ->post(route('projects.members.store', $project), ['user_id' => $otherUser->id])
            ->assertInvalid(['user_id']);
    }

    public function test_project_member_can_access_project(): void
    {
        [$owner, $workspace, $team] = $this->projectContext();
        $member = User::factory()->create();
        $workspace->users()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);
        $project = $this->project($owner, $workspace, $team);
        $project->members()->attach($member->id, ['role' => 'member', 'added_by' => $owner->id]);

        $this->actingAs($member)
            ->get(route('projects.show', $project))
            ->assertOk();
    }

    public function test_project_show_includes_recent_task_collaboration_activity(): void
    {
        [$user, $workspace, $team] = $this->projectContext();
        $project = $this->project($user, $workspace, $team);
        $task = Task::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Launch checklist',
            'status' => 'todo',
            'priority' => 'medium',
            'assignee_id' => $user->id,
            'reporter_id' => $user->id,
        ]);

        $task->comments()->create([
            'user_id' => $user->id,
            'body' => 'Latest collaboration note.',
        ]);

        $this->actingAs($user)
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('collaborationActivity.0.kind', 'comment')
                ->where('collaborationActivity.0.action', 'comment_added')
                ->where('collaborationActivity.0.task_title', 'Launch checklist')
                ->where('collaborationActivity.0.description', 'Latest collaboration note.')
            );
    }

    private function projectContext(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'TaskFlow Workspace',
            'slug' => 'taskflow-workspace',
            'created_by' => $user->id,
        ]);
        $team = Team::create([
            'workspace_id' => $workspace->id,
            'name' => 'Product Team',
            'slug' => 'product-team',
        ]);

        $workspace->users()->attach($user->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);
        $team->users()->attach($user->id, [
            'role' => 'lead',
            'joined_at' => now(),
        ]);

        return [$user, $workspace, $team];
    }

    private function isolatedProjectContext(string $slug): array
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

        $workspace->users()->attach($user->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);
        $team->users()->attach($user->id, [
            'role' => 'lead',
            'joined_at' => now(),
        ]);

        return [$user, $workspace, $team];
    }

    private function project(User $user, Workspace $workspace, Team $team): Project
    {
        return Project::create([
            'workspace_id' => $workspace->id,
            'team_id' => $team->id,
            'owner_id' => $user->id,
            'name' => $workspace->name.' Project',
            'slug' => $workspace->slug.'-project',
            'status' => 'active',
            'visibility' => 'workspace',
        ]);
    }
}
