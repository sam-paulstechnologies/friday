<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Approval;
use App\Models\Blocker;
use App\Models\Decision;
use App\Models\Portfolio;
use App\Models\Project;
use App\Models\Risk;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\WaitingItem;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LifeOsCommandCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_areas_index_loads(): void
    {
        [$user] = $this->context();

        $this->actingAs($user)->get(route('areas.index'))->assertOk();
    }

    public function test_area_show_groups_tasks(): void
    {
        [$user, $workspace, , $area, $portfolio, $project] = $this->context();
        Task::create([
            'workspace_id' => $workspace->id,
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'project_id' => $project->id,
            'title' => 'Area due task',
            'status' => 'todo',
            'priority' => 'high',
            'reporter_id' => $user->id,
            'due_date' => now()->toDateString(),
        ]);

        $this->actingAs($user)
            ->get(route('areas.show', $area))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Areas/Show')
                ->has('tasks.due_today', 1)
            );
    }

    public function test_portfolio_index_loads(): void
    {
        [$user] = $this->context();

        $this->actingAs($user)->get(route('portfolios.index'))->assertOk();
    }

    public function test_portfolio_index_exposes_work_metrics(): void
    {
        [$user, $workspace, , $area, $portfolio, $project] = $this->context();

        Task::create([
            'workspace_id' => $workspace->id,
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'project_id' => $project->id,
            'title' => 'Open overdue portfolio task',
            'status' => 'todo',
            'priority' => 'high',
            'reporter_id' => $user->id,
            'due_date' => now()->subDay()->toDateString(),
        ]);

        Task::create([
            'workspace_id' => $workspace->id,
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'project_id' => $project->id,
            'title' => 'Completed portfolio task',
            'status' => 'completed',
            'priority' => 'medium',
            'reporter_id' => $user->id,
            'due_date' => now()->toDateString(),
        ]);

        $this->actingAs($user)
            ->get(route('portfolios.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portfolios/Index')
                ->where('areas.0.portfolios.0.total_projects_count', 1)
                ->where('areas.0.portfolios.0.open_tasks_count', 1)
                ->where('areas.0.portfolios.0.completed_tasks_count', 1)
                ->where('areas.0.portfolios.0.overdue_tasks_count', 1)
                ->where('areas.0.portfolios.0.progress_percentage', 50)
            );
    }

    public function test_portfolio_show_groups_tasks(): void
    {
        [$user, $workspace, , $area, $portfolio, $project] = $this->context();
        Task::create([
            'workspace_id' => $workspace->id,
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'project_id' => $project->id,
            'title' => 'Portfolio overdue task',
            'status' => 'todo',
            'priority' => 'urgent',
            'reporter_id' => $user->id,
            'due_date' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($user)
            ->get(route('portfolios.show', $portfolio))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Portfolios/Show')
                ->has('tasks.overdue', 1)
            );
    }

    public function test_project_can_be_assigned_area_and_portfolio(): void
    {
        [$user, $workspace, $team, $area, $portfolio] = $this->context();

        $this->actingAs($user)->post(route('projects.store'), [
            'workspace_id' => $workspace->id,
            'team_id' => $team->id,
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'name' => 'Life OS Project',
            'status' => 'active',
            'visibility' => 'workspace',
            'health' => 'at_risk',
            'project_type' => 'career',
        ])->assertRedirect();

        $this->assertDatabaseHas('projects', [
            'name' => 'Life OS Project',
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'health' => 'at_risk',
        ]);
    }

    public function test_task_can_be_assigned_area_portfolio_and_task_type(): void
    {
        [$user, $workspace, , $area, $portfolio, $project] = $this->context();

        $this->actingAs($user)->post(route('tasks.store'), [
            'workspace_id' => $workspace->id,
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'project_id' => $project->id,
            'title' => 'Decision task',
            'status' => 'todo',
            'priority' => 'medium',
            'task_type' => 'decision',
        ])->assertRedirect();

        $this->assertDatabaseHas('tasks', [
            'title' => 'Decision task',
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'task_type' => 'decision',
        ]);
    }

    public function test_dashboard_loads_with_command_center_data(): void
    {
        [$user, $workspace, , $area, $portfolio, $project] = $this->context();
        WaitingItem::create([
            'user_id' => $user->id,
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'project_id' => $project->id,
            'title' => 'Waiting on client',
        ]);
        Task::create([
            'workspace_id' => $workspace->id,
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'project_id' => $project->id,
            'title' => 'Risk task',
            'status' => 'todo',
            'priority' => 'high',
            'task_type' => 'risk',
            'reporter_id' => $user->id,
            'due_date' => now()->toDateString(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('summary.waiting_for', 1)
                ->where('summary.risks', 1)
            );
    }

    public function test_command_center_objects_can_be_created_and_closed(): void
    {
        [$user, , , $area, $portfolio, $project] = $this->context();

        $this->actingAs($user)->post(route('waiting.store'), [
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'project_id' => $project->id,
            'title' => 'Waiting for Sunny',
            'waiting_on' => 'Sunny',
        ])->assertRedirect();
        $waiting = WaitingItem::first();
        $this->actingAs($user)->patch(route('waiting.close', $waiting))->assertRedirect();
        $this->assertSame('closed', $waiting->refresh()->status);

        $this->actingAs($user)->post(route('decisions.store'), ['title' => 'Choose vendor'])->assertRedirect();
        $decision = Decision::first();
        $this->actingAs($user)->patch(route('decisions.close', $decision))->assertRedirect();
        $this->assertSame('decided', $decision->refresh()->status);

        $this->actingAs($user)->post(route('blockers.store'), ['title' => 'Access blocked'])->assertRedirect();
        $blocker = Blocker::first();
        $this->actingAs($user)->patch(route('blockers.close', $blocker))->assertRedirect();
        $this->assertSame('resolved', $blocker->refresh()->status);

        $this->actingAs($user)->post(route('risks.store'), ['title' => 'Timeline risk'])->assertRedirect();
        $risk = Risk::first();
        $this->actingAs($user)->patch(route('risks.close', $risk))->assertRedirect();
        $this->assertSame('closed', $risk->refresh()->status);

        $this->actingAs($user)->post(route('approvals.store'), ['title' => 'Approve budget'])->assertRedirect();
        $approval = Approval::first();
        $this->actingAs($user)->patch(route('approvals.close', $approval))->assertRedirect();
        $this->assertSame('approved', $approval->refresh()->status);

        $approval = Approval::create(['user_id' => $user->id, 'title' => 'Reject draft']);
        $this->actingAs($user)->patch(route('approvals.reject', $approval))->assertRedirect();
        $this->assertSame('rejected', $approval->refresh()->status);
    }

    public function test_today_and_daily_briefing_still_work_after_area_portfolio_changes(): void
    {
        [$user, $workspace, , $area, $portfolio, $project] = $this->context();
        Task::create([
            'workspace_id' => $workspace->id,
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'project_id' => $project->id,
            'title' => 'Briefing task',
            'status' => 'todo',
            'priority' => 'urgent',
            'assignee_id' => $user->id,
            'reporter_id' => $user->id,
            'due_date' => now()->toDateString(),
        ]);

        $this->actingAs($user)->get(route('today.index'))->assertOk();

        config(['services.slack.bot_token' => 'xoxb-test', 'services.slack.default_channel' => 'C123']);
        Http::fake(['https://slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'ts' => '123.456'])]);

        $this->artisan('taskflow:send-daily-briefing', ['--format' => 'text'])->assertExitCode(0);
        $this->assertDatabaseHas('daily_reviews', ['user_id' => $user->id, 'type' => 'morning', 'status' => 'sent']);
    }

    private function context(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'TaskFlow Workspace', 'slug' => 'taskflow-workspace', 'created_by' => $user->id]);
        $team = Team::create(['workspace_id' => $workspace->id, 'name' => 'Product Team', 'slug' => 'product-team']);
        $area = Area::create(['name' => 'Career', 'slug' => 'career', 'position' => 1, 'is_active' => true]);
        $portfolio = Portfolio::create(['area_id' => $area->id, 'workspace_id' => $workspace->id, 'name' => 'Publicis Digitas', 'slug' => 'publicis-digitas']);
        $project = Project::create([
            'workspace_id' => $workspace->id,
            'team_id' => $team->id,
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'owner_id' => $user->id,
            'name' => 'Stellantis GCC',
            'slug' => 'stellantis-gcc',
            'status' => 'active',
            'visibility' => 'workspace',
        ]);

        $workspace->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $team->users()->attach($user->id, ['role' => 'lead', 'joined_at' => now()]);

        return [$user, $workspace, $team, $area, $portfolio, $project];
    }
}
