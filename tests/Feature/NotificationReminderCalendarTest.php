<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskReminder;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\TaskFlowNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NotificationReminderCalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_is_created_when_task_is_assigned(): void
    {
        [$reporter, $workspace, $project, $assignee] = $this->context();

        $this->actingAs($reporter)->post(route('tasks.store'), [
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Assigned task',
            'status' => 'todo',
            'priority' => 'medium',
            'assignee_id' => $assignee->id,
        ]);

        $this->assertSame(1, $assignee->notifications()->count());
        $this->assertSame('Task assigned', $assignee->notifications()->first()->data['title']);
    }

    public function test_email_notification_is_triggered_when_task_is_assigned(): void
    {
        Notification::fake();
        [$reporter, $workspace, $project, $assignee] = $this->context();

        $this->actingAs($reporter)->post(route('tasks.store'), [
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Assigned task',
            'status' => 'todo',
            'priority' => 'medium',
            'assignee_id' => $assignee->id,
        ]);

        Notification::assertSentTo($assignee, TaskFlowNotification::class, function ($notification, array $channels) {
            return in_array('mail', $channels, true);
        });
    }

    public function test_notification_is_created_when_comment_is_added(): void
    {
        [$reporter, $workspace, $project, $assignee] = $this->context();
        $task = $this->task($reporter, $workspace, $project, $assignee);

        $this->actingAs($reporter)->post(route('tasks.comments.store', $task), [
            'body' => 'Please review this task.',
        ]);

        $this->assertSame('Comment added', $assignee->notifications()->first()->data['title']);
    }

    public function test_email_notification_is_triggered_when_comment_is_added(): void
    {
        Notification::fake();
        [$reporter, $workspace, $project, $assignee] = $this->context();
        $task = $this->task($reporter, $workspace, $project, $assignee);

        $this->actingAs($reporter)->post(route('tasks.comments.store', $task), [
            'body' => 'Please review this task.',
        ]);

        Notification::assertSentTo($assignee, TaskFlowNotification::class, function ($notification, array $channels) {
            return in_array('mail', $channels, true);
        });
    }

    public function test_notification_is_created_when_attachment_is_uploaded(): void
    {
        Storage::fake('attachments');
        [$reporter, $workspace, $project, $assignee] = $this->context();
        $task = $this->task($reporter, $workspace, $project, $assignee);

        $this->actingAs($reporter)->post(route('tasks.attachments.store', $task), [
            'file' => UploadedFile::fake()->create('brief.pdf', 128, 'application/pdf'),
        ]);

        $this->assertSame('Attachment uploaded', $assignee->notifications()->first()->data['title']);
    }

    public function test_notification_page_loads(): void
    {
        [$reporter] = $this->context();

        $response = $this->actingAs($reporter)->get(route('notifications.index'));

        $response->assertOk();
    }

    public function test_notification_can_be_marked_as_read(): void
    {
        [$reporter] = $this->context();
        $reporter->notify(new TaskFlowNotification('Test', 'Message'));
        $notification = $reporter->notifications()->first();

        $this->actingAs($reporter)->patch(route('notifications.read', $notification));

        $this->assertNotNull($notification->refresh()->read_at);
    }

    public function test_all_notifications_can_be_marked_as_read(): void
    {
        [$reporter] = $this->context();
        $reporter->notify(new TaskFlowNotification('One', 'Message'));
        $reporter->notify(new TaskFlowNotification('Two', 'Message'));

        $this->actingAs($reporter)->patch(route('notifications.read-all'));

        $this->assertSame(0, $reporter->unreadNotifications()->count());
    }

    public function test_due_tomorrow_reminder_creates_notification(): void
    {
        [$reporter, $workspace, $project, $assignee] = $this->context();
        $this->task($reporter, $workspace, $project, $assignee, ['due_date' => now()->addDay()]);

        Artisan::call('taskflow:send-task-reminders');

        $this->assertSame(1, $assignee->notifications()->count());
        $this->assertDatabaseHas('task_reminders', ['reminder_type' => 'due_tomorrow']);
    }

    public function test_due_today_reminder_creates_notification(): void
    {
        [$reporter, $workspace, $project, $assignee] = $this->context();
        $this->task($reporter, $workspace, $project, $assignee, ['due_date' => now()]);

        Artisan::call('taskflow:send-task-reminders');

        $this->assertDatabaseHas('task_reminders', ['reminder_type' => 'due_today']);
    }

    public function test_overdue_reminder_creates_notification(): void
    {
        [$reporter, $workspace, $project, $assignee] = $this->context();
        $this->task($reporter, $workspace, $project, $assignee, ['due_date' => now()->subDay()]);

        Artisan::call('taskflow:send-task-reminders');

        $this->assertDatabaseHas('task_reminders', ['reminder_type' => 'overdue']);
    }

    public function test_duplicate_reminders_are_not_created_for_same_day_type_task_user(): void
    {
        [$reporter, $workspace, $project, $assignee] = $this->context();
        $this->task($reporter, $workspace, $project, $assignee, ['due_date' => now()]);

        Artisan::call('taskflow:send-task-reminders');
        Artisan::call('taskflow:send-task-reminders');

        $this->assertSame(1, TaskReminder::count());
    }

    public function test_reminder_command_exits_successfully(): void
    {
        $this->assertSame(0, Artisan::call('taskflow:send-task-reminders'));
    }

    public function test_calendar_page_loads(): void
    {
        [$reporter] = $this->context();

        $response = $this->actingAs($reporter)->get(route('calendar.index'));

        $response->assertOk();
    }

    public function test_calendar_includes_task_due_dates(): void
    {
        [$reporter, $workspace, $project, $assignee] = $this->context();
        $this->task($reporter, $workspace, $project, $assignee, ['title' => 'Task due event', 'due_date' => '2026-05-20']);

        $response = $this->actingAs($reporter)->get(route('calendar.index', ['month' => '2026-05']));

        $response->assertSee('Task due event');
        $response->assertSee('Task due');
    }

    public function test_calendar_includes_task_start_dates(): void
    {
        [$reporter, $workspace, $project, $assignee] = $this->context();
        $this->task($reporter, $workspace, $project, $assignee, ['title' => 'Task start event', 'start_date' => '2026-05-10']);

        $response = $this->actingAs($reporter)->get(route('calendar.index', ['month' => '2026-05']));

        $response->assertSee('Task start event');
        $response->assertSee('Task start');
    }

    public function test_calendar_includes_project_due_dates(): void
    {
        [$reporter, $workspace, $project] = $this->context();
        $project->update(['due_date' => '2026-05-22']);

        $response = $this->actingAs($reporter)->get(route('calendar.index', ['month' => '2026-05']));

        $response->assertSee($project->name);
        $response->assertSee('Project due');
    }

    public function test_calendar_includes_project_start_dates(): void
    {
        [$reporter, $workspace, $project] = $this->context();
        $project->update(['start_date' => '2026-05-02']);

        $response = $this->actingAs($reporter)->get(route('calendar.index', ['month' => '2026-05']));

        $response->assertSee($project->name);
        $response->assertSee('Project start');
    }

    public function test_archived_tasks_are_excluded_from_calendar(): void
    {
        [$reporter, $workspace, $project, $assignee] = $this->context();
        $this->task($reporter, $workspace, $project, $assignee, [
            'title' => 'Archived calendar task',
            'status' => 'archived',
            'due_date' => '2026-05-20',
        ]);

        $response = $this->actingAs($reporter)->get(route('calendar.index', ['month' => '2026-05']));

        $response->assertDontSee('Archived calendar task');
    }

    private function context(): array
    {
        $reporter = User::factory()->create();
        $assignee = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'TaskFlow Workspace',
            'slug' => 'taskflow-workspace',
            'created_by' => $reporter->id,
        ]);
        $team = Team::create([
            'workspace_id' => $workspace->id,
            'name' => 'Product Team',
            'slug' => 'product-team',
        ]);
        $project = Project::create([
            'workspace_id' => $workspace->id,
            'team_id' => $team->id,
            'owner_id' => $reporter->id,
            'name' => 'Product Launch Plan',
            'slug' => 'product-launch-plan',
            'status' => 'active',
            'visibility' => 'workspace',
        ]);

        $workspace->users()->attach($reporter->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);
        $workspace->users()->attach($assignee->id, [
            'role' => 'member',
            'joined_at' => now(),
        ]);
        $team->users()->attach($reporter->id, [
            'role' => 'lead',
            'joined_at' => now(),
        ]);
        $team->users()->attach($assignee->id, [
            'role' => 'member',
            'joined_at' => now(),
        ]);

        return [$reporter, $workspace, $project, $assignee];
    }

    private function task(User $reporter, Workspace $workspace, Project $project, User $assignee, array $overrides = []): Task
    {
        return Task::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Calendar task',
            'status' => 'todo',
            'priority' => 'medium',
            'assignee_id' => $assignee->id,
            'reporter_id' => $reporter->id,
            ...$overrides,
        ]);
    }
}
