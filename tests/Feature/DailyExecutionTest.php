<?php

namespace Tests\Feature;

use App\Models\DailyReview;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DailyReview\DailyReviewService;
use App\Services\Slack\SlackCommandParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DailyExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_page_loads(): void
    {
        [$user] = $this->context();

        $response = $this->actingAs($user)->get(route('today.index'));

        $response->assertOk();
        $response->assertSee('Today/Index');
    }

    public function test_daily_review_service_groups_overdue_due_today_and_upcoming(): void
    {
        [$user, $workspace, $project] = $this->context();
        $this->task($user, $workspace, $project, ['title' => 'Overdue task', 'due_date' => now()->subDay()]);
        $this->task($user, $workspace, $project, ['title' => 'Today task', 'due_date' => now()]);
        $this->task($user, $workspace, $project, ['title' => 'Upcoming task', 'due_date' => now()->addDays(3)]);

        $groups = app(DailyReviewService::class)->collectTodayTasks($user);

        $this->assertSame(1, $groups->get('overdue')->count());
        $this->assertSame(1, $groups->get('due_today')->count());
        $this->assertSame(1, $groups->get('upcoming')->count());
    }

    public function test_morning_command_creates_review(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true, 'channel' => 'C123', 'ts' => '123.456'])]);
        config(['services.slack.bot_token' => 'xoxb-test', 'services.slack.default_channel' => 'C123']);
        [$user, $workspace, $project] = $this->context();
        $this->task($user, $workspace, $project, ['title' => 'Today task', 'due_date' => now()]);

        $this->artisan('taskflow:send-daily-briefing', ['--user_id' => $user->id])->assertSuccessful();

        $this->assertDatabaseHas('daily_reviews', [
            'user_id' => $user->id,
            'type' => 'morning',
            'status' => 'sent',
        ]);
    }

    public function test_morning_command_uses_daily_user_env_when_user_option_is_missing(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true, 'channel' => 'C123', 'ts' => '123.456'])]);
        config(['services.slack.bot_token' => 'xoxb-test', 'services.slack.default_channel' => 'C123']);
        [$firstUser, $workspace, $project] = $this->context();
        $secondUser = User::factory()->create();
        $this->task($firstUser, $workspace, $project, ['title' => 'First user task', 'due_date' => now()]);
        $this->task($secondUser, $workspace, $project, ['title' => 'Second user task', 'due_date' => now()]);
        $this->setDailyUserEnv((string) $secondUser->id);

        $this->artisan('taskflow:send-daily-briefing')->assertSuccessful();

        $this->assertDatabaseMissing('daily_reviews', [
            'user_id' => $firstUser->id,
            'type' => 'morning',
        ]);
        $this->assertDatabaseHas('daily_reviews', [
            'user_id' => $secondUser->id,
            'type' => 'morning',
            'status' => 'sent',
        ]);

        $this->setDailyUserEnv('');
    }

    public function test_evening_command_creates_review(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true, 'channel' => 'C123', 'ts' => '123.456'])]);
        config(['services.slack.bot_token' => 'xoxb-test', 'services.slack.default_channel' => 'C123']);
        [$user, $workspace, $project] = $this->context();
        $this->task($user, $workspace, $project, ['title' => 'Today task', 'due_date' => now()]);

        $this->artisan('taskflow:send-evening-checkin', ['--user_id' => $user->id])->assertSuccessful();

        $this->assertDatabaseHas('daily_reviews', [
            'user_id' => $user->id,
            'type' => 'evening',
            'status' => 'sent',
        ]);
    }

    public function test_evening_command_uses_daily_user_env_when_user_option_is_missing(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true, 'channel' => 'C123', 'ts' => '123.456'])]);
        config(['services.slack.bot_token' => 'xoxb-test', 'services.slack.default_channel' => 'C123']);
        [$firstUser, $workspace, $project] = $this->context();
        $secondUser = User::factory()->create();
        $this->task($firstUser, $workspace, $project, ['title' => 'First user task', 'due_date' => now()]);
        $this->task($secondUser, $workspace, $project, ['title' => 'Second user task', 'due_date' => now()]);
        $this->setDailyUserEnv((string) $secondUser->id);

        $this->artisan('taskflow:send-evening-checkin')->assertSuccessful();

        $this->assertDatabaseMissing('daily_reviews', [
            'user_id' => $firstUser->id,
            'type' => 'evening',
        ]);
        $this->assertDatabaseHas('daily_reviews', [
            'user_id' => $secondUser->id,
            'type' => 'evening',
            'status' => 'sent',
        ]);

        $this->setDailyUserEnv('');
    }

    public function test_slack_review_messages_use_fixed_width_code_block_tables(): void
    {
        [$user, $workspace, $project] = $this->context();
        $this->task($user, $workspace, $project, [
            'title' => 'Review internal process',
            'priority' => 'medium',
            'due_date' => now(),
        ]);
        $service = app(DailyReviewService::class);
        $morningReview = $service->createMorningReview($user);
        $eveningReview = $service->createEveningReview($user);

        $morningMessage = $service->formatMorningSlackMessage($morningReview);
        $eveningMessage = $service->formatEveningSlackMessage($eveningReview);

        $this->assertStringContainsString("Friday Daily Briefing\nToday:", $morningMessage);
        $this->assertStringContainsString("```\nNo. Type      Priority  Due Date     Context", $morningMessage);
        $this->assertStringContainsString('1   focus     medium', $morningMessage);
        $this->assertStringContainsString('Friday Evening Check-in', $eveningMessage);
        $this->assertStringContainsString("```\nNo. Status    Priority  Due Date     Context", $eveningMessage);
        $this->assertStringContainsString('1   todo      medium', $eveningMessage);
    }

    public function test_slack_parser_maps_done_move_and_note_commands(): void
    {
        $parser = new SlackCommandParser();

        $this->assertSame(['done', [2, 3]], [$parser->parse('done 2,3')['action'], $parser->parse('done 2,3')['numbers']]);
        $this->assertSame(['move', [1], 'tomorrow'], [$parser->parse('move 1 tomorrow')['action'], $parser->parse('move 1 tomorrow')['numbers'], $parser->parse('move 1 tomorrow')['date']]);
        $this->assertSame(['note', [4], 'tested partially'], [$parser->parse('note 4 tested partially')['action'], $parser->parse('note 4 tested partially')['numbers'], $parser->parse('note 4 tested partially')['text']]);
    }

    public function test_slack_done_webhook_marks_task_completed(): void
    {
        config(['services.slack.signing_secret' => 'secret', 'services.slack.default_channel' => 'C123']);
        [$user, $workspace, $project] = $this->context();
        $task = $this->task($user, $workspace, $project, ['title' => 'Review plan', 'due_date' => now()]);
        $this->reviewWithItems($user, [$task]);

        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);
        $response = $this->postSlackCommand('done 1');

        $response->assertOk();
        $this->assertSame('completed', $task->refresh()->status);
        $this->assertDatabaseHas('task_comments', [
            'task_id' => $task->id,
            'body' => 'Marked complete from Slack daily review.',
        ]);
    }

    public function test_slack_done_webhook_marks_multiple_tasks_completed(): void
    {
        config(['services.slack.signing_secret' => 'secret', 'services.slack.default_channel' => 'C123']);
        [$user, $workspace, $project] = $this->context();
        $firstTask = $this->task($user, $workspace, $project, ['title' => 'First task', 'due_date' => now()]);
        $secondTask = $this->task($user, $workspace, $project, ['title' => 'Second task', 'due_date' => now()]);
        $this->reviewWithItems($user, [$firstTask, $secondTask]);

        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);
        $this->postSlackCommand('done 1,2')->assertOk();

        $this->assertSame('completed', $firstTask->refresh()->status);
        $this->assertSame('completed', $secondTask->refresh()->status);
    }

    public function test_slack_move_webhook_updates_due_date(): void
    {
        config(['services.slack.signing_secret' => 'secret', 'services.slack.default_channel' => 'C123']);
        [$user, $workspace, $project] = $this->context();
        $task = $this->task($user, $workspace, $project, ['title' => 'Move task', 'due_date' => now()]);
        $this->reviewWithItems($user, [$task]);

        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);
        $this->postSlackCommand('move 1 tomorrow')->assertOk();

        $this->assertSame(now()->addDay()->toDateString(), $task->refresh()->due_date->toDateString());
        $this->assertDatabaseHas('task_comments', [
            'task_id' => $task->id,
            'body' => 'Moved to '.now()->addDay()->toDateString().' from Slack daily review.',
        ]);
    }

    public function test_slack_note_webhook_adds_comment(): void
    {
        config(['services.slack.signing_secret' => 'secret', 'services.slack.default_channel' => 'C123']);
        [$user, $workspace, $project] = $this->context();
        $task = $this->task($user, $workspace, $project, ['title' => 'Note task', 'due_date' => now()]);
        $this->reviewWithItems($user, [$task]);

        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);
        $this->postSlackCommand('note 1 waiting on feedback')->assertOk();

        $this->assertSame('todo', $task->refresh()->status);
        $this->assertDatabaseHas('task_comments', [
            'task_id' => $task->id,
            'body' => 'Slack daily review note: waiting on feedback',
        ]);
    }

    public function test_slack_block_webhook_marks_blocked_and_adds_comment(): void
    {
        config(['services.slack.signing_secret' => 'secret', 'services.slack.default_channel' => 'C123']);
        [$user, $workspace, $project] = $this->context();
        $task = $this->task($user, $workspace, $project, ['title' => 'Block task', 'due_date' => now()]);
        $this->reviewWithItems($user, [$task]);

        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);
        $this->postSlackCommand('block 1 waiting for client')->assertOk();

        $this->assertSame('blocked', $task->refresh()->status);
        $this->assertDatabaseHas('task_comments', [
            'task_id' => $task->id,
            'body' => 'Blocked from Slack daily review: waiting for client',
        ]);
    }

    public function test_slack_skip_webhook_adds_comment_and_leaves_task_open(): void
    {
        config(['services.slack.signing_secret' => 'secret', 'services.slack.default_channel' => 'C123']);
        [$user, $workspace, $project] = $this->context();
        $task = $this->task($user, $workspace, $project, ['title' => 'Skip task', 'due_date' => now()]);
        $this->reviewWithItems($user, [$task]);

        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);
        $this->postSlackCommand('skip 1')->assertOk();

        $this->assertSame('todo', $task->refresh()->status);
        $this->assertDatabaseHas('task_comments', [
            'task_id' => $task->id,
            'body' => 'Skipped in Slack evening review.',
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
        ]);

        return [$user, $workspace, $project];
    }

    private function task(User $user, Workspace $workspace, Project $project, array $overrides = []): Task
    {
        return Task::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Task',
            'status' => 'todo',
            'priority' => 'medium',
            'assignee_id' => $user->id,
            'reporter_id' => $user->id,
            ...$overrides,
        ]);
    }

    private function reviewWithItems(User $user, array $tasks): DailyReview
    {
        $review = DailyReview::create([
            'user_id' => $user->id,
            'review_date' => now()->toDateString(),
            'type' => 'evening',
            'status' => 'sent',
            'slack_channel_id' => 'C123',
            'sent_at' => now(),
        ]);

        foreach ($tasks as $index => $task) {
            $review->items()->create([
                'task_id' => $task->id,
                'position' => $index + 1,
                'item_type' => 'due_today',
                'snapshot_title' => $task->title,
                'snapshot_status' => $task->status,
                'snapshot_priority' => $task->priority,
                'snapshot_due_date' => $task->due_date,
            ]);
        }

        return $review;
    }

    private function postSlackCommand(string $text, string $user = 'U123')
    {
        $payload = json_encode(['event' => ['channel' => 'C123', 'user' => $user, 'text' => $text]]);
        $timestamp = (string) time();
        $signature = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$payload}", 'secret');

        return $this->withHeaders([
            'X-Slack-Request-Timestamp' => $timestamp,
            'X-Slack-Signature' => $signature,
            'Content-Type' => 'application/json',
        ])->postJson(route('webhooks.slack.events'), json_decode($payload, true));
    }

    private function setDailyUserEnv(string $value): void
    {
        putenv("TASKFLOW_DAILY_USER_ID={$value}");
        $_ENV['TASKFLOW_DAILY_USER_ID'] = $value;
        $_SERVER['TASKFLOW_DAILY_USER_ID'] = $value;
    }
}
