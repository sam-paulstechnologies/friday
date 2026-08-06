<?php

namespace Tests\Feature;

use App\Models\MiriamReminder;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Tasks\TaskTransitionService;
use App\Support\OperationalClock;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The Phase 1 daily loop, end to end:
 *
 *   capture -> Inbox -> clarify/convert -> Today -> complete -> Completed
 *
 * Every Slack interaction here is a locally signed fake and every outbound
 * HTTP call is faked, so running this suite sends nothing anywhere.
 */
class MiriamDailyLoopTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'http://taskflow.test',
            'app.operational_timezone' => 'Asia/Dubai',
            'services.slack.signing_secret' => 'test-signing-secret',
            'services.slack.bot_token' => 'xoxb-test',
            'services.slack.allowed_user_id' => 'U123',
            'services.slack.miriam_channel_id' => 'CMIRIAM',
            'services.slack.default_channel' => null,
            'services.slack.daily_user_id' => null,
            'services.slack.webhook_url' => null,
        ]);

        $this->travelToDubai('2026-06-23 12:00:00');

        [$this->user, $this->workspace] = $this->userWithWorkspace();

        Http::fake(['slack.com/*' => Http::response(['ok' => true, 'channel' => 'CMIRIAM', 'ts' => '1.1'])]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------- capture

    public function test_signed_slack_capture_creates_one_inbox_item(): void
    {
        $this->postSignedSlackEvent('Remind me to call Julian tomorrow at 10 AM.')
            ->assertOk()
            ->assertJson(['status' => 'needs_confirmation']);

        $inbox = app(\App\Services\Inbox\InboxService::class)->items($this->user);

        $this->assertCount(1, $inbox['open']);
        $this->assertSame('unprocessed', $inbox['open'][0]['state']);
        $this->assertSame('Slack', $inbox['open'][0]['capture_source']);
        $this->assertSame('Remind me to call Julian tomorrow at 10 AM.', $inbox['open'][0]['original_text']);
        $this->assertDatabaseCount('miriam_reminders', 1);
    }

    public function test_replaying_the_same_slack_event_does_not_duplicate_the_capture(): void
    {
        $this->postSignedSlackEvent('Remind me to call Julian tomorrow at 10 AM.', eventId: 'Ev123')->assertOk();

        $this->postSignedSlackEvent('Remind me to call Julian tomorrow at 10 AM.', eventId: 'Ev123')
            ->assertOk()
            ->assertJson(['ignored' => 'duplicate_event']);

        $this->assertDatabaseCount('miriam_reminders', 1);
        $this->assertCount(1, app(\App\Services\Inbox\InboxService::class)->items($this->user)['open']);
    }

    public function test_inbox_page_displays_the_capture(): void
    {
        $this->postSignedSlackEvent('Remind me to call Julian tomorrow at 10 AM.')->assertOk();

        $this->actingAs($this->user)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inbox/Index')
                ->where('inbox.counts.open', 1)
                ->where('inbox.open.0.title', 'Call Julian')
                ->where('inbox.open.0.original_text', 'Remind me to call Julian tomorrow at 10 AM.')
                ->where('inbox.open.0.capture_source', 'Slack')
                ->where('inbox.open.0.state', 'unprocessed')
            );
    }

    public function test_inbox_shows_an_empty_state_when_nothing_is_captured(): void
    {
        $this->actingAs($this->user)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inbox/Index')
                ->where('inbox.counts.open', 0)
                ->where('inbox.open', [])
            );
    }

    // ---------------------------------------------------------- conversion

    public function test_converting_a_capture_creates_one_task_and_preserves_the_original_wording(): void
    {
        $this->postSignedSlackEvent('Remind me to call Julian tomorrow at 10 AM.')->assertOk();
        $reminder = MiriamReminder::firstOrFail();

        $this->actingAs($this->user)
            ->post(route('inbox.convert', ['capture', $reminder->id]), [
                'title' => 'Call Julian about the contract',
                'destination' => TaskTransitionService::MOVE_TASKS,
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('tasks', 1);

        $task = Task::firstOrFail();

        $this->assertSame('Call Julian about the contract', $task->title);
        $this->assertStringContainsString('Remind me to call Julian tomorrow at 10 AM.', (string) $task->description);
        $this->assertSame(Task::WORKFLOW_TASKS, $task->workflow_state);
        $this->assertSame($task->id, $reminder->fresh()->task_id);
        $this->assertSame($reminder->id, $task->source_metadata['capture_reminder_id']);
        $this->assertSame($this->user->id, $task->source_metadata['converted_by_user_id']);
    }

    public function test_repeating_a_conversion_does_not_create_a_second_task(): void
    {
        $this->postSignedSlackEvent('Remind me to call Julian tomorrow at 10 AM.')->assertOk();
        $reminder = MiriamReminder::firstOrFail();

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($this->user)
                ->post(route('inbox.convert', ['capture', $reminder->id]), ['destination' => TaskTransitionService::MOVE_TASKS])
                ->assertRedirect();
        }

        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_converted_capture_no_longer_counts_as_unresolved(): void
    {
        $this->postSignedSlackEvent('Remind me to call Julian tomorrow at 10 AM.')->assertOk();
        $reminder = MiriamReminder::firstOrFail();

        $this->actingAs($this->user)
            ->post(route('inbox.convert', ['capture', $reminder->id]), ['destination' => TaskTransitionService::MOVE_TASKS]);

        $inbox = app(\App\Services\Inbox\InboxService::class)->items($this->user);

        $this->assertSame(0, $inbox['counts']['open']);
        $this->assertSame(1, $inbox['counts']['converted']);
        // Nothing is deleted: the resolved capture is still on the record.
        $this->assertCount(1, $inbox['resolved']);
    }

    public function test_dismissing_a_capture_keeps_the_original_wording(): void
    {
        $this->postSignedSlackEvent('Remind me to call Julian tomorrow at 10 AM.')->assertOk();
        $reminder = MiriamReminder::firstOrFail();

        $this->actingAs($this->user)
            ->post(route('inbox.dismiss', ['capture', $reminder->id]))
            ->assertRedirect();

        $reminder->refresh();

        $this->assertSame('cancelled', $reminder->status);
        $this->assertSame('Remind me to call Julian tomorrow at 10 AM.', $reminder->metadata['original_text']);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_conversion_only_attaches_a_project_that_actually_exists(): void
    {
        $project = Project::create([
            'workspace_id' => $this->workspace->id,
            'owner_id' => $this->user->id,
            'name' => 'CF Football Academy',
            'slug' => 'cf-football-academy',
            'status' => 'active',
            'visibility' => 'workspace',
        ]);

        $this->postSignedSlackEvent('Remind me to call Julian tomorrow at 10 AM.')->assertOk();
        $reminder = MiriamReminder::firstOrFail();

        // A project id that does not belong to this operator is discarded
        // rather than attached.
        $this->actingAs($this->user)
            ->post(route('inbox.convert', ['capture', $reminder->id]), [
                'project_id' => $project->id + 999,
                'destination' => TaskTransitionService::MOVE_TASKS,
            ])
            ->assertRedirect();

        $this->assertNull(Task::firstOrFail()->project_id);
    }

    // --------------------------------------------------------------- today

    public function test_moving_an_inbox_item_to_today_makes_it_appear_in_today(): void
    {
        $this->postSignedSlackEvent('Remind me to call Julian tomorrow at 10 AM.')->assertOk();
        $reminder = MiriamReminder::firstOrFail();

        $this->actingAs($this->user)
            ->post(route('inbox.move', ['capture', $reminder->id]), ['destination' => TaskTransitionService::MOVE_TODAY])
            ->assertRedirect();

        $task = Task::firstOrFail();

        $this->assertSame(Task::WORKFLOW_TODAY, $task->workflow_state);
        $this->assertSame('2026-06-23', $task->due_date->toDateString());

        $this->actingAs($this->user)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Today/Index')
                ->where('groups.due_today.0.id', $task->id)
            );
    }

    public function test_task_due_today_in_dubai_appears_in_today_during_the_utc_boundary_window(): void
    {
        // 00:30 Dubai is still the previous calendar day in UTC. This is the
        // window in which "today" used to silently mean yesterday.
        $this->travelToDubai('2026-06-24 00:30:00');

        $task = $this->task(['due_date' => '2026-06-24', 'title' => 'Due today in Dubai']);

        $this->actingAs($this->user)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('today.date', '2026-06-24')
                ->where('groups.due_today.0.id', $task->id)
                ->where('groups.overdue', [])
            );
    }

    public function test_late_evening_in_dubai_still_reports_the_same_operational_day(): void
    {
        $this->travelToDubai('2026-06-23 23:59:00');

        $task = $this->task(['due_date' => '2026-06-23', 'title' => 'Due tonight']);

        $this->actingAs($this->user)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('today.date', '2026-06-23')
                ->where('groups.due_today.0.id', $task->id)
            );
    }

    public function test_task_due_tomorrow_in_dubai_does_not_appear_today(): void
    {
        $this->travelToDubai('2026-06-24 00:01:00');

        $this->task(['due_date' => '2026-06-25', 'title' => 'Due tomorrow']);

        $this->actingAs($this->user)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('groups.due_today', [])
                ->where('groups.overdue', [])
            );
    }

    public function test_yesterdays_task_is_overdue_from_the_first_minute_of_the_dubai_day(): void
    {
        $this->travelToDubai('2026-06-24 00:01:00');

        $task = $this->task(['due_date' => '2026-06-23', 'title' => 'Missed yesterday']);

        $this->actingAs($this->user)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('groups.overdue.0.id', $task->id)
                ->where('groups.due_today', [])
            );
    }

    public function test_operational_clock_converts_a_dubai_day_into_a_utc_range(): void
    {
        $this->travelToDubai('2026-06-24 02:00:00');

        $clock = app(OperationalClock::class);

        $this->assertSame('2026-06-24', $clock->todayString());
        // 00:00-04:00 Dubai maps back into the previous UTC day.
        $this->assertSame('2026-06-23 20:00:00', $clock->startOfDayUtc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-24 19:59:59', $clock->endOfDayUtc()->format('Y-m-d H:i:s'));
    }

    public function test_a_task_completed_during_the_boundary_window_counts_as_completed_today(): void
    {
        $this->travelToDubai('2026-06-24 01:00:00');

        $task = $this->task(['due_date' => '2026-06-24']);

        $this->actingAs($this->user)->patch(route('tasks.complete', $task))->assertRedirect();

        $this->actingAs($this->user)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.completed_today', 1)
            );
    }

    // --------------------------------------------------------- transitions

    public function test_completing_from_today_removes_the_task_from_active_today(): void
    {
        $task = $this->task(['due_date' => '2026-06-23', 'section' => Task::WORKFLOW_TODAY]);

        $this->actingAs($this->user)
            ->from(route('today.index'))
            ->patch(route('tasks.complete', $task))
            // Completing stays where the operator was instead of ejecting them.
            ->assertRedirect(route('today.index'));

        $this->assertSame('completed', $task->refresh()->status);

        $this->actingAs($this->user)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('groups.due_today', [])
                ->where('groups.overdue', [])
                ->where('summary.completed_today', 1)
            );
    }

    public function test_completed_task_appears_under_completed_in_the_task_list(): void
    {
        $task = $this->task(['due_date' => '2026-06-23']);

        $this->actingAs($this->user)->patch(route('tasks.complete', $task));

        $this->actingAs($this->user)
            ->get(route('tasks.index', ['view' => 'completed']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tasks.data.0.id', $task->id)
                ->where('viewCounts.completed', 1)
            );

        // ...and it is gone from the active views.
        $this->actingAs($this->user)
            ->get(route('tasks.index', ['view' => 'today']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('tasks.data', []));
    }

    public function test_reopening_a_completed_task_restores_it_to_an_active_state(): void
    {
        $task = $this->task(['due_date' => '2026-06-23']);

        $this->actingAs($this->user)->patch(route('tasks.complete', $task));
        $this->actingAs($this->user)->patch(route('tasks.restore', $task))->assertRedirect();

        $task->refresh();

        $this->assertSame('todo', $task->status);
        $this->assertNull($task->completed_at);
        $this->assertNotSame(Task::WORKFLOW_INBOX, $task->workflow_state);
    }

    public function test_waiting_and_later_transitions_persist(): void
    {
        $waiting = $this->task(['due_date' => '2026-06-23', 'title' => 'Waiting item']);
        $later = $this->task(['due_date' => '2026-06-23', 'title' => 'Later item']);

        $this->actingAs($this->user)->patch(route('today.tasks.waiting', $waiting))->assertRedirect();
        $this->actingAs($this->user)->patch(route('today.tasks.later', $later))->assertRedirect();

        $this->assertSame(Task::WORKFLOW_WAITING, $waiting->refresh()->workflow_state);
        $this->assertSame('waiting_for', $waiting->task_type);
        $this->assertSame(Task::WORKFLOW_LATER, $later->refresh()->workflow_state);
    }

    public function test_an_invalid_transition_is_rejected_and_reported(): void
    {
        $task = $this->task(['due_date' => '2026-06-23']);

        // Reopening something that was never closed is not a legal move.
        $this->expectException(\App\Services\Tasks\InvalidTaskTransitionException::class);

        app(TaskTransitionService::class)->apply($task, TaskTransitionService::REOPEN, $this->user);
    }

    public function test_a_completed_task_cannot_be_re_bucketed_without_reopening_it(): void
    {
        $task = $this->task(['due_date' => '2026-06-23']);
        app(TaskTransitionService::class)->apply($task, TaskTransitionService::COMPLETE, $this->user);

        $this->actingAs($this->user)
            ->from(route('today.index'))
            ->patch(route('today.tasks.later', $task))
            ->assertRedirect(route('today.index'))
            ->assertSessionHas('error');

        $this->assertSame('completed', $task->refresh()->status);
    }

    public function test_completing_twice_is_idempotent(): void
    {
        $task = $this->task(['due_date' => '2026-06-23']);

        $this->actingAs($this->user)->patch(route('tasks.complete', $task));
        $completedAt = $task->refresh()->completed_at;

        $this->actingAs($this->user)->patch(route('tasks.complete', $task));

        $this->assertEquals($completedAt, $task->refresh()->completed_at);
        $this->assertSame(1, $task->activities()->where('action', 'task_completed')->count());
    }

    // ------------------------------------------------------- authorization

    public function test_a_task_belonging_to_another_user_cannot_be_updated_by_id(): void
    {
        [$intruder] = $this->userWithWorkspace('intruder');
        $task = $this->task(['due_date' => '2026-06-23']);

        $this->actingAs($intruder)->patch(route('tasks.complete', $task))->assertForbidden();
        $this->actingAs($intruder)->patch(route('today.tasks.today', $task))->assertForbidden();
        $this->actingAs($intruder)->patch(route('today.tasks.later', $task))->assertForbidden();
        $this->actingAs($intruder)->patch(route('today.tasks.waiting', $task))->assertForbidden();
        $this->actingAs($intruder)->post(route('inbox.convert', ['task', $task->id]))->assertForbidden();
        $this->actingAs($intruder)->post(route('inbox.dismiss', ['task', $task->id]))->assertForbidden();

        $task->refresh();

        $this->assertSame('todo', $task->status);
        $this->assertNull($task->workflow_state);
    }

    public function test_a_capture_belonging_to_another_user_cannot_be_converted_by_id(): void
    {
        [$intruder] = $this->userWithWorkspace('intruder');
        $this->postSignedSlackEvent('Remind me to call Julian tomorrow at 10 AM.')->assertOk();
        $reminder = MiriamReminder::firstOrFail();

        $this->actingAs($intruder)
            ->post(route('inbox.convert', ['capture', $reminder->id]))
            ->assertForbidden();

        $this->assertDatabaseCount('tasks', 0);
        $this->assertNull($reminder->fresh()->task_id);
    }

    public function test_the_removed_bulk_prioritization_endpoint_no_longer_exists(): void
    {
        // It bulk-updated any task id with no ownership check and had no page.
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('prioritization-review.apply'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('prioritization-review.index'));

        $this->actingAs($this->user)
            ->patch('/prioritization-review/apply', ['task_ids' => [1], 'status' => 'archived', 'confirmation' => 1])
            ->assertNotFound();
    }

    // --------------------------------------------------------------- slack

    public function test_slack_webhook_rejects_an_invalid_signature(): void
    {
        $this->withHeaders([
            'X-Slack-Request-Timestamp' => (string) time(),
            'X-Slack-Signature' => 'v0=bad',
        ])
            ->postJson(route('webhooks.slack.events'), ['event' => ['type' => 'message', 'text' => 'done 1']])
            ->assertStatus(403);
    }

    public function test_slack_webhook_rejects_an_expired_timestamp(): void
    {
        $stale = (string) (time() - 3600);
        $payload = json_encode(['event' => ['type' => 'message', 'text' => 'done 1']]);

        $this->call('POST', route('webhooks.slack.events', absolute: false), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SLACK_REQUEST_TIMESTAMP' => $stale,
            'HTTP_X_SLACK_SIGNATURE' => 'v0='.hash_hmac('sha256', "v0:{$stale}:{$payload}", 'test-signing-secret'),
        ], $payload)->assertStatus(403);
    }

    public function test_slack_webhook_answers_url_verification(): void
    {
        $this->postSignedWebhook(['type' => 'url_verification', 'challenge' => 'abc123'])
            ->assertOk()
            ->assertJson(['challenge' => 'abc123']);
    }

    public function test_slack_webhook_acknowledges_an_unknown_event_without_failing(): void
    {
        $this->postSignedWebhook([
            'event_id' => 'Ev-unknown-1',
            'event' => ['type' => 'reaction_added', 'channel' => 'C123', 'user' => 'U123', 'text' => ''],
        ])
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_slack_webhook_does_not_claim_a_development_command_ran(): void
    {
        $response = $this->postSignedWebhook([
            'event_id' => 'Ev-dev-1',
            'event' => ['type' => 'message', 'channel' => 'C123', 'user' => 'U123', 'text' => 'miriam dev go'],
        ]);

        $response->assertOk()->assertJson(['ignored' => 'capability_unavailable']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat.postMessage')
            && str_contains((string) $request->body(), 'not available in this build')
            && str_contains((string) $request->body(), 'Nothing was started'));
    }

    public function test_slack_webhook_deduplicates_a_replayed_event_id(): void
    {
        $payload = [
            'event_id' => 'Ev-dup-1',
            'event' => ['type' => 'message', 'channel' => 'C123', 'user' => 'U123', 'text' => 'help'],
        ];

        $this->postSignedWebhook($payload)->assertOk()->assertJson(['handled' => 'help']);
        $this->postSignedWebhook($payload)->assertOk()->assertJson(['ignored' => 'duplicate_event']);
    }

    // ----------------------------------------------------------- reminders

    public function test_reminder_acknowledgement_is_idempotent(): void
    {
        $reminder = $this->pendingReminder('call the bank');

        $this->postSignedReminderAction('miriam_reminder_done', $reminder->id)
            ->assertOk()
            ->assertJson(['text' => 'Done - call the bank']);

        $first = $reminder->fresh();

        $this->postSignedReminderAction('miriam_reminder_done', $reminder->id)
            ->assertOk()
            ->assertJson(['text' => 'Done - call the bank']);

        $second = $reminder->fresh();

        $this->assertSame('done', $second->status);
        $this->assertEquals($first->completed_at, $second->completed_at);
        // Acknowledgement stops delivery for this occurrence.
        $this->assertNull($second->next_reminder_at);
    }

    public function test_reminder_snooze_is_idempotent_and_creates_one_next_occurrence(): void
    {
        $reminder = $this->pendingReminder('check the oven');

        $this->postSignedReminderAction('miriam_reminder_snooze_15', $reminder->id)->assertOk();
        $firstNext = $reminder->fresh()->next_reminder_at;

        $this->postSignedReminderAction('miriam_reminder_snooze_15', $reminder->id)->assertOk();
        $second = $reminder->fresh();

        $this->assertSame('snoozed', $second->status);
        $this->assertEquals($firstNext, $second->next_reminder_at);
        $this->assertSame(1, $second->events()->where('event_type', 'reminder_snoozed')->count());
    }

    public function test_an_acknowledged_reminder_is_not_delivered_again(): void
    {
        $reminder = $this->pendingReminder('call the bank');
        $this->postSignedReminderAction('miriam_reminder_done', $reminder->id)->assertOk();

        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->artisan('miriam:send-reminders')->assertExitCode(0);

        $this->assertSame(0, (int) $reminder->fresh()->reminder_attempts);
        Http::assertNothingSent();
    }

    public function test_the_scheduler_does_not_deliver_the_same_occurrence_twice(): void
    {
        $reminder = $this->pendingReminder('call the bank');

        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->artisan('miriam:send-reminders')->assertExitCode(0);
        $afterFirst = $reminder->fresh()->reminder_attempts;

        // Overlapping scheduler runs in the same minute must not re-poke the
        // same occurrence.
        $this->artisan('miriam:send-reminders')->assertExitCode(0);
        $this->artisan('miriam:send-reminders')->assertExitCode(0);

        $this->assertSame(1, (int) $afterFirst);
        $this->assertSame(1, (int) $reminder->fresh()->reminder_attempts);
        Http::assertSentCount(1);
    }

    public function test_today_shows_a_due_reminder(): void
    {
        $this->pendingReminder('call the bank');

        $this->actingAs($this->user)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('commandCenter.reminders.0.title', 'call the bank')
                ->where('commandCenter.reminders.0.state', 'due')
            );
    }

    // ---------------------------------------------------------- navigation

    public function test_every_primary_navigation_route_resolves(): void
    {
        $routes = [
            'today.index',
            'inbox.index',
            'tasks.index',
            'health.index',
            'spiritual.index',
            'notifications.index',
            'projects.index',
            'planner.index',
            'reports.index',
            'task-review.index',
            'waiting.index',
            'decisions.index',
            'blockers.index',
            'risks.index',
            'approvals.index',
            'notes.index',
            'dashboard',
            'operations-center.index',
            'assistant.index',
            'agents.index',
            'agents.orchestrator.index',
            'agents.task-capture.index',
            'settings.workspace.edit',
            'settings.integrations.index',
            'settings.system-health.index',
            'settings.automations.index',
            'settings.ai.edit',
            'admin.custom-fields.index',
            'templates.index',
        ];

        foreach ($routes as $name) {
            $this->assertTrue(\Illuminate\Support\Facades\Route::has($name), "Route [{$name}] is missing.");

            $this->actingAs($this->user)
                ->get(route($name))
                ->assertSuccessful();
        }
    }

    public function test_today_reports_codex_as_unavailable_rather_than_idle(): void
    {
        $this->actingAs($this->user)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('commandCenter.codex_workstream.available', false)
                ->where('commandCenter.codex_workstream.status_label', 'Codex not available')
            );
    }

    // ------------------------------------------------------------- helpers

    private function travelToDubai(string $local): void
    {
        $moment = CarbonImmutable::parse($local, 'Asia/Dubai');

        CarbonImmutable::setTestNow($moment);
        Carbon::setTestNow(Carbon::parse($moment->utc()->toDateTimeString(), 'UTC'));
    }

    private function userWithWorkspace(string $prefix = 'owner'): array
    {
        $user = User::factory()->create(['email' => "{$prefix}-".uniqid().'@example.test']);
        $workspace = Workspace::create([
            'name' => ucfirst($prefix).' Workspace',
            'slug' => $prefix.'-workspace-'.uniqid(),
            'created_by' => $user->id,
        ]);

        return [$user, $workspace];
    }

    private function task(array $overrides = []): Task
    {
        return Task::create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Daily loop task',
            'status' => 'todo',
            'priority' => 'medium',
            'assignee_id' => $this->user->id,
            'reporter_id' => $this->user->id,
            ...$overrides,
        ]);
    }

    private function pendingReminder(string $title): MiriamReminder
    {
        return MiriamReminder::create([
            'user_id' => $this->user->id,
            'category' => 'personal',
            'title' => $title,
            'timezone' => 'Asia/Dubai',
            'due_at' => CarbonImmutable::now('UTC'),
            'status' => 'pending',
            'next_reminder_at' => CarbonImmutable::now('UTC'),
            'slack_channel_id' => 'CMIRIAM',
            'slack_user_id' => 'U123',
        ]);
    }

    private function postSignedSlackEvent(string $text, ?string $eventId = null, string $ts = '1710000000.000100')
    {
        $payload = json_encode(array_filter([
            'team_id' => 'T123',
            'event_id' => $eventId,
            'event' => [
                'type' => 'message',
                'channel' => 'CMIRIAM',
                'user' => 'U123',
                'text' => $text,
                'ts' => $ts,
            ],
        ]));

        return $this->postSigned(route('slack.events', absolute: false), $payload);
    }

    private function postSignedWebhook(array $body)
    {
        return $this->postSigned(route('webhooks.slack.events', absolute: false), json_encode($body));
    }

    private function postSignedReminderAction(string $actionId, int $reminderId)
    {
        $body = http_build_query([
            'payload' => json_encode([
                'type' => 'block_actions',
                'response_url' => 'https://hooks.slack.com/actions/T123/ABC',
                'channel' => ['id' => 'CMIRIAM'],
                'message' => ['ts' => '1710000000.000200'],
                'user' => ['id' => 'U123'],
                'actions' => [['action_id' => $actionId, 'value' => (string) $reminderId]],
            ]),
        ]);

        return $this->postSigned(route('slack.events', absolute: false), $body, 'application/x-www-form-urlencoded');
    }

    /** Locally signed with the test secret — no request ever leaves the machine. */
    private function postSigned(string $uri, string $payload, string $contentType = 'application/json')
    {
        $timestamp = (string) time();
        $signature = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$payload}", 'test-signing-secret');

        return $this->call('POST', $uri, [], [], [], [
            'CONTENT_TYPE' => $contentType,
            'HTTP_X_SLACK_REQUEST_TIMESTAMP' => $timestamp,
            'HTTP_X_SLACK_SIGNATURE' => $signature,
        ], $payload);
    }
}
