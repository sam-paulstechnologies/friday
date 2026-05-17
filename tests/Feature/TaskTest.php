<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskAttachment;
use App\Models\TaskComment;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_my_tasks_page_loads(): void
    {
        [$user] = $this->taskContext();

        $response = $this->actingAs($user)->get(route('tasks.index'));

        $response->assertOk();
    }

    public function test_task_can_be_created(): void
    {
        [$user, $workspace, $project] = $this->taskContext();

        $response = $this->actingAs($user)->post(route('tasks.store'), [
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Finalize launch checklist',
            'description' => 'Confirm launch readiness.',
            'status' => 'todo',
            'priority' => 'high',
            'assignee_id' => $user->id,
            'start_date' => '2026-06-01',
            'due_date' => '2026-06-05',
        ]);

        $task = Task::where('title', 'Finalize launch checklist')->first();

        $this->assertNotNull($task);
        $this->assertSame($user->id, $task->reporter_id);
        $this->assertSame($project->id, $task->project_id);
        $response->assertRedirect(route('tasks.show', $task));
    }

    public function test_task_can_be_updated(): void
    {
        [$user, $workspace, $project] = $this->taskContext();
        $task = $this->task($user, $workspace, $project);

        $response = $this->actingAs($user)->patch(route('tasks.update', $task), [
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Confirm project timeline',
            'description' => 'Updated task brief.',
            'status' => 'in_progress',
            'priority' => 'urgent',
            'assignee_id' => $user->id,
            'start_date' => '2026-06-01',
            'due_date' => '2026-06-10',
        ]);

        $task->refresh();

        $this->assertSame('Confirm project timeline', $task->title);
        $this->assertSame('in_progress', $task->status);
        $this->assertSame('urgent', $task->priority);
        $response->assertRedirect(route('tasks.show', $task));
    }

    public function test_task_can_be_completed(): void
    {
        [$user, $workspace, $project] = $this->taskContext();
        $task = $this->task($user, $workspace, $project);

        $response = $this->actingAs($user)->patch(route('tasks.complete', $task));

        $task->refresh();

        $this->assertSame('completed', $task->status);
        $this->assertNotNull($task->completed_at);
        $response->assertRedirect(route('tasks.show', $task));
    }

    public function test_task_can_be_archived(): void
    {
        [$user, $workspace, $project] = $this->taskContext();
        $task = $this->task($user, $workspace, $project);

        $response = $this->actingAs($user)->patch(route('tasks.archive', $task));

        $this->assertSame('archived', $task->refresh()->status);
        $response->assertRedirect(route('tasks.index'));
    }

    public function test_project_show_displays_linked_tasks(): void
    {
        [$user, $workspace, $project] = $this->taskContext();
        $this->task($user, $workspace, $project, ['title' => 'Prepare homepage content']);

        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $response->assertOk();
        $response->assertSee('Prepare homepage content');
    }

    public function test_project_board_page_loads(): void
    {
        [$user, $workspace, $project] = $this->taskContext();
        $this->task($user, $workspace, $project, ['title' => 'Board task']);

        $response = $this->actingAs($user)->get(route('projects.board', $project));

        $response->assertOk();
        $response->assertSee('Board task');
    }

    public function test_project_board_excludes_archived_tasks(): void
    {
        [$user, $workspace, $project] = $this->taskContext();
        $this->task($user, $workspace, $project, ['title' => 'Visible task']);
        $this->task($user, $workspace, $project, [
            'title' => 'Archived task',
            'status' => 'archived',
        ]);

        $response = $this->actingAs($user)->get(route('projects.board', $project));

        $response->assertOk();
        $response->assertSee('Visible task');
        $response->assertDontSee('Archived task');
    }

    public function test_task_status_can_be_updated(): void
    {
        [$user, $workspace, $project] = $this->taskContext();
        $task = $this->task($user, $workspace, $project);

        $response = $this->actingAs($user)->patch(route('tasks.status', $task), [
            'status' => 'review',
        ]);

        $this->assertSame('review', $task->refresh()->status);
        $response->assertRedirect();
    }

    public function test_moving_task_to_completed_sets_completed_at(): void
    {
        [$user, $workspace, $project] = $this->taskContext();
        $task = $this->task($user, $workspace, $project);

        $this->actingAs($user)->patch(route('tasks.status', $task), [
            'status' => 'completed',
        ]);

        $task->refresh();

        $this->assertSame('completed', $task->status);
        $this->assertNotNull($task->completed_at);
    }

    public function test_moving_task_away_from_completed_clears_completed_at(): void
    {
        [$user, $workspace, $project] = $this->taskContext();
        $task = $this->task($user, $workspace, $project, [
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $this->actingAs($user)->patch(route('tasks.status', $task), [
            'status' => 'in_progress',
        ]);

        $task->refresh();

        $this->assertSame('in_progress', $task->status);
        $this->assertNull($task->completed_at);
    }

    public function test_comment_can_be_added_to_task(): void
    {
        [$user, $workspace, $project] = $this->taskContext();
        $task = $this->task($user, $workspace, $project);

        $response = $this->actingAs($user)->post(route('tasks.comments.store', $task), [
            'body' => 'This needs a launch review.',
        ]);

        $this->assertDatabaseHas('task_comments', [
            'task_id' => $task->id,
            'user_id' => $user->id,
            'body' => 'This needs a launch review.',
        ]);
        $response->assertRedirect();
    }

    public function test_comment_can_be_updated(): void
    {
        [$user, $workspace, $project] = $this->taskContext();
        $task = $this->task($user, $workspace, $project);
        $comment = TaskComment::create([
            'task_id' => $task->id,
            'user_id' => $user->id,
            'body' => 'Original comment.',
        ]);

        $response = $this->actingAs($user)->patch(route('task-comments.update', $comment), [
            'body' => 'Updated comment.',
        ]);

        $this->assertSame('Updated comment.', $comment->refresh()->body);
        $response->assertRedirect();
    }

    public function test_comment_can_be_deleted(): void
    {
        [$user, $workspace, $project] = $this->taskContext();
        $task = $this->task($user, $workspace, $project);
        $comment = TaskComment::create([
            'task_id' => $task->id,
            'user_id' => $user->id,
            'body' => 'Temporary comment.',
        ]);

        $response = $this->actingAs($user)->delete(route('task-comments.destroy', $comment));

        $this->assertDatabaseMissing('task_comments', ['id' => $comment->id]);
        $response->assertRedirect();
    }

    public function test_task_show_displays_comments(): void
    {
        [$user, $workspace, $project] = $this->taskContext();
        $task = $this->task($user, $workspace, $project);
        TaskComment::create([
            'task_id' => $task->id,
            'user_id' => $user->id,
            'body' => 'Visible task discussion.',
        ]);

        $response = $this->actingAs($user)->get(route('tasks.show', $task));

        $response->assertOk();
        $response->assertSee('Visible task discussion.');
    }

    public function test_task_creation_logs_activity(): void
    {
        [$user, $workspace, $project] = $this->taskContext();

        $this->actingAs($user)->post(route('tasks.store'), [
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Activity tracked task',
            'status' => 'todo',
            'priority' => 'medium',
            'assignee_id' => $user->id,
        ]);

        $task = Task::where('title', 'Activity tracked task')->firstOrFail();

        $this->assertDatabaseHas('task_activities', [
            'task_id' => $task->id,
            'user_id' => $user->id,
            'action' => 'task_created',
        ]);
    }

    public function test_task_update_logs_activity(): void
    {
        [$user, $workspace, $project] = $this->taskContext();
        $task = $this->task($user, $workspace, $project);

        $this->actingAs($user)->patch(route('tasks.update', $task), [
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Updated activity task',
            'status' => 'review',
            'priority' => 'urgent',
            'assignee_id' => $user->id,
            'due_date' => '2026-06-10',
        ]);

        $this->assertDatabaseHas('task_activities', [
            'task_id' => $task->id,
            'action' => 'status_changed',
            'old_value' => 'todo',
            'new_value' => 'review',
        ]);
        $this->assertDatabaseHas('task_activities', [
            'task_id' => $task->id,
            'action' => 'priority_changed',
            'old_value' => 'medium',
            'new_value' => 'urgent',
        ]);
    }

    public function test_task_completion_logs_activity(): void
    {
        [$user, $workspace, $project] = $this->taskContext();
        $task = $this->task($user, $workspace, $project);

        $this->actingAs($user)->patch(route('tasks.complete', $task));

        $this->assertDatabaseHas('task_activities', [
            'task_id' => $task->id,
            'user_id' => $user->id,
            'action' => 'task_completed',
        ]);
    }

    public function test_board_status_change_logs_activity(): void
    {
        [$user, $workspace, $project] = $this->taskContext();
        $task = $this->task($user, $workspace, $project);

        $this->actingAs($user)->patch(route('tasks.status', $task), [
            'status' => 'in_progress',
        ]);

        $this->assertDatabaseHas('task_activities', [
            'task_id' => $task->id,
            'action' => 'status_changed',
            'old_value' => 'todo',
            'new_value' => 'in_progress',
        ]);
    }

    public function test_attachment_can_be_uploaded(): void
    {
        Storage::fake('attachments');
        [$user, $workspace, $project] = $this->taskContext();
        $task = $this->task($user, $workspace, $project);

        $response = $this->actingAs($user)->post(route('tasks.attachments.store', $task), [
            'file' => UploadedFile::fake()->create('brief.pdf', 128, 'application/pdf'),
        ]);

        $attachment = TaskAttachment::where('task_id', $task->id)->first();

        $this->assertNotNull($attachment);
        $this->assertSame('brief.pdf', $attachment->original_name);
        Storage::disk('attachments')->assertExists($attachment->path);
        $response->assertRedirect();
    }

    public function test_attachment_appears_on_task_show(): void
    {
        Storage::fake('attachments');
        [$user, $workspace, $project] = $this->taskContext();
        $task = $this->task($user, $workspace, $project);
        $attachment = $this->attachment($user, $task);

        $response = $this->actingAs($user)->get(route('tasks.show', $task));

        $response->assertOk();
        $response->assertSee($attachment->original_name);
    }

    public function test_attachment_can_be_downloaded(): void
    {
        Storage::fake('attachments');
        [$user, $workspace, $project] = $this->taskContext();
        $task = $this->task($user, $workspace, $project);
        $attachment = $this->attachment($user, $task);

        $response = $this->actingAs($user)->get(route('task-attachments.download', $attachment));

        $response->assertOk();
        $this->assertStringContainsString('attachment;', $response->headers->get('content-disposition'));
    }

    public function test_attachment_can_be_deleted_by_uploader(): void
    {
        Storage::fake('attachments');
        [$user, $workspace, $project] = $this->taskContext();
        $task = $this->task($user, $workspace, $project);
        $attachment = $this->attachment($user, $task);

        $response = $this->actingAs($user)->delete(route('task-attachments.destroy', $attachment));

        $this->assertDatabaseMissing('task_attachments', ['id' => $attachment->id]);
        Storage::disk('attachments')->assertMissing($attachment->path);
        $response->assertRedirect();
    }

    public function test_attachment_upload_logs_activity(): void
    {
        Storage::fake('attachments');
        [$user, $workspace, $project] = $this->taskContext();
        $task = $this->task($user, $workspace, $project);

        $this->actingAs($user)->post(route('tasks.attachments.store', $task), [
            'file' => UploadedFile::fake()->create('brief.pdf', 128, 'application/pdf'),
        ]);

        $this->assertDatabaseHas('task_activities', [
            'task_id' => $task->id,
            'user_id' => $user->id,
            'action' => 'attachment_uploaded',
            'new_value' => 'brief.pdf',
        ]);
    }

    public function test_attachment_delete_logs_activity(): void
    {
        Storage::fake('attachments');
        [$user, $workspace, $project] = $this->taskContext();
        $task = $this->task($user, $workspace, $project);
        $attachment = $this->attachment($user, $task);

        $this->actingAs($user)->delete(route('task-attachments.destroy', $attachment));

        $this->assertDatabaseHas('task_activities', [
            'task_id' => $task->id,
            'user_id' => $user->id,
            'action' => 'attachment_deleted',
            'old_value' => 'brief.pdf',
        ]);
    }

    private function taskContext(): array
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
            'title' => 'Finalize launch checklist',
            'status' => 'todo',
            'priority' => 'medium',
            'assignee_id' => $user->id,
            'reporter_id' => $user->id,
            ...$overrides,
        ]);
    }

    private function attachment(User $user, Task $task): TaskAttachment
    {
        Storage::disk('attachments')->put("task-attachments/{$task->id}/brief.pdf", 'demo file');

        return TaskAttachment::create([
            'task_id' => $task->id,
            'user_id' => $user->id,
            'original_name' => 'brief.pdf',
            'stored_name' => 'brief.pdf',
            'path' => "task-attachments/{$task->id}/brief.pdf",
            'mime_type' => 'application/pdf',
            'size' => 9,
        ]);
    }
}
