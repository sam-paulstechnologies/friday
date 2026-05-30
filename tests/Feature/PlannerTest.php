<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-05-22 09:00:00');
    }

    public function test_planner_page_loads_for_authenticated_user(): void
    {
        [$user] = $this->context();

        $this->actingAs($user)
            ->get(route('planner.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Planner/Index')
                ->has('calendar.events')
                ->has('weekPlan.days')
                ->has('timeline')
                ->has('workload')
            );
    }

    public function test_calendar_includes_due_today_and_due_this_week_tasks(): void
    {
        [$user, $workspace, $project, $assignee] = $this->context();
        $today = $this->task($workspace, $project, $assignee, ['title' => 'Due today', 'due_date' => '2026-05-22']);
        $week = $this->task($workspace, $project, $assignee, ['title' => 'Due this week', 'due_date' => '2026-05-24']);

        $this->actingAs($user)
            ->get(route('planner.index', ['month' => '2026-05']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('calendar.events.0.title', 'Due today')
                ->where('calendar.events.0.date', '2026-05-22')
                ->where('calendar.events.1.title', 'Due this week')
                ->where('summary.due_this_week', 2)
            );

        $this->assertSame('Due today', $today->title);
        $this->assertSame('Due this week', $week->title);
    }

    public function test_weekly_planner_groups_tasks_by_date_and_separates_completed_tasks(): void
    {
        [$user, $workspace, $project, $assignee] = $this->context();
        $active = $this->task($workspace, $project, $assignee, ['title' => 'Active Friday', 'due_date' => '2026-05-22']);
        $completed = $this->task($workspace, $project, $assignee, [
            'title' => 'Completed Friday',
            'status' => 'completed',
            'due_date' => '2026-05-22',
            'completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('planner.index', ['week' => '2026-05-18']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('weekPlan.days.4.date', '2026-05-22')
                ->where('weekPlan.days.4.activeTasks.0.id', $active->id)
                ->where('weekPlan.days.4.completedTasks.0.id', $completed->id)
            );
    }

    public function test_overdue_tasks_appear_in_planner(): void
    {
        [$user, $workspace, $project, $assignee] = $this->context();
        $overdue = $this->task($workspace, $project, $assignee, ['title' => 'Late planner task', 'due_date' => '2026-05-20']);

        $this->actingAs($user)
            ->get(route('planner.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('calendar.overdue.0.id', $overdue->id)
                ->where('weekPlan.overdue.0.title', 'Late planner task')
                ->where('summary.overdue_tasks', 1)
            );
    }

    public function test_timeline_includes_project_and_task_deadline_data(): void
    {
        [$user, $workspace, $project, $assignee] = $this->context([
            'start_date' => '2026-05-19',
            'due_date' => '2026-06-01',
        ]);
        $task = $this->task($workspace, $project, $assignee, [
            'title' => 'Timeline task',
            'start_date' => '2026-05-21',
            'due_date' => '2026-05-29',
        ]);

        $this->actingAs($user)
            ->get(route('planner.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('timeline.0.id', $project->id)
                ->where('timeline.0.due_date', '2026-06-01')
                ->where('timeline.0.tasks.0.id', $task->id)
                ->where('timeline.0.tasks.0.due_date', '2026-05-29')
            );
    }

    public function test_workload_counts_open_overdue_due_this_week_and_high_priority_tasks(): void
    {
        [$user, $workspace, $project, $assignee] = $this->context();
        $this->task($workspace, $project, $assignee, ['priority' => 'high', 'due_date' => '2026-05-20']);
        $this->task($workspace, $project, $assignee, ['priority' => 'urgent', 'due_date' => '2026-05-24']);
        $this->task($workspace, $project, $assignee, ['status' => 'completed', 'due_date' => '2026-05-21', 'completed_at' => now()->subDay()]);

        $this->actingAs($user)
            ->get(route('planner.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workload.0.name', $assignee->name)
                ->where('workload.0.open_tasks', 2)
                ->where('workload.0.overdue_tasks', 1)
                ->where('workload.0.due_this_week', 1)
                ->where('workload.0.high_priority_tasks', 2)
                ->where('workload.0.recently_completed', 1)
            );
    }

    public function test_unauthorized_user_cannot_see_inaccessible_planning_data(): void
    {
        [$owner, $workspace, $project, $assignee] = $this->context();
        $this->task($workspace, $project, $assignee, ['title' => 'Owner only task', 'due_date' => '2026-05-22']);
        [$intruder] = $this->context(['name' => 'Intruder Project'], 'intruder');

        $this->actingAs($intruder)
            ->get(route('planner.index', ['month' => '2026-05']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('calendar.events', 0)
                ->has('timeline', 0)
            );

        $this->assertNotSame($owner->id, $intruder->id);
    }

    public function test_dashboard_planning_summary_appears(): void
    {
        [$user, $workspace, $project, $assignee] = $this->context(['due_date' => '2026-06-03']);
        $this->task($workspace, $project, $assignee, ['due_date' => '2026-05-20']);
        $this->task($workspace, $project, $assignee, ['due_date' => '2026-05-24']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('planning.this_week_tasks', 1)
                ->where('planning.overdue_tasks', 1)
                ->where('planning.next_project_deadline.name', $project->name)
            );
    }

    private function context(array $projectOverrides = [], string $slug = 'planner'): array
    {
        $user = User::factory()->create(['email' => "{$slug}@example.com", 'name' => ucfirst($slug).' Owner']);
        $assignee = User::factory()->create(['email' => "{$slug}-assignee@example.com", 'name' => ucfirst($slug).' Assignee']);
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
            'name' => $projectOverrides['name'] ?? ucfirst($slug).' Project',
            'slug' => "{$slug}-project",
            'status' => 'active',
            'visibility' => 'workspace',
            ...$projectOverrides,
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

        return [$user, $workspace, $project, $assignee];
    }

    private function task(Workspace $workspace, Project $project, User $assignee, array $overrides = []): Task
    {
        return Task::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Planner task',
            'status' => 'todo',
            'priority' => 'medium',
            'assignee_id' => $assignee->id,
            'reporter_id' => $project->owner_id,
            ...$overrides,
        ]);
    }
}
