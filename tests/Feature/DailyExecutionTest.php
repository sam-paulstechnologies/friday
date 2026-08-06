<?php

namespace Tests\Feature;

use App\Models\DailyReview;
use App\Models\Portfolio;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DailyReview\DailyBriefingImageService;
use App\Services\DailyReview\DailyBriefingService;
use App\Services\DailyReview\DailyReviewService;
use App\Services\Slack\SlackCommandParser;
use Database\Seeders\SpiritualBibleReadingPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DailyExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_page_loads(): void
    {
        [$user] = $this->context();

        $response = $this->actingAs($user)->get(route('today.index'));

        $response->assertOk();
        // Assert the Inertia component, not the raw HTML: the page name is
        // JSON-escaped inside data-page, so assertSee() could never match it.
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('Today/Index'));
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

    public function test_completed_tasks_are_separated_from_active_today_groups(): void
    {
        [$user, $workspace, $project] = $this->context();
        $active = $this->task($user, $workspace, $project, ['title' => 'Active today', 'due_date' => now()]);
        $completed = $this->task($user, $workspace, $project, [
            'title' => 'Completed today',
            'due_date' => now(),
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('groups.due_today.0.id', $active->id)
                ->where('groups.completed_today.0.id', $completed->id)
                ->where('summary.completed_today', 1)
            );
    }

    public function test_my_day_shows_due_today_scheduled_today_overdue_and_missed_yesterday(): void
    {
        [$user, $workspace, $project] = $this->context();
        $overdue = $this->task($user, $workspace, $project, ['title' => 'Older overdue', 'due_date' => now()->subDays(2)]);
        $missed = $this->task($user, $workspace, $project, ['title' => 'Missed yesterday', 'due_date' => now()->subDay()]);
        $due = $this->task($user, $workspace, $project, ['title' => 'Due today', 'due_date' => now()]);
        $scheduled = $this->task($user, $workspace, $project, ['title' => 'Scheduled today', 'start_date' => now(), 'due_date' => now()->addDays(4)]);

        $this->actingAs($user)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('groups.overdue.0.id', $overdue->id)
                ->where('groups.overdue.1.id', $missed->id)
                ->where('groups.missed_yesterday.0.id', $missed->id)
                ->where('groups.due_today.0.id', $due->id)
                ->where('groups.scheduled_today.0.id', $scheduled->id)
                ->where('groups.missed_yesterday.0.missed_yesterday', true)
                ->where('summary.missed_yesterday', 1)
            );
    }

    public function test_my_day_quick_actions_move_tasks_without_silent_changes(): void
    {
        [$user, $workspace, $project] = $this->context();
        $task = $this->task($user, $workspace, $project, ['due_date' => now()->subDay()]);

        $this->actingAs($user)->patch(route('today.tasks.today', $task))->assertRedirect();
        $this->assertSame(now()->toDateString(), $task->refresh()->due_date->toDateString());

        $this->actingAs($user)->patch(route('today.tasks.tomorrow', $task))->assertRedirect();
        $this->assertSame(now()->addDay()->toDateString(), $task->refresh()->due_date->toDateString());

        $this->actingAs($user)->patch(route('today.tasks.snooze', $task))->assertRedirect();
        $this->assertSame(now()->addDays(3)->toDateString(), $task->refresh()->due_date->toDateString());
    }

    public function test_dashboard_exposes_todays_focus_reading_and_missed_warning(): void
    {
        [$user, $workspace, $project] = $this->context();
        $this->seed(SpiritualBibleReadingPlanSeeder::class);
        $focus = $this->task($user, $workspace, $project, ['title' => 'Urgent today', 'priority' => 'urgent', 'due_date' => now()]);
        $this->task($user, $workspace, $project, ['title' => 'Missed yesterday', 'due_date' => now()->subDay()]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('focus.0.id', $focus->id)
                ->where('summary.missed_yesterday', 1)
                ->where('spiritualReading.today_label', 'Genesis 1-14')
            );
    }

    public function test_same_user_date_and_type_reuses_existing_daily_review(): void
    {
        [$user, $workspace, $project] = $this->context();
        $this->task($user, $workspace, $project, ['title' => 'Today task', 'due_date' => now()]);
        $service = app(DailyReviewService::class);

        $first = $service->createMorningReview($user);
        $second = $service->createMorningReview($user);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DailyReview::query()
            ->where('user_id', $user->id)
            ->whereDate('review_date', now()->toDateString())
            ->where('type', 'morning')
            ->count());

        $service->createEveningReview($user);
        $otherUser = User::factory()->create();
        $service->createMorningReview($otherUser);

        $this->assertSame(3, DailyReview::count());
    }

    public function test_morning_command_creates_review(): void
    {
        $this->fakeSlackImageUpload();
        config(['services.slack.bot_token' => 'xoxb-test', 'services.slack.default_channel' => 'C123']);
        [$user, $workspace, $project] = $this->context();
        $this->task($user, $workspace, $project, ['title' => 'Today task', 'priority' => 'urgent', 'due_date' => now()]);

        $this->artisan('taskflow:send-daily-briefing', ['--user_id' => $user->id])->assertSuccessful();

        $this->assertDatabaseHas('daily_reviews', [
            'user_id' => $user->id,
            'type' => 'morning',
            'status' => 'sent',
        ]);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'files.getUploadURLExternal'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'files.completeUploadExternal'));
    }

    public function test_morning_command_uses_daily_user_env_when_user_option_is_missing(): void
    {
        $this->fakeSlackImageUpload();
        config(['services.slack.bot_token' => 'xoxb-test', 'services.slack.default_channel' => 'C123']);
        [$firstUser, $workspace, $project] = $this->context();
        $secondUser = User::factory()->create();
        $this->task($firstUser, $workspace, $project, ['title' => 'First user task', 'priority' => 'urgent', 'due_date' => now()]);
        $this->task($secondUser, $workspace, $project, ['title' => 'Second user task', 'priority' => 'urgent', 'due_date' => now()]);
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

    public function test_daily_briefing_image_mode_uploads_png_with_short_caption(): void
    {
        $this->fakeSlackImageUpload();
        config(['services.slack.bot_token' => 'xoxb-test', 'services.slack.default_channel' => 'C123']);
        [$user, $workspace, $project] = $this->context();
        $this->task($user, $workspace, $project, ['title' => 'Ship launch checklist', 'priority' => 'urgent', 'due_date' => now()]);

        $this->artisan('taskflow:send-daily-briefing', ['--user_id' => $user->id, '--format' => 'image'])->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'files.completeUploadExternal')
            && str_contains((string) $request->body(), 'Friday Daily Briefing - '.now()->toDateString())
            && str_contains((string) $request->body(), 'Open: 1 | Overdue: 0 | Due today: 1 | Due this week: 1')
            && str_contains((string) $request->body(), 'Focus: Ship launch checklist')
            && str_contains((string) $request->body(), 'Missed yesterday: 0'));
    }

    public function test_slack_text_payload_includes_today_focus_and_missed_yesterday(): void
    {
        [$user, $workspace, $project] = $this->context();
        $this->task($user, $workspace, $project, ['title' => 'Urgent today', 'priority' => 'urgent', 'due_date' => now()]);
        $this->task($user, $workspace, $project, ['title' => 'Missed yesterday', 'priority' => 'high', 'due_date' => now()->subDay()]);

        $review = app(DailyReviewService::class)->createMorningReview($user, ['priority' => 'urgent-high']);
        $briefing = app(DailyBriefingService::class)->build($review);
        $message = app(DailyBriefingService::class)->textMessage($briefing);

        $this->assertStringContainsString('Today focus: Missed yesterday | Urgent today', $message);
        $this->assertStringContainsString('Missed yesterday: 1', $message);
    }

    public function test_daily_briefing_removes_duplicate_tasks_across_sections(): void
    {
        [$user, $workspace, $project] = $this->context();
        $task = $this->task($user, $workspace, $project, ['title' => 'Duplicated task', 'priority' => 'urgent', 'due_date' => now()->subDay()]);
        $review = $this->reviewWithTypedItems($user, [
            [$task, 'focus'],
            [$task, 'overdue'],
        ]);

        $briefing = app(DailyBriefingService::class)->build($review);

        $this->assertCount(1, $briefing['sections']['focus']);
        $this->assertCount(0, $briefing['sections']['overdue']);
    }

    public function test_daily_briefing_displayed_tasks_are_capped(): void
    {
        [$user, $workspace, $project] = $this->context();
        $typedTasks = [];

        foreach (range(1, 8) as $index) {
            $typedTasks[] = [
                $this->task($user, $workspace, $project, [
                    'title' => "Overdue {$index}",
                    'priority' => 'urgent',
                    'due_date' => now()->subDay(),
                ]),
                'overdue',
            ];
        }

        $review = $this->reviewWithTypedItems($user, $typedTasks);
        $briefing = app(DailyBriefingService::class)->build($review, ['limit' => 4]);

        $this->assertCount(4, $briefing['sections']['overdue']);
    }

    public function test_daily_briefing_portfolio_summary_includes_launch_portfolios(): void
    {
        [$user, $workspace, $project] = $this->context();
        $sayara = Portfolio::create(['workspace_id' => $workspace->id, 'name' => 'SayaraForce', 'slug' => 'sayaraforce']);
        $church = Portfolio::create(['workspace_id' => $workspace->id, 'name' => 'ChurchForce', 'slug' => 'churchforce']);
        $project->update(['portfolio_id' => $sayara->id]);
        $this->task($user, $workspace, $project, ['portfolio_id' => $sayara->id, 'priority' => 'urgent', 'due_date' => now()]);
        $this->task($user, $workspace, $project, ['portfolio_id' => $church->id, 'priority' => 'high', 'due_date' => now()->subDay()]);
        $review = app(DailyReviewService::class)->createMorningReview($user, ['priority' => 'urgent-high']);

        $briefing = app(DailyBriefingService::class)->build($review);

        $this->assertSame('SayaraForce', $briefing['portfolio_summary'][0]['portfolio']);
        $this->assertSame(1, $briefing['portfolio_summary'][0]['due_today']);
        $this->assertSame('ChurchForce', $briefing['portfolio_summary'][1]['portfolio']);
        $this->assertSame(1, $briefing['portfolio_summary'][1]['overdue']);
    }

    public function test_daily_briefing_falls_back_to_text_caption_when_image_generation_fails(): void
    {
        config(['services.slack.bot_token' => 'xoxb-test', 'services.slack.default_channel' => 'C123']);
        Http::fake(['slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'channel' => 'C123', 'ts' => '123.456'])]);
        $this->app->instance(DailyBriefingImageService::class, new class extends DailyBriefingImageService
        {
            public function generate(array $briefing): string
            {
                throw new \RuntimeException('Image renderer unavailable.');
            }
        });
        [$user, $workspace, $project] = $this->context();
        $this->task($user, $workspace, $project, ['priority' => 'urgent', 'due_date' => now()]);

        $this->artisan('taskflow:send-daily-briefing', ['--user_id' => $user->id])->assertSuccessful();

        $this->assertDatabaseHas('daily_reviews', [
            'user_id' => $user->id,
            'type' => 'morning',
            'status' => 'sent',
        ]);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat.postMessage')
            && str_contains((string) $request->body(), 'Friday Daily Briefing - '.now()->toDateString()));
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

    private function reviewWithTypedItems(User $user, array $items): DailyReview
    {
        $review = DailyReview::create([
            'user_id' => $user->id,
            'review_date' => now()->toDateString(),
            'type' => 'morning',
            'status' => 'pending',
        ]);

        foreach ($items as $index => [$task, $type]) {
            $review->items()->create([
                'task_id' => $task->id,
                'position' => $index + 1,
                'item_type' => $type,
                'snapshot_title' => $task->title,
                'snapshot_status' => $task->status,
                'snapshot_priority' => $task->priority,
                'snapshot_due_date' => $task->due_date,
            ]);
        }

        return $review->load(['items.task.project', 'items.task.portfolio']);
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

    private function fakeSlackImageUpload(): void
    {
        Http::fake([
            'slack.com/api/files.getUploadURLExternal' => Http::response([
                'ok' => true,
                'upload_url' => 'https://uploads.slack.test/daily-briefing',
                'file_id' => 'F123',
            ]),
            'uploads.slack.test/*' => Http::response('', 200),
            'slack.com/api/files.completeUploadExternal' => Http::response([
                'ok' => true,
                'channel' => 'C123',
                'ts' => '123.456',
            ]),
        ]);
    }
}
