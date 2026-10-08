<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Area;
use App\Models\Blocker;
use App\Models\Decision;
use App\Models\Portfolio;
use App\Models\Project;
use App\Models\Risk;
use App\Models\Task;
use App\Models\User;
use App\Models\WaitingItem;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CommandCenterIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public static function resources(): array
    {
        return [
            'waiting' => ['waiting', WaitingItem::class],
            'decisions' => ['decisions', Decision::class],
            'blockers' => ['blockers', Blocker::class],
            'risks' => ['risks', Risk::class],
            'approvals' => ['approvals', Approval::class],
        ];
    }

    #[DataProvider('resources')]
    public function test_options_are_scoped_and_accessible_links_are_preserved(string $route, string $model): void
    {
        [$user, $workspace, $area, $portfolio, $project, $task] = $this->context();
        $this->context();
        $item = $model::create(['user_id' => $user->id, 'title' => 'Owned item', 'project_id' => $project->id, 'portfolio_id' => $portfolio->id]);
        $this->actingAs($user)->get(route($route.'.index'))->assertOk()->assertInertia(fn ($page) => $page
            ->has('options.portfolios', 1)->has('options.projects', 1)->has('options.tasks', 1)
            ->where('options.projects.0.id', $project->id)->where('items.0.project.id', $project->id));
    }

    #[DataProvider('resources')]
    public function test_foreign_relation_ids_are_rejected_on_create_and_update(string $route, string $model): void
    {
        [$user] = $this->context();
        [, , , $foreignPortfolio, $foreignProject, $foreignTask] = $this->context();
        $item = $model::create(['user_id' => $user->id, 'title' => 'Owned item']);
        foreach (['portfolio_id' => $foreignPortfolio->id, 'project_id' => $foreignProject->id, 'task_id' => $foreignTask->id] as $field => $id) {
            $payload = ['title' => 'Attempt', $field => $id];
            $this->actingAs($user)->post(route($route.'.store'), $payload)->assertSessionHasErrors($field);
            $this->actingAs($user)->patch(route($route.'.update', $item), $payload)->assertSessionHasErrors($field);
        }
        $this->assertSame(1, $model::count());
        $this->assertSame('Owned item', $item->refresh()->title);
    }

    #[DataProvider('resources')]
    public function test_accessible_but_mixed_workspaces_and_hierarchy_are_rejected(string $route, string $model): void
    {
        [$user, $workspace, $area, $portfolio, $project, $task] = $this->context();
        [$other, $otherWorkspace, , $otherPortfolio] = $this->context();
        $otherWorkspace->users()->attach($user, ['role' => 'admin']);
        $this->actingAs($user)->post(route($route.'.store'), ['title' => 'Mixed', 'portfolio_id' => $otherPortfolio->id, 'project_id' => $project->id])->assertSessionHasErrors('project_id');
        $wrong = Portfolio::create(['workspace_id' => $workspace->id, 'area_id' => $area->id, 'name' => 'Other portfolio', 'slug' => 'other-'.$user->id]);
        $this->post(route($route.'.store'), ['title' => 'Wrong hierarchy', 'portfolio_id' => $wrong->id, 'project_id' => $project->id])->assertSessionHasErrors('portfolio_id');
        $this->assertSame(0, $model::count());
    }

    #[DataProvider('resources')]
    public function test_owner_member_can_create_update_and_close_valid_records(string $route, string $model): void
    {
        [$user, $workspace, $area, $portfolio, $project, $task] = $this->context();
        $payload = ['title' => 'Valid item', 'area_id' => $area->id, 'portfolio_id' => $portfolio->id, 'project_id' => $project->id, 'task_id' => $task->id];
        $this->actingAs($user)->post(route($route.'.store'), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $item = $model::firstOrFail();
        $this->patch(route($route.'.update', $item), [...$payload, 'title' => 'Updated'])->assertSessionHasNoErrors();
        $this->patch(route($route.'.close', $item))->assertSessionHasNoErrors();
        $this->assertSame('Updated', $item->refresh()->title);
    }

    #[DataProvider('resources')]
    public function test_viewer_cannot_create_update_close_or_reject(string $route, string $model): void
    {
        [, $workspace] = $this->context();
        $viewer = User::factory()->create();
        $workspace->users()->attach($viewer, ['role' => 'viewer']);
        $item = $model::create(['user_id' => $viewer->id, 'title' => 'Viewer item']);
        $this->actingAs($viewer)->post(route($route.'.store'), ['title' => 'Attempt'])->assertForbidden();
        $this->patch(route($route.'.update', $item), ['title' => 'Attempt'])->assertForbidden();
        $this->patch(route($route.'.close', $item))->assertForbidden();
        if ($route === 'approvals') {
            $this->patch(route('approvals.reject', $item))->assertForbidden();
        }
    }

    #[DataProvider('resources')]
    public function test_other_users_cannot_mutate_an_owned_item(string $route, string $model): void
    {
        [$user] = $this->context();
        [$other] = $this->context();
        $item = $model::create(['user_id' => $other->id, 'title' => 'Foreign owned item']);
        $this->actingAs($user)->patch(route($route.'.update', $item), ['title' => 'Attempt'])->assertForbidden();
        $this->patch(route($route.'.close', $item))->assertForbidden();
    }

    #[DataProvider('resources')]
    public function test_legacy_foreign_links_are_redacted_and_cannot_be_closed(string $route, string $model): void
    {
        [$user] = $this->context();
        [, , , $portfolio, $project] = $this->context();
        $item = $model::create(['user_id' => $user->id, 'title' => 'Legacy item', 'portfolio_id' => $portfolio->id, 'project_id' => $project->id]);
        $this->actingAs($user)->get(route($route.'.index'))->assertOk()->assertInertia(fn ($page) => $page
            ->where('items.0.project', null)->where('items.0.project_id', null)->where('items.0.portfolio', null));
        $this->patch(route($route.'.close', $item))->assertSessionHasErrors('portfolio_id');
        $this->patch(route($route.'.update', $item), ['title' => 'Repair', 'portfolio_id' => null, 'project_id' => null])->assertSessionHasNoErrors();
    }

    #[DataProvider('resources')]
    public function test_no_workspace_and_arbitrary_terminal_status_fail_closed(string $route, string $model): void
    {
        $unassigned = User::factory()->create();
        $this->actingAs($unassigned)->get(route($route.'.index'))->assertOk()->assertInertia(fn ($page) => $page
            ->has('options.projects', 0)->has('options.portfolios', 0)->has('options.tasks', 0));
        $this->post(route($route.'.store'), ['title' => 'Attempt'])->assertForbidden();
        [$user] = $this->context();
        $item = $model::create(['user_id' => $user->id, 'title' => 'Owned item']);
        $this->actingAs($user)->patch(route($route.'.close', $item), ['status' => 'invented'])->assertSessionHasErrors('status');
    }

    public function test_task_and_project_must_match_and_assignment_does_not_bypass_membership(): void
    {
        [$user, $workspace, $area, $portfolio, $project] = $this->context();
        [$other, $otherWorkspace, , , , $foreignTask] = $this->context();
        $foreignTask->update(['assignee_id' => $user->id]);
        $this->actingAs($user)->post(route('waiting.store'), ['title' => 'Attempt', 'task_id' => $foreignTask->id])->assertSessionHasErrors('task_id');
        $personal = Task::create(['workspace_id' => $workspace->id, 'title' => 'Personal', 'reporter_id' => $user->id]);
        $this->post(route('waiting.store'), ['title' => 'Attempt', 'project_id' => $project->id, 'task_id' => $personal->id])->assertSessionHasErrors('task_id');
        $wrongArea = Area::create(['name' => 'Other taxonomy', 'slug' => 'other-taxonomy', 'is_active' => true]);
        $this->post(route('waiting.store'), ['title' => 'Attempt', 'project_id' => $project->id, 'area_id' => $wrongArea->id])->assertSessionHasErrors('area_id');
    }

    public function test_member_can_link_without_being_the_project_owner(): void
    {
        [$owner, $workspace, $area, $portfolio, $project, $task] = $this->context();
        $member = User::factory()->create();
        $workspace->users()->attach($member, ['role' => 'member']);
        $this->actingAs($member)->post(route('waiting.store'), ['title' => 'Member item', 'project_id' => $project->id, 'task_id' => $task->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('waiting_items', ['user_id' => $member->id, 'project_id' => $project->id]);
    }

    public function test_inaccessible_and_nonexistent_ids_have_the_same_error(): void
    {
        [$user] = $this->context();
        [, , , , $foreign] = $this->context();
        $messages = [];
        foreach ([$foreign->id, 999999] as $id) {
            $this->actingAs($user)->post(route('waiting.store'), ['title' => 'Attempt', 'project_id' => $id])->assertSessionHasErrors('project_id');
            $messages[] = session('errors')->first('project_id');
        }
        $this->assertSame($messages[0], $messages[1]);
    }

    private function context(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Synthetic workspace', 'slug' => 'ws-'.$user->id, 'created_by' => $user->id]);
        $workspace->users()->attach($user, ['role' => 'owner']);
        $area = Area::create(['name' => 'Synthetic area', 'slug' => 'area-'.$user->id, 'is_active' => true]);
        $portfolio = Portfolio::create(['workspace_id' => $workspace->id, 'area_id' => $area->id, 'name' => 'Synthetic portfolio', 'slug' => 'portfolio-'.$user->id]);
        $project = Project::create(['workspace_id' => $workspace->id, 'area_id' => $area->id, 'portfolio_id' => $portfolio->id, 'owner_id' => $user->id, 'name' => 'Synthetic project', 'slug' => 'project-'.$user->id]);
        $task = Task::create(['workspace_id' => $workspace->id, 'area_id' => $area->id, 'portfolio_id' => $portfolio->id, 'project_id' => $project->id, 'reporter_id' => $user->id, 'title' => 'Synthetic task']);

        return [$user, $workspace, $area, $portfolio, $project, $task];
    }
}
