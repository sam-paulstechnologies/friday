<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Portfolio;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TaskReviewExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_review_export_command_writes_markdown_and_csv(): void
    {
        $this->reviewContext();

        $this->artisan('taskflow:export-task-review')
            ->expectsOutputToContain('Task review pack exported.')
            ->assertSuccessful();

        $markdownPath = storage_path('app/reviews/task-review.md');
        $csvPath = storage_path('app/reviews/task-review.csv');

        $this->assertFileExists($markdownPath);
        $this->assertFileExists($csvPath);
        $this->assertStringContainsString('## A. Executive Summary', file_get_contents($markdownPath));
        $this->assertStringContainsString('Possible high-priority tasks', file_get_contents($markdownPath));
        $this->assertStringContainsString('Due Date Bucket', file_get_contents($csvPath));
    }

    public function test_task_review_page_loads(): void
    {
        [$user] = $this->reviewContext();

        $response = $this->actingAs($user)->get(route('task-review.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('TaskReview/Index')
            ->has('review.summary')
            ->has('review.portfolioSummary')
            ->has('review.priorityReviewCandidates')
            ->has('review.tasks', 3)
        );
    }

    private function reviewContext(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'Miriam Workspace',
            'slug' => 'miriam-workspace',
            'created_by' => $user->id,
        ]);
        $area = Area::create([
            'name' => 'Work',
            'slug' => 'work',
            'position' => 1,
            'is_active' => true,
        ]);
        $portfolio = Portfolio::create([
            'workspace_id' => $workspace->id,
            'area_id' => $area->id,
            'name' => 'SayaraForce',
            'slug' => 'sayaraforce',
            'status' => 'active',
            'position' => 1,
        ]);
        $project = Project::create([
            'workspace_id' => $workspace->id,
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'name' => 'Launch',
            'slug' => 'launch',
            'status' => 'active',
            'visibility' => 'workspace',
        ]);

        $workspace->users()->attach($user->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        Task::create([
            'workspace_id' => $workspace->id,
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'project_id' => $project->id,
            'title' => 'Medium task due this week',
            'description' => 'Review candidate for high priority.',
            'status' => 'todo',
            'priority' => 'medium',
            'due_date' => now()->addDays(2)->toDateString(),
            'reporter_id' => $user->id,
        ]);
        Task::create([
            'workspace_id' => $workspace->id,
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'project_id' => $project->id,
            'title' => 'Old cleanup task',
            'status' => 'todo',
            'priority' => 'low',
            'due_date' => now()->subDay()->toDateString(),
        ]);
        Task::create([
            'workspace_id' => $workspace->id,
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'project_id' => $project->id,
            'title' => 'Completed urgent task',
            'status' => 'completed',
            'priority' => 'urgent',
            'completed_at' => now(),
        ]);

        return [$user, $workspace, $area, $portfolio, $project];
    }
}
