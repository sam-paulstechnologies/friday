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

class ReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_page_loads(): void
    {
        [$user] = $this->reportContext();

        $response = $this->actingAs($user)->get(route('reports.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Reports/Index')
            ->has('summary')
            ->has('portfolioMetrics')
            ->has('projectMetrics')
            ->has('taskHealth')
        );
    }

    public function test_portfolio_metrics_count_launch_portfolio_tasks(): void
    {
        [$user, $workspace, $area] = $this->reportContext();
        $sayara = $this->portfolio($workspace, $area, 'SayaraForce', 1);
        $church = $this->portfolio($workspace, $area, 'ChurchForce', 2);
        $sayaraProject = $this->project($workspace, $area, $sayara, 'SayaraForce Launch');
        $churchProject = $this->project($workspace, $area, $church, 'ChurchForce Launch');

        $this->makeTasks($workspace, $area, $sayara, $sayaraProject, 119);
        $this->makeTasks($workspace, $area, $church, $churchProject, 107);

        Task::where('portfolio_id', $sayara->id)->limit(19)->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('reports.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('launchReadiness.0.name', 'SayaraForce')
            ->where('launchReadiness.0.total_launch_tasks', 119)
            ->where('launchReadiness.0.completed_tasks', 19)
            ->where('launchReadiness.0.open_tasks', 100)
            ->where('launchReadiness.1.name', 'ChurchForce')
            ->where('launchReadiness.1.total_launch_tasks', 107)
            ->where('portfolioMetrics.0.total_tasks', 119)
            ->where('portfolioMetrics.1.total_tasks', 107)
        );
    }

    public function test_reports_filters_update_task_metrics(): void
    {
        [$user, $workspace, $area] = $this->reportContext();
        $portfolio = $this->portfolio($workspace, $area, 'SayaraForce', 1);
        $project = $this->project($workspace, $area, $portfolio, 'SayaraForce Launch');

        $this->task($workspace, $area, $portfolio, $project, [
            'title' => 'Urgent overdue',
            'priority' => 'urgent',
            'due_date' => now()->subDay()->toDateString(),
        ]);
        $this->task($workspace, $area, $portfolio, $project, [
            'title' => 'High future',
            'priority' => 'high',
            'due_date' => now()->addDays(10)->toDateString(),
        ]);

        $response = $this->actingAs($user)->get(route('reports.index', [
            'priority' => 'urgent',
            'due_bucket' => 'overdue',
        ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('summary.total_open_tasks', 1)
            ->where('summary.overdue_tasks', 1)
            ->where('portfolioMetrics.0.total_tasks', 1)
        );
    }

    private function reportContext(): array
    {
        $user = User::factory()->create();
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

        $workspace->users()->attach($user->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);
        $team->users()->attach($user->id, [
            'role' => 'lead',
            'joined_at' => now(),
        ]);

        return [$user, $workspace, $area, $team];
    }

    private function portfolio(Workspace $workspace, Area $area, string $name, int $position): Portfolio
    {
        return Portfolio::create([
            'workspace_id' => $workspace->id,
            'area_id' => $area->id,
            'name' => $name,
            'slug' => strtolower($name),
            'status' => 'active',
            'position' => $position,
        ]);
    }

    private function project(Workspace $workspace, Area $area, Portfolio $portfolio, string $name): Project
    {
        return Project::create([
            'workspace_id' => $workspace->id,
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'status' => 'active',
            'visibility' => 'workspace',
        ]);
    }

    private function makeTasks(Workspace $workspace, Area $area, Portfolio $portfolio, Project $project, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->task($workspace, $area, $portfolio, $project, [
                'title' => "{$portfolio->name} task {$i}",
                'priority' => $i % 10 === 0 ? 'urgent' : 'medium',
                'due_date' => $i % 15 === 0 ? now()->subDay()->toDateString() : now()->addDays($i % 7)->toDateString(),
            ]);
        }
    }

    private function task(Workspace $workspace, Area $area, Portfolio $portfolio, Project $project, array $overrides = []): Task
    {
        return Task::create([
            'workspace_id' => $workspace->id,
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'project_id' => $project->id,
            'title' => 'Launch task',
            'status' => 'todo',
            'priority' => 'medium',
            ...$overrides,
        ]);
    }
}
