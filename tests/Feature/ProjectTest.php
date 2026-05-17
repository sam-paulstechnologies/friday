<?php

namespace Tests\Feature;

use App\Models\Project;
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
}
