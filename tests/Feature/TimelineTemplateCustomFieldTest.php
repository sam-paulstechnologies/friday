<?php

namespace Tests\Feature;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TimelineTemplateCustomFieldTest extends TestCase
{
    use RefreshDatabase;

    public function test_timeline_page_loads(): void
    {
        [$user, , $project] = $this->context();

        $response = $this->actingAs($user)->get(route('projects.timeline', $project));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Projects/Timeline')
            ->where('project.id', $project->id));
    }

    public function test_timeline_includes_dated_tasks(): void
    {
        [$user, $workspace, $project] = $this->context();
        $this->task($user, $workspace, $project, [
            'title' => 'Dated launch task',
            'start_date' => '2026-06-01',
            'due_date' => '2026-06-12',
        ]);

        $response = $this->actingAs($user)->get(route('projects.timeline', $project));

        $response->assertOk();
        $response->assertSee('Dated launch task');
    }

    public function test_timeline_includes_unscheduled_tasks(): void
    {
        [$user, $workspace, $project] = $this->context();
        $this->task($user, $workspace, $project, ['title' => 'Unscheduled planning task']);

        $response = $this->actingAs($user)->get(route('projects.timeline', $project));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Projects/Timeline')
            ->where('unscheduledTasks.0.title', 'Unscheduled planning task'));
    }

    public function test_timeline_excludes_archived_tasks(): void
    {
        [$user, $workspace, $project] = $this->context();
        $this->task($user, $workspace, $project, ['title' => 'Visible task']);
        $this->task($user, $workspace, $project, [
            'title' => 'Archived timeline task',
            'status' => 'archived',
            'start_date' => '2026-06-01',
            'due_date' => '2026-06-05',
        ]);

        $response = $this->actingAs($user)->get(route('projects.timeline', $project));

        $response->assertOk();
        $response->assertSee('Visible task');
        $response->assertDontSee('Archived timeline task');
    }

    public function test_project_show_has_timeline_link(): void
    {
        [$user, , $project] = $this->context();

        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $response->assertOk();
        $response->assertSee('projects.timeline');
    }

    public function test_custom_fields_page_loads(): void
    {
        [$user] = $this->context();

        $response = $this->actingAs($user)->get(route('admin.custom-fields.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/CustomFields/Index')
            ->has('workspaces'));
    }

    public function test_custom_field_can_be_created(): void
    {
        [$user, $workspace] = $this->context();

        $response = $this->actingAs($user)->post(route('admin.custom-fields.store'), [
            'workspace_id' => $workspace->id,
            'name' => 'Customer Impact',
            'field_type' => 'select',
            'applies_to' => 'task',
            'options' => "Low\nMedium\nHigh",
        ]);

        $this->assertDatabaseHas('custom_fields', [
            'workspace_id' => $workspace->id,
            'name' => 'Customer Impact',
            'key' => 'customer_impact',
            'field_type' => 'select',
            'applies_to' => 'task',
        ]);
        $response->assertRedirect();
    }

    public function test_custom_field_value_can_be_saved_for_a_task(): void
    {
        [$user, $workspace, $project] = $this->context();
        $task = $this->task($user, $workspace, $project);
        $field = CustomField::create([
            'workspace_id' => $workspace->id,
            'name' => 'Customer Impact',
            'key' => 'customer_impact',
            'field_type' => 'text',
            'applies_to' => 'task',
        ]);

        $response = $this->actingAs($user)->patch(route('tasks.custom-fields.update', $task), [
            'values' => [$field->id => 'High'],
        ]);

        $this->assertDatabaseHas('custom_field_values', [
            'custom_field_id' => $field->id,
            'entity_type' => Task::class,
            'entity_id' => $task->id,
            'value' => 'High',
        ]);
        $response->assertRedirect();
    }

    public function test_templates_page_loads(): void
    {
        [$user] = $this->context();

        $response = $this->actingAs($user)->get(route('templates.index'));

        $response->assertOk();
        $response->assertSee('Templates');
    }

    public function test_project_template_can_create_a_project(): void
    {
        [$user, $workspace] = $this->context();
        $template = ProjectTemplate::create([
            'workspace_id' => $workspace->id,
            'name' => 'Product Launch Template',
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->post(route('templates.create-project', $template));

        $project = Project::where('name', 'Product Launch Template Project')->first();

        $this->assertNotNull($project);
        $this->assertSame($user->id, $project->owner_id);
        $response->assertRedirect(route('projects.show', $project));
    }

    public function test_template_project_creates_tasks(): void
    {
        [$user, $workspace] = $this->context();
        $template = ProjectTemplate::create([
            'workspace_id' => $workspace->id,
            'name' => 'Product Launch Template',
            'created_by' => $user->id,
        ]);
        $template->tasks()->create([
            'title' => 'Define launch goals',
            'priority' => 'medium',
            'position' => 1,
            'offset_days' => 2,
        ]);

        $this->actingAs($user)->post(route('templates.create-project', $template));

        $project = Project::where('name', 'Product Launch Template Project')->firstOrFail();

        $this->assertDatabaseHas('tasks', [
            'project_id' => $project->id,
            'title' => 'Define launch goals',
            'reporter_id' => $user->id,
        ]);
    }

    private function context(): array
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
        $project = Project::create([
            'workspace_id' => $workspace->id,
            'team_id' => $team->id,
            'owner_id' => $user->id,
            'name' => 'Product Launch Plan',
            'slug' => 'product-launch-plan',
            'status' => 'active',
            'visibility' => 'workspace',
            'start_date' => '2026-06-01',
            'due_date' => '2026-06-30',
        ]);

        $workspace->users()->attach($user->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);
        $team->users()->attach($user->id, [
            'role' => 'lead',
            'joined_at' => now(),
        ]);

        return [$user, $workspace, $project];
    }

    private function task(User $user, Workspace $workspace, Project $project, array $overrides = []): Task
    {
        return Task::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Timeline task',
            'status' => 'todo',
            'priority' => 'medium',
            'assignee_id' => $user->id,
            'reporter_id' => $user->id,
            ...$overrides,
        ]);
    }
}
