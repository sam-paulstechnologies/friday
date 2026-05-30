<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Label;
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

    public function test_task_index_segregates_completed_tasks_from_active_groups(): void
    {
        [$user, $workspace, $project] = $this->taskContext();
        $upcoming = $this->task($user, $workspace, $project, [
            'title' => 'Upcoming active task',
            'due_date' => now()->addDay()->toDateString(),
        ]);
        $overdue = $this->task($user, $workspace, $project, [
            'title' => 'Overdue active task',
            'due_date' => now()->subDay()->toDateString(),
        ]);
        $completed = $this->task($user, $workspace, $project, [
            'title' => 'Completed task',
            'status' => 'completed',
            'completed_at' => now(),
            'due_date' => now()->toDateString(),
        ]);

        $this->actingAs($user)
            ->get(route('tasks.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tasks/Index')
                ->where('taskGroups.upcoming.0.id', $upcoming->id)
                ->where('taskGroups.overdue.0.id', $overdue->id)
                ->where('taskGroups.completed.0.id', $completed->id)
                ->where('taskCounts.upcoming', 1)
                ->where('taskCounts.overdue', 1)
                ->where('taskCounts.completed', 1)
            );
    }

    public function test_task_index_all_tab_sorts_active_before_completed_and_hides_archived(): void
    {
        [$user, $workspace, $project] = $this->taskContext();
        $activeSoon = $this->task($user, $workspace, $project, [
            'title' => 'Active soon',
            'priority' => 'low',
            'due_date' => now()->addDay()->toDateString(),
        ]);
        $activeLaterUrgent = $this->task($user, $workspace, $project, [
            'title' => 'Active later urgent',
            'priority' => 'urgent',
            'due_date' => now()->addDays(3)->toDateString(),
        ]);
        $completedNew = $this->task($user, $workspace, $project, [
            'title' => 'Completed new',
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        $completedOld = $this->task($user, $workspace, $project, [
            'title' => 'Completed old',
            'status' => 'completed',
            'completed_at' => now()->subDay(),
        ]);
        $archived = $this->task($user, $workspace, $project, [
            'title' => 'Archived task',
            'status' => 'archived',
        ]);

        $this->actingAs($user)
            ->get(route('tasks.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('taskGroups.all.0.id', $activeSoon->id)
                ->where('taskGroups.all.1.id', $activeLaterUrgent->id)
                ->where('taskGroups.all.2.id', $completedNew->id)
                ->where('taskGroups.all.3.id', $completedOld->id)
                ->where('taskCounts.all', 4)
            );

        $this->actingAs($user)
            ->get(route('tasks.index', ['status' => 'archived']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('taskGroups.all.0.id', $archived->id)
                ->where('taskCounts.all', 1)
            );
    }

    public function test_dashboard_upcoming_excludes_completed_tasks(): void
    {
        [$user, $workspace, $project] = $this->taskContext();
        $active = $this->task($user, $workspace, $project, [
            'title' => 'Dashboard active task',
            'due_date' => now()->addDay()->toDateString(),
        ]);
        $completed = $this->task($user, $workspace, $project, [
            'title' => 'Dashboard completed task',
            'status' => 'completed',
            'completed_at' => now(),
            'due_date' => now()->addDay()->toDateString(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('weeklyFocus.0.id', $active->id)
                ->where('completedTasks.0.id', $completed->id)
                ->where('summary.upcoming', 1)
            );
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

    public function test_user_cannot_view_another_users_task(): void
    {
        [$owner, $workspace, $project] = $this->isolatedContext('owner');
        [$intruder] = $this->isolatedContext('intruder');
        $task = $this->task($owner, $workspace, $project);

        $this->actingAs($intruder)
            ->get(route('tasks.show', $task))
            ->assertForbidden();
    }

    public function test_user_cannot_update_another_users_task(): void
    {
        [$owner, $workspace, $project] = $this->isolatedContext('owner');
        [$intruder, $intruderWorkspace, $intruderProject] = $this->isolatedContext('intruder');
        $task = $this->task($owner, $workspace, $project);

        $this->actingAs($intruder)
            ->patch(route('tasks.update', $task), [
                'workspace_id' => $intruderWorkspace->id,
                'project_id' => $intruderProject->id,
                'title' => 'Unauthorized edit',
                'status' => 'in_progress',
                'priority' => 'urgent',
                'assignee_id' => $intruder->id,
            ])
            ->assertForbidden();

        $this->assertNotSame('Unauthorized edit', $task->refresh()->title);
    }

    public function test_user_cannot_complete_or_archive_another_users_task(): void
    {
        [$owner, $workspace, $project] = $this->isolatedContext('owner');
        [$intruder] = $this->isolatedContext('intruder');
        $task = $this->task($owner, $workspace, $project);

        $this->actingAs($intruder)
            ->patch(route('tasks.complete', $task))
            ->assertForbidden();

        $this->actingAs($intruder)
            ->patch(route('tasks.archive', $task))
            ->assertForbidden();

        $this->assertSame('todo', $task->refresh()->status);
    }

    public function test_user_cannot_download_another_users_task_attachment(): void
    {
        Storage::fake('attachments');
        [$owner, $workspace, $project] = $this->isolatedContext('owner');
        [$intruder] = $this->isolatedContext('intruder');
        $task = $this->task($owner, $workspace, $project);
        $attachment = $this->attachment($owner, $task);

        $this->actingAs($intruder)
            ->get(route('task-attachments.download', $attachment))
            ->assertForbidden();
    }

    public function test_task_forms_do_not_show_other_users_workspace_project_or_user_options(): void
    {
        [$user, $workspace, $project] = $this->isolatedContext('owner');
        [$otherUser, $otherWorkspace, $otherProject] = $this->isolatedContext('other');

        $this->actingAs($user)
            ->get(route('tasks.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tasks/Create')
                ->has('workspaces', 1)
                ->where('workspaces.0.id', $workspace->id)
                ->has('projects', 1)
                ->where('projects.0.id', $project->id)
                ->has('users', 1)
                ->where('users.0.id', $user->id)
            );

        $task = $this->task($user, $workspace, $project);

        $this->actingAs($user)
            ->get(route('tasks.edit', $task))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tasks/Edit')
                ->where('workspaces.0.id', $workspace->id)
                ->where('projects.0.id', $project->id)
                ->where('users.0.id', $user->id)
            );

        $this->assertNotSame($otherWorkspace->id, $workspace->id);
        $this->assertNotSame($otherProject->id, $project->id);
        $this->assertNotSame($otherUser->id, $user->id);
    }

    public function test_user_can_create_subtask_under_accessible_task(): void
    {
        [$user, $workspace, $project] = $this->taskContext();
        $task = $this->task($user, $workspace, $project);

        $this->actingAs($user)
            ->post(route('tasks.subtasks.store', $task), [
                'title' => 'Draft checklist items',
                'due_date' => '2026-06-03',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tasks', [
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'parent_task_id' => $task->id,
            'title' => 'Draft checklist items',
            'status' => 'todo',
        ]);
    }

    public function test_user_can_complete_and_reopen_subtask_and_progress_updates(): void
    {
        [$user, $workspace, $project] = $this->taskContext();
        $task = $this->task($user, $workspace, $project);
        $first = $this->task($user, $workspace, $project, ['title' => 'First subtask', 'parent_task_id' => $task->id]);
        $this->task($user, $workspace, $project, ['title' => 'Second subtask', 'parent_task_id' => $task->id]);

        $this->actingAs($user)
            ->patch(route('tasks.subtasks.status', [$task, $first]), ['status' => 'completed'])
            ->assertRedirect();

        $this->assertSame('completed', $first->refresh()->status);

        $this->actingAs($user)
            ->get(route('tasks.show', $task))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('task.subtask_progress.total', 2)
                ->where('task.subtask_progress.completed', 1)
            );

        $this->actingAs($user)
            ->patch(route('tasks.subtasks.status', [$task, $first]), ['status' => 'todo'])
            ->assertRedirect();

        $this->assertSame('todo', $first->refresh()->status);
    }

    public function test_user_cannot_create_or_update_another_users_subtask(): void
    {
        [$owner, $workspace, $project] = $this->isolatedContext('owner');
        [$intruder] = $this->isolatedContext('intruder');
        $task = $this->task($owner, $workspace, $project);
        $subtask = $this->task($owner, $workspace, $project, ['parent_task_id' => $task->id]);

        $this->actingAs($intruder)
            ->post(route('tasks.subtasks.store', $task), ['title' => 'Nope'])
            ->assertForbidden();

        $this->actingAs($intruder)
            ->patch(route('tasks.subtasks.status', [$task, $subtask]), ['status' => 'completed'])
            ->assertForbidden();

        $this->assertSame('todo', $subtask->refresh()->status);
    }

    public function test_user_can_create_attach_and_detach_labels_in_own_workspace(): void
    {
        [$user, $workspace, $project] = $this->taskContext();

        $this->actingAs($user)->post(route('tasks.store'), [
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Labelled task',
            'status' => 'todo',
            'priority' => 'medium',
            'assignee_id' => $user->id,
            'new_labels' => 'Launch, Client',
        ])->assertRedirect();

        $task = Task::where('title', 'Labelled task')->firstOrFail();
        $this->assertSame(['Client', 'Launch'], $task->labels()->orderBy('name')->pluck('name')->all());

        $label = Label::where('workspace_id', $workspace->id)->where('name', 'Launch')->firstOrFail();

        $this->actingAs($user)->patch(route('tasks.update', $task), [
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Labelled task',
            'status' => 'todo',
            'priority' => 'medium',
            'assignee_id' => $user->id,
            'label_ids' => [$label->id],
        ])->assertRedirect();

        $this->assertSame(['Launch'], $task->refresh()->labels()->pluck('name')->all());
    }

    public function test_user_cannot_see_or_use_other_workspace_labels(): void
    {
        [$user, $workspace, $project] = $this->isolatedContext('owner');
        [$otherUser, $otherWorkspace] = $this->isolatedContext('other');
        $otherLabel = Label::create(['workspace_id' => $otherWorkspace->id, 'name' => 'Other label']);

        $this->actingAs($user)
            ->get(route('tasks.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('labels', [])
            );

        $this->actingAs($user)->post(route('tasks.store'), [
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Attempt leaked label',
            'status' => 'todo',
            'priority' => 'medium',
            'assignee_id' => $user->id,
            'label_ids' => [$otherLabel->id],
        ])->assertInvalid(['label_ids.0']);

        $this->assertNotSame($otherUser->id, $user->id);
    }

    public function test_recurring_task_creates_next_daily_weekly_and_monthly_occurrences(): void
    {
        [$user, $workspace, $project] = $this->taskContext();

        foreach ([
            'daily' => '2026-06-02',
            'weekly' => '2026-06-08',
            'monthly' => '2026-07-01',
        ] as $type => $nextDate) {
            $task = $this->task($user, $workspace, $project, [
                'title' => "Recurring {$type}",
                'due_date' => '2026-06-01',
                'recurrence_type' => $type,
            ]);

            $this->actingAs($user)->patch(route('tasks.complete', $task))->assertRedirect();

            $this->assertTrue(Task::query()
                ->where('title', "Recurring {$type}")
                ->where('status', 'todo')
                ->where('workspace_id', $workspace->id)
                ->where('project_id', $project->id)
                ->where('recurring_parent_id', $task->id)
                ->whereDate('due_date', $nextDate)
                ->exists());
        }
    }

    public function test_non_recurring_task_does_not_create_duplicate_on_completion(): void
    {
        [$user, $workspace, $project] = $this->taskContext();
        $task = $this->task($user, $workspace, $project, ['title' => 'One-off task', 'due_date' => '2026-06-01']);

        $this->actingAs($user)->patch(route('tasks.complete', $task))->assertRedirect();

        $this->assertSame(1, Task::where('title', 'One-off task')->count());
    }

    public function test_user_cannot_trigger_recurrence_on_another_users_task(): void
    {
        [$owner, $workspace, $project] = $this->isolatedContext('owner');
        [$intruder] = $this->isolatedContext('intruder');
        $task = $this->task($owner, $workspace, $project, [
            'title' => 'Protected recurring task',
            'due_date' => '2026-06-01',
            'recurrence_type' => 'daily',
        ]);

        $this->actingAs($intruder)->patch(route('tasks.complete', $task))->assertForbidden();

        $this->assertSame(1, Task::where('title', 'Protected recurring task')->count());
    }

    public function test_archived_task_is_hidden_from_active_list_and_can_be_restored(): void
    {
        [$user, $workspace, $project] = $this->taskContext();
        $task = $this->task($user, $workspace, $project, ['title' => 'Restorable task']);

        $this->actingAs($user)->patch(route('tasks.archive', $task))->assertRedirect();

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'archived']);
        $this->actingAs($user)
            ->get(route('tasks.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('taskCounts.all', 0));

        $this->actingAs($user)->patch(route('tasks.restore', $task))->assertRedirect(route('tasks.show', $task));
        $this->assertSame('todo', $task->refresh()->status);
    }

    public function test_user_cannot_restore_another_users_task(): void
    {
        [$owner, $workspace, $project] = $this->isolatedContext('owner');
        [$intruder] = $this->isolatedContext('intruder');
        $task = $this->task($owner, $workspace, $project, ['status' => 'archived']);

        $this->actingAs($intruder)->patch(route('tasks.restore', $task))->assertForbidden();

        $this->assertSame('archived', $task->refresh()->status);
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

    private function isolatedContext(string $slug): array
    {
        $user = User::factory()->create([
            'email' => "{$slug}@example.com",
            'name' => ucfirst($slug).' User',
        ]);
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
            'name' => ucfirst($slug).' Project',
            'slug' => "{$slug}-project",
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
