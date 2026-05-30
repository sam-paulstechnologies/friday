<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Portfolio;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-05-22 09:00:00');
    }

    public function test_workload_page_loads(): void
    {
        [$user] = $this->workloadContext();

        $response = $this->actingAs($user)->get(route('workload.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Workload/Index')
            ->has('summary')
            ->has('assigneeWorkloads')
            ->has('unassignedTasks')
            ->has('portfolioWorkloads')
            ->has('projectWorkloads')
            ->has('weeklyBuckets')
        );
    }

    public function test_workload_score_calculation_works(): void
    {
        [$user, $workspace, $area, , $assignee, $portfolio, $project] = $this->workloadContext();

        $this->task($workspace, $area, $portfolio, $project, [
            'assignee_id' => $assignee->id,
            'priority' => 'urgent',
            'status' => 'blocked',
            'due_date' => now()->subDay()->toDateString(),
        ]);
        $this->task($workspace, $area, $portfolio, $project, [
            'assignee_id' => $assignee->id,
            'priority' => 'high',
            'due_date' => now()->toDateString(),
        ]);
        $this->task($workspace, $area, $portfolio, $project, [
            'assignee_id' => $assignee->id,
            'priority' => 'medium',
            'due_date' => null,
        ]);

        $response = $this->actingAs($user)->get(route('workload.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('assigneeWorkloads.0.name', $assignee->name)
            ->where('assigneeWorkloads.0.workload_score', 15)
            ->where('assigneeWorkloads.0.classification', 'Busy')
            ->where('assigneeWorkloads.0.blocked_tasks', 1)
            ->where('assigneeWorkloads.0.no_due_date_tasks', 1)
        );
    }

    public function test_unassigned_tasks_are_counted(): void
    {
        [$user, $workspace, $area, , , $portfolio, $project] = $this->workloadContext();

        $task = $this->task($workspace, $area, $portfolio, $project, [
            'title' => 'Needs owner',
            'assignee_id' => null,
            'priority' => 'urgent',
        ]);

        $response = $this->actingAs($user)->get(route('workload.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('summary.total_unassigned_tasks', 1)
            ->where('unassignedTasks.0.id', $task->id)
            ->where('unassignedTasks.0.title', 'Needs owner')
            ->where('assigneeWorkloads.0.name', 'Unassigned')
        );
    }

    public function test_overloaded_classification_works(): void
    {
        [$user, $workspace, $area, , $assignee, $portfolio, $project] = $this->workloadContext();

        for ($i = 1; $i <= 5; $i++) {
            $this->task($workspace, $area, $portfolio, $project, [
                'title' => "Urgent task {$i}",
                'assignee_id' => $assignee->id,
                'priority' => 'urgent',
            ]);
        }

        $response = $this->actingAs($user)->get(route('workload.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('assigneeWorkloads.0.workload_score', 25)
            ->where('assigneeWorkloads.0.classification', 'Overloaded')
            ->where('summary.overloaded_people', 1)
        );
    }

    public function test_filters_affect_metrics(): void
    {
        [$user, $workspace, $area, , $assignee, $portfolio, $project] = $this->workloadContext();
        $otherAssignee = User::factory()->create(['name' => 'Other Assignee']);
        $workspace->users()->attach($otherAssignee->id, [
            'role' => 'member',
            'joined_at' => now(),
        ]);

        $this->task($workspace, $area, $portfolio, $project, [
            'assignee_id' => $assignee->id,
            'priority' => 'urgent',
            'due_date' => now()->subDay()->toDateString(),
        ]);
        $this->task($workspace, $area, $portfolio, $project, [
            'assignee_id' => $otherAssignee->id,
            'priority' => 'low',
            'due_date' => now()->addDays(5)->toDateString(),
        ]);

        $response = $this->actingAs($user)->get(route('workload.index', [
            'assignee_id' => $assignee->id,
            'priority' => 'urgent',
            'due_bucket' => 'overdue',
        ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('summary.total_open_tasks', 1)
            ->where('summary.total_overdue_tasks', 1)
            ->where('assigneeWorkloads.0.name', $assignee->name)
            ->where('assigneeWorkloads.0.workload_score', 7)
        );
    }

    private function workloadContext(): array
    {
        $user = User::factory()->create(['name' => 'Miriam']);
        $assignee = User::factory()->create(['name' => 'Friday Lead']);
        $workspace = Workspace::create([
            'name' => 'Miriam Workspace',
            'slug' => 'miriam-workspace',
            'created_by' => $user->id,
        ]);
        $team = Team::create([
            'workspace_id' => $workspace->id,
            'name' => 'Launch Team',
            'slug' => 'launch-team',
        ]);
        $area = Area::create([
            'name' => 'Business',
            'slug' => 'business',
            'position' => 1,
            'is_active' => true,
        ]);
        $portfolio = Portfolio::create([
            'workspace_id' => $workspace->id,
            'area_id' => $area->id,
            'name' => 'Phase 12',
            'slug' => 'phase-12',
            'status' => 'active',
            'position' => 1,
        ]);
        $project = Project::create([
            'workspace_id' => $workspace->id,
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'name' => 'Workload Planning',
            'slug' => 'workload-planning',
            'status' => 'active',
            'visibility' => 'workspace',
        ]);

        foreach ([$user, $assignee] as $member) {
            $workspace->users()->attach($member->id, [
                'role' => $member->is($user) ? 'owner' : 'member',
                'joined_at' => now(),
            ]);
            $team->users()->attach($member->id, [
                'role' => $member->is($user) ? 'lead' : 'member',
                'joined_at' => now(),
            ]);
        }

        return [$user, $workspace, $area, $team, $assignee, $portfolio, $project];
    }

    private function task(Workspace $workspace, Area $area, Portfolio $portfolio, Project $project, array $overrides = []): Task
    {
        return Task::create([
            'workspace_id' => $workspace->id,
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'project_id' => $project->id,
            'title' => 'Workload task',
            'status' => 'todo',
            'priority' => 'medium',
            'due_date' => now()->addDay()->toDateString(),
            ...$overrides,
        ]);
    }
}
