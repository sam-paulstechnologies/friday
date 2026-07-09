<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Area;
use App\Models\Blocker;
use App\Models\MedicationDoseSchedule;
use App\Models\MiriamDevelopmentFailure;
use App\Models\MiriamDevelopmentJob;
use App\Models\MiriamManagedApp;
use App\Models\Portfolio;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MiriamTodayCommandCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_today_dashboard_route_loads(): void
    {
        [$user] = $this->context();

        $this->actingAs($user)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Today/Index')
                ->has('commandCenter.metrics')
                ->has('commandCenter.do_this_now')
                ->has('commandCenter.products')
            );
    }

    public function test_critical_overdue_and_waiting_sections_render(): void
    {
        [$user, $workspace, $project] = $this->context();
        $overdue = $this->task($user, $workspace, $project, [
            'title' => 'Client renewal overdue',
            'priority' => 'urgent',
            'due_date' => now()->subDay()->toDateString(),
        ]);

        Approval::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'title' => 'Approve production cleanup',
            'status' => 'pending',
        ]);

        Blocker::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'title' => 'Blocked by missing credentials',
            'description' => 'Need access from client.',
            'severity' => 'critical',
            'status' => 'open',
        ]);

        $this->actingAs($user)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('commandCenter.metrics.overdue', 1)
                ->where('commandCenter.metrics.waiting_for_me', 1)
                ->where('commandCenter.waiting_on_me.0.title', 'Approve production cleanup')
                ->where('commandCenter.overdue_blocked.0.title', $overdue->title)
            );
    }

    public function test_codex_job_status_appears_when_jobs_exist(): void
    {
        [$user] = $this->context();
        $app = MiriamManagedApp::create([
            'name' => 'Miriam/Friday',
            'slug' => 'miriam-friday',
            'status' => 'active',
        ]);

        MiriamDevelopmentJob::create([
            'managed_app_id' => $app->id,
            'started_by_user_id' => $user->id,
            'title' => 'Codex is redesigning Today',
            'status' => 'running',
            'total_phases' => 3,
            'completed_phases' => 1,
            'run_mode' => 'controlled_multi_phase',
        ]);

        $this->actingAs($user)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('commandCenter.metrics.codex_running', 1)
                ->where('commandCenter.codex_workstream.jobs.0.title', 'Codex is redesigning Today')
                ->where('commandCenter.codex_workstream.jobs.0.status', 'running')
                ->where('commandCenter.codex_workstream.jobs.0.app', 'Miriam/Friday')
            );
    }

    public function test_medication_pending_overdue_state_appears(): void
    {
        $now = CarbonImmutable::parse('2026-07-08 07:30:00', 'UTC');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
        [$user, $workspace] = $this->context();

        MedicationDoseSchedule::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'dose_key' => 'morning',
            'label' => 'Morning medications',
            'dosage_text' => 'Morning dose',
            'timing_note' => 'after breakfast',
            'schedule_time' => '09:00:00',
            'hard_deadline_time' => '10:00:00',
            'timezone' => 'Asia/Dubai',
            'active' => true,
            'repeat_interval_minutes' => 30,
            'default_channel' => 'database',
            'metadata' => ['frequency' => 'daily'],
        ]);

        $this->actingAs($user)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('commandCenter.metrics.overdue', 1)
                ->where('commandCenter.metrics.health_medicine', 'Medication pending')
                ->where('commandCenter.do_this_now.0.kind', 'medication')
                ->where('commandCenter.do_this_now.0.title', 'Morning medications')
            );
    }

    public function test_product_grouping_appears(): void
    {
        [$user, $workspace, $project] = $this->context(projectName: 'SayaraForce launch');
        $this->task($user, $workspace, $project, [
            'title' => 'SayaraForce demo checklist',
            'priority' => 'high',
            'due_date' => now()->toDateString(),
        ]);

        $this->actingAs($user)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('commandCenter.products.0.label', 'SayaraForce')
                ->where('commandCenter.products.0.urgent_count', 1)
                ->where('commandCenter.products.0.open_count', 1)
                ->where('commandCenter.products.0.next_action', 'SayaraForce demo checklist')
            );
    }

    public function test_backlog_items_do_not_appear_in_do_this_now_unless_urgent(): void
    {
        [$user, $workspace, $project] = $this->context();
        $this->task($user, $workspace, $project, [
            'title' => 'Low urgency someday task',
            'priority' => 'low',
            'due_date' => null,
        ]);

        $this->actingAs($user)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('commandCenter.do_this_now', [])
                ->where('commandCenter.later_backlog.0.title', 'Low urgency someday task')
            );
    }

    public function test_codex_failure_appears_as_blocked_work(): void
    {
        [$user] = $this->context();
        $app = MiriamManagedApp::create([
            'name' => 'Miriam/Friday',
            'slug' => 'miriam-friday',
            'status' => 'active',
        ]);
        $job = MiriamDevelopmentJob::create([
            'managed_app_id' => $app->id,
            'started_by_user_id' => $user->id,
            'title' => 'Validation job',
            'status' => 'failed',
            'run_mode' => 'controlled_multi_phase',
        ]);

        MiriamDevelopmentFailure::create([
            'development_job_id' => $job->id,
            'failure_type' => 'build_failed',
            'severity' => 'high',
            'title' => 'Build failed',
            'summary' => 'npm run build failed',
            'can_auto_fix' => true,
            'status' => 'open',
        ]);

        $this->actingAs($user)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('commandCenter.codex_workstream.jobs.0.status', 'failed')
                ->where('commandCenter.overdue_blocked.0.title', 'Build failed')
            );
    }

    private function context(string $projectName = 'Miriam Command Center'): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'Miriam Workspace',
            'slug' => 'miriam-workspace',
            'created_by' => $user->id,
        ]);
        $team = Team::create([
            'workspace_id' => $workspace->id,
            'name' => 'Product Team',
            'slug' => 'product-team',
        ]);
        $area = Area::create([
            'name' => 'Products',
            'slug' => 'products',
            'is_active' => true,
        ]);
        $portfolio = Portfolio::create([
            'workspace_id' => $workspace->id,
            'area_id' => $area->id,
            'owner_id' => $user->id,
            'name' => $projectName,
            'slug' => str($projectName)->slug()->toString(),
            'status' => 'active',
        ]);
        $project = Project::create([
            'workspace_id' => $workspace->id,
            'team_id' => $team->id,
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'owner_id' => $user->id,
            'name' => $projectName,
            'slug' => str($projectName)->slug()->toString(),
            'status' => 'active',
            'visibility' => 'workspace',
        ]);

        $workspace->users()->attach($user->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);
        $team->users()->attach($user->id, [
            'role' => 'lead',
            'joined_at' => now(),
        ]);

        return [$user, $workspace, $project, $portfolio, $area];
    }

    private function task(User $user, Workspace $workspace, Project $project, array $overrides = []): Task
    {
        return Task::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'area_id' => $project->area_id,
            'portfolio_id' => $project->portfolio_id,
            'title' => 'Default task',
            'status' => 'todo',
            'priority' => 'medium',
            'assignee_id' => $user->id,
            'reporter_id' => $user->id,
            ...$overrides,
        ]);
    }
}
