<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceTeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_belong_to_workspaces_with_roles(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'TaskFlow Workspace',
            'slug' => 'taskflow-workspace',
            'created_by' => $user->id,
        ]);

        $workspace->users()->attach($user->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        $this->assertTrue($user->workspaces()->whereKey($workspace->id)->exists());
        $this->assertSame('owner', $workspace->users()->first()->pivot->role);
    }

    public function test_teams_belong_to_workspaces_and_have_users(): void
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

        $team->users()->attach($user->id, [
            'role' => 'lead',
            'joined_at' => now(),
        ]);

        $this->assertTrue($workspace->teams()->whereKey($team->id)->exists());
        $this->assertTrue($user->teams()->whereKey($team->id)->exists());
        $this->assertSame('lead', $team->users()->first()->pivot->role);
    }
}
