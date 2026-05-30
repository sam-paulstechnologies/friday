<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Goal;
use App\Models\Portfolio;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LeadershipVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-05-22 09:00:00');
    }

    public function test_goals_page_loads(): void
    {
        [$user] = $this->context();

        $this->actingAs($user)
            ->get(route('goals.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Goals/Index')->has('goals'));
    }

    public function test_user_can_create_and_edit_goal_and_link_project(): void
    {
        [$user, $workspace, $area, $portfolio, $project] = $this->context();

        $this->actingAs($user)
            ->post(route('goals.store'), [
                'workspace_id' => $workspace->id,
                'owner_id' => $user->id,
                'title' => 'Grow Miriam usage',
                'description' => 'Leadership visibility goal.',
                'status' => 'on_track',
                'target_date' => '2026-06-30',
                'progress_percentage' => 10,
                'project_ids' => [$project->id],
            ])
            ->assertRedirect();

        $goal = Goal::where('title', 'Grow Miriam usage')->firstOrFail();
        $this->assertTrue($goal->projects()->whereKey($project->id)->exists());

        $this->actingAs($user)
            ->patch(route('goals.update', $goal), [
                'workspace_id' => $workspace->id,
                'owner_id' => $user->id,
                'title' => 'Grow Miriam adoption',
                'description' => 'Updated goal.',
                'status' => 'at_risk',
                'target_date' => '2026-07-15',
                'progress_percentage' => 25,
                'project_ids' => [$project->id],
            ])
            ->assertRedirect(route('goals.show', $goal));

        $this->assertSame('Grow Miriam adoption', $goal->refresh()->title);
        $this->assertDatabaseHas('goal_activities', ['goal_id' => $goal->id, 'action' => 'goal_updated']);
        $this->assertNotNull($area);
        $this->assertNotNull($portfolio);
    }

    public function test_goal_and_key_result_progress_rollups_work(): void
    {
        [$user, $workspace] = $this->context();
        $goal = Goal::create([
            'workspace_id' => $workspace->id,
            'owner_id' => $user->id,
            'title' => 'Improve delivery',
            'status' => 'on_track',
        ]);

        $this->actingAs($user)
            ->post(route('goals.key-results.store', $goal), [
                'title' => 'Ship 10 improvements',
                'target_value' => 10,
                'current_value' => 5,
                'unit' => 'items',
                'status' => 'on_track',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('goal_key_results', [
            'goal_id' => $goal->id,
            'title' => 'Ship 10 improvements',
            'progress_percentage' => 50,
        ]);
        $this->assertSame(50, $goal->refresh()->progress_percentage);
    }

    public function test_portfolio_page_loads_and_user_can_create_edit_add_and_remove_project(): void
    {
        [$user, $workspace, $area, , $project] = $this->context();

        $this->actingAs($user)->get(route('portfolios.index'))->assertOk();

        $this->actingAs($user)
            ->post(route('portfolios.store'), [
                'workspace_id' => $workspace->id,
                'area_id' => $area->id,
                'owner_id' => $user->id,
                'name' => 'Leadership Portfolio',
                'description' => 'Portfolio reporting.',
                'status' => 'active',
                'color' => '#2563eb',
                'position' => 1,
            ])
            ->assertRedirect();

        $portfolio = Portfolio::where('name', 'Leadership Portfolio')->firstOrFail();

        $this->actingAs($user)
            ->patch(route('portfolios.update', $portfolio), [
                'workspace_id' => $workspace->id,
                'area_id' => $area->id,
                'owner_id' => $user->id,
                'name' => 'Leadership Portfolio Updated',
                'description' => 'Updated.',
                'status' => 'active',
                'color' => '#0f172a',
                'position' => 2,
            ])
            ->assertRedirect(route('portfolios.show', $portfolio));

        $this->actingAs($user)->post(route('portfolios.projects.store', $portfolio), ['project_id' => $project->id])->assertRedirect();
        $this->assertSame($portfolio->id, $project->refresh()->portfolio_id);

        $this->actingAs($user)->delete(route('portfolios.projects.destroy', [$portfolio, $project]))->assertRedirect();
        $this->assertNull($project->refresh()->portfolio_id);
    }

    public function test_portfolio_progress_rollup_works(): void
    {
        [$user, $workspace, $area, $portfolio, $project] = $this->context();
        $this->task($workspace, $area, $portfolio, $project, ['status' => 'completed', 'completed_at' => now()]);
        $this->task($workspace, $area, $portfolio, $project, ['status' => 'todo']);

        $this->actingAs($user)
            ->get(route('portfolios.show', $portfolio))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('portfolio.progress_percentage', 50)
                ->where('portfolio.open_tasks_count', 1)
                ->where('portfolio.completed_tasks_count', 1)
            );
    }

    public function test_reports_show_leadership_metrics_and_workload(): void
    {
        [$user, $workspace, $area, $portfolio, $project, $assignee] = $this->context(['health' => 'at_risk']);
        Goal::create(['workspace_id' => $workspace->id, 'owner_id' => $user->id, 'title' => 'Leadership goal', 'status' => 'on_track', 'progress_percentage' => 30]);
        $this->task($workspace, $area, $portfolio, $project, ['assignee_id' => $assignee->id, 'priority' => 'high', 'due_date' => '2026-05-20']);
        $this->task($workspace, $area, $portfolio, $project, ['assignee_id' => $assignee->id, 'due_date' => '2026-05-24']);
        $this->task($workspace, $area, $portfolio, $project, ['assignee_id' => $assignee->id, 'status' => 'completed', 'completed_at' => now()]);

        $this->actingAs($user)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.active_projects', 1)
                ->where('summary.overdue_tasks', 1)
                ->where('summary.due_this_week', 1)
                ->where('trends.completed_this_week', 1)
                ->where('summary.active_goals', 1)
                ->where('goalMetrics.0.title', 'Leadership goal')
                ->where('workloadMetrics.0.name', $assignee->name)
                ->where('workloadMetrics.0.open_tasks', 2)
            );
    }

    public function test_unauthorized_user_cannot_see_inaccessible_goal_portfolio_or_report_data(): void
    {
        [$owner, $workspace, $area, $portfolio, $project] = $this->context();
        $goal = Goal::create(['workspace_id' => $workspace->id, 'owner_id' => $owner->id, 'title' => 'Private goal', 'status' => 'on_track']);
        $this->task($workspace, $area, $portfolio, $project, ['title' => 'Private task']);
        [$intruder] = $this->context([], 'intruder');

        $this->actingAs($intruder)->get(route('goals.show', $goal))->assertForbidden();
        $this->actingAs($intruder)->get(route('portfolios.show', $portfolio))->assertForbidden();
        $this->actingAs($intruder)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.total_open_tasks', 0)
                ->where('summary.active_goals', 0)
            );
    }

    public function test_dashboard_leadership_summary_appears(): void
    {
        [$user, $workspace, $area, $portfolio, $project] = $this->context(['health' => 'at_risk']);
        Goal::create(['workspace_id' => $workspace->id, 'owner_id' => $user->id, 'title' => 'Visible goal', 'status' => 'on_track']);
        $this->task($workspace, $area, $portfolio, $project, ['due_date' => '2026-05-20']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('leadership.active_goals', 1)
                ->where('leadership.at_risk_projects', 1)
                ->where('leadership.overdue_tasks', 1)
            );
    }

    private function context(array $projectOverrides = [], string $slug = 'leader'): array
    {
        $user = User::factory()->create(['email' => "{$slug}@example.com", 'name' => ucfirst($slug).' Owner']);
        $assignee = User::factory()->create(['email' => "{$slug}-assignee@example.com", 'name' => ucfirst($slug).' Assignee']);
        $workspace = Workspace::create([
            'name' => ucfirst($slug).' Workspace',
            'slug' => "{$slug}-workspace",
            'created_by' => $user->id,
        ]);
        $team = Team::create(['workspace_id' => $workspace->id, 'name' => ucfirst($slug).' Team', 'slug' => "{$slug}-team"]);
        $area = Area::create(['name' => ucfirst($slug).' Area', 'slug' => "{$slug}-area", 'position' => 1, 'is_active' => true]);
        $portfolio = Portfolio::create([
            'workspace_id' => $workspace->id,
            'area_id' => $area->id,
            'owner_id' => $user->id,
            'name' => ucfirst($slug).' Portfolio',
            'slug' => "{$slug}-portfolio",
            'status' => 'active',
            'position' => 1,
        ]);
        $project = Project::create([
            'workspace_id' => $workspace->id,
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'owner_id' => $user->id,
            'name' => ucfirst($slug).' Project',
            'slug' => "{$slug}-project",
            'status' => 'active',
            'visibility' => 'workspace',
            'health' => 'on_track',
            ...$projectOverrides,
        ]);

        foreach ([$user, $assignee] as $member) {
            $workspace->users()->attach($member->id, ['role' => $member->is($user) ? 'owner' : 'member', 'joined_at' => now()]);
            $team->users()->attach($member->id, ['role' => $member->is($user) ? 'lead' : 'member', 'joined_at' => now()]);
        }

        return [$user, $workspace, $area, $portfolio, $project, $assignee];
    }

    private function task(Workspace $workspace, Area $area, Portfolio $portfolio, Project $project, array $overrides = []): Task
    {
        return Task::create([
            'workspace_id' => $workspace->id,
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'project_id' => $project->id,
            'title' => 'Leadership task',
            'status' => 'todo',
            'priority' => 'medium',
            'assignee_id' => $project->owner_id,
            'reporter_id' => $project->owner_id,
            'due_date' => now()->addDay()->toDateString(),
            ...$overrides,
        ]);
    }
}
