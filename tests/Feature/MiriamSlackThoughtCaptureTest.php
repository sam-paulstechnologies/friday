<?php

namespace Tests\Feature;

use App\Models\MiriamReminder;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Agents\TaskCaptureAgent;
use App\Services\MiriamReminderService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class MiriamSlackThoughtCaptureTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'http://taskflow.test',
            'services.slack.signing_secret' => 'test-signing-secret',
            'services.slack.bot_token' => 'xoxb-test',
            'services.slack.allowed_user_id' => 'U123',
            'services.slack.miriam_channel_id' => 'CMIRIAM',
            'services.slack.webhook_url' => null,
            'services.miriam_capture.second_poke_minutes' => 30,
            'services.miriam_capture.final_poke_minutes' => 120,
            'services.miriam_capture.max_pokes' => 3,
            'services.miriam_capture.pending_confirmation_minutes' => 720,
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-23 12:00:00', 'Asia/Dubai'));
        Carbon::setTestNow(Carbon::parse('2026-06-23 08:00:00', 'UTC'));

        [$this->user, $this->workspace] = $this->userWithWorkspace();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_natural_language_reminder_is_parsed_and_waits_for_slack_confirmation(): void
    {
        $this->project('CF Football Academy');
        Http::fake(['slack.com/*' => Http::response(['ok' => true, 'channel' => 'CMIRIAM', 'ts' => '1.1'])]);

        $this->postSignedSlackEvent(
            'Remind me to call Julian tomorrow at 10 AM about CF Football Academy.',
            ts: '1710000000.000100'
        )
            ->assertOk()
            ->assertJson([
                'status' => 'needs_confirmation',
                'needs_confirmation' => true,
            ]);

        $reminder = MiriamReminder::firstOrFail();

        $this->assertSame('awaiting_confirmation', $reminder->status);
        $this->assertSame('Call Julian', $reminder->title);
        $this->assertSame('CF Football Academy', $reminder->metadata['project_name']);
        $this->assertSame('2026-06-24 06:00:00', $reminder->due_at->format('Y-m-d H:i:s'));
        $this->assertNull($reminder->next_reminder_at);
        $this->assertDatabaseCount('tasks', 0);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $labels = collect(data_get($payload, 'blocks.1.elements', []))->pluck('text.text')->all();

            return $request->url() === 'https://slack.com/api/chat.postMessage'
                && str_contains($payload['text'], '*Captured: Call Julian*')
                && str_contains($payload['text'], 'Project: CF Football Academy')
                && str_contains($payload['text'], 'Due: Jun 24, 10:00 AM')
                && in_array('Confirm', $labels, true)
                && in_array('Edit', $labels, true)
                && in_array('Cancel', $labels, true)
                && in_array('Move to Today', $labels, true);
        });
    }

    public function test_confirm_creates_one_task_and_schedules_the_reminder(): void
    {
        $project = $this->project('CF Football Academy');
        Http::fake([
            'slack.com/*' => Http::response(['ok' => true, 'channel' => 'CMIRIAM', 'ts' => '1.1']),
            'https://hooks.slack.com/*' => Http::response(['ok' => true]),
        ]);

        $this->postSignedSlackEvent('Remind me to call Julian tomorrow at 10 AM about CF Football Academy.');
        $reminder = MiriamReminder::firstOrFail();

        $response = $this->postSignedReminderAction('miriam_capture_confirm', $reminder->id)
            ->assertOk();

        $this->assertStringContainsString('Confirmed: Call Julian.', $response->json('text'));

        $task = Task::firstOrFail();
        $reminder->refresh();

        $this->assertSame('Call Julian', $task->title);
        $this->assertSame($project->id, $task->project_id);
        $this->assertSame('slack', $task->source);
        $this->assertSame('2026-06-24', $task->due_date->toDateString());
        $this->assertSame('pending', $reminder->status);
        $this->assertSame($task->id, $reminder->task_id);
        $this->assertSame('2026-06-24 06:00:00', $reminder->next_reminder_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('miriam_reminder_events', ['event_type' => 'capture_confirmed']);
        $this->assertDatabaseHas('miriam_reminder_events', ['event_type' => 'reminder_scheduled']);
    }

    public function test_task_and_reminder_prefixes_are_supported(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('Task: send the Wesley invoice tonight', ts: '1710000000.000101')
            ->assertOk()
            ->assertJson(['status' => 'needs_confirmation']);

        $taskReminder = MiriamReminder::firstOrFail();
        $this->assertSame('Task', $taskReminder->metadata['display_type']);
        $this->assertSame('Send the Wesley invoice', $taskReminder->title);
        $this->assertSame('2026-06-23 15:00:00', $taskReminder->due_at->format('Y-m-d H:i:s'));

        $this->postSignedSlackEvent('Remember to check the DigitalOcean backup on Friday', ts: '1710000000.000102')
            ->assertOk();

        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseHas('tasks', [
            'title' => 'Check the DigitalOcean backup',
            'source' => 'slack',
        ]);
    }

    public function test_idea_without_date_goes_to_inbox_without_scheduling_a_reminder(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('Thought: add attendance alerts to CF Football Academy.', ts: '1710000000.000103')
            ->assertOk()
            ->assertJson(['status' => 'task_created_no_reminder']);

        $task = Task::firstOrFail();

        $this->assertDatabaseCount('miriam_reminders', 0);
        $this->assertSame('Add attendance alerts to CF Football Academy', $task->title);
        // The capture bucket now lives in its own column; `section` is
        // reserved for the operator's project grouping labels.
        $this->assertSame('inbox', $task->workflow_state);
        $this->assertNull($task->section);
        $this->assertSame('slack', $task->source);
        $this->assertNull($task->due_date);

        Http::assertSent(fn ($request): bool => str_contains($request['text'], 'No reminder was scheduled.'));
    }

    public function test_relative_dates_use_asia_dubai(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('Follow up with Nikhila in two days.', ts: '1710000000.000104')
            ->assertOk();

        $reminder = MiriamReminder::firstOrFail();

        $this->assertSame('2026-06-25 05:00:00', $reminder->due_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-25', $reminder->metadata['due_date']);
        $this->assertSame('09:00', $reminder->metadata['due_time']);
    }

    public function test_duplicate_slack_event_does_not_create_duplicate_reminder_or_task(): void
    {
        Http::fake([
            'slack.com/*' => Http::response(['ok' => true]),
            'https://hooks.slack.com/*' => Http::response(['ok' => true]),
        ]);

        $text = 'Remind me to call Julian tomorrow at 10 AM about CF Football Academy.';

        $this->postSignedSlackEvent($text, ts: '1710000000.000105')->assertOk();
        $this->postSignedSlackEvent($text, ts: '1710000000.000105')->assertOk();

        $this->assertDatabaseCount('miriam_reminders', 1);
        $this->assertDatabaseCount('tasks', 0);

        $reminder = MiriamReminder::firstOrFail();
        $this->postSignedReminderAction('miriam_capture_confirm', $reminder->id)->assertOk();
        $this->postSignedReminderAction('miriam_capture_confirm', $reminder->id)->assertOk();

        $this->assertDatabaseCount('tasks', 1);
        $this->assertSame('pending', $reminder->fresh()->status);
    }

    public function test_cancel_and_expired_confirmation_do_not_activate_a_task(): void
    {
        Http::fake([
            'slack.com/*' => Http::response(['ok' => true]),
            'https://hooks.slack.com/*' => Http::response(['ok' => true]),
        ]);

        $this->postSignedSlackEvent('Remind me to call Julian tomorrow at 10 AM.', ts: '1710000000.000106')->assertOk();
        $cancelled = MiriamReminder::firstOrFail();

        $this->postSignedReminderAction('miriam_capture_cancel', $cancelled->id)
            ->assertOk()
            ->assertJson(['text' => 'Cancelled. I did not create or schedule that task.']);
        $this->postSignedReminderAction('miriam_capture_cancel', $cancelled->id)->assertOk();

        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->assertDatabaseCount('tasks', 0);

        $this->postSignedSlackEvent('Remind me to review SayaraForce pricing tomorrow at 10 AM.', ts: '1710000000.000107')->assertOk();
        $expired = MiriamReminder::query()->latest('id')->firstOrFail();
        $expired->forceFill([
            'metadata' => array_merge($expired->metadata ?? [], [
                'confirmation_expires_at' => CarbonImmutable::now('UTC')->subMinute()->toIso8601String(),
            ]),
        ])->save();

        $response = $this->postSignedReminderAction('miriam_capture_confirm', $expired->id)
            ->assertOk();

        $this->assertStringContainsString('expired', $response->json('text'));

        $this->assertSame('expired', $expired->fresh()->status);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_move_to_today_confirms_without_duplicating_the_task(): void
    {
        Http::fake([
            'slack.com/*' => Http::response(['ok' => true]),
            'https://hooks.slack.com/*' => Http::response(['ok' => true]),
        ]);

        $this->postSignedSlackEvent('Remind me to call Julian tomorrow at 10 AM.', ts: '1710000000.000108')->assertOk();
        $reminder = MiriamReminder::firstOrFail();

        $this->postSignedReminderAction('miriam_capture_move_today', $reminder->id)->assertOk();
        $this->postSignedReminderAction('miriam_capture_move_today', $reminder->id)->assertOk();

        $task = Task::firstOrFail();

        $this->assertDatabaseCount('tasks', 1);
        $this->assertSame('2026-06-23', $task->start_date->toDateString());
        $this->assertSame('2026-06-24', $task->due_date->toDateString());
        $this->assertSame($task->id, $reminder->fresh()->task_id);
    }

    public function test_classifier_failure_still_captures_the_message_to_inbox(): void
    {
        $this->mock(TaskCaptureAgent::class, function ($mock): void {
            $mock->shouldReceive('classify')->andThrow(new RuntimeException('classifier unavailable'));
        });

        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('Loose thought with no date that still matters.', ts: '1710000000.000109')
            ->assertOk()
            ->assertJson(['status' => 'task_created_no_reminder']);

        $task = Task::firstOrFail();

        $this->assertSame('Loose thought with no date that still matters', $task->title);
        $this->assertSame('fallback_after_classifier_failure', $task->source_metadata['classification']['source']);
        $this->assertDatabaseCount('miriam_reminders', 0);
    }

    public function test_scheduled_reminder_is_sent_with_poker_buttons_and_is_deduplicated(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true, 'channel' => 'CMIRIAM', 'ts' => '2.1'])]);
        $reminder = $this->pendingReminderWithTask(
            dueAt: CarbonImmutable::parse('2026-06-24 10:00:00', 'Asia/Dubai')
        );

        $this->artisan('miriam:send-reminders', ['--pretend-now' => '2026-06-24 10:00'])
            ->assertExitCode(0);
        $this->artisan('miriam:send-reminders', ['--pretend-now' => '2026-06-24 10:00'])
            ->assertExitCode(0);

        $reminder->refresh();

        $this->assertSame(1, $reminder->reminder_attempts);
        $this->assertSame('2026-06-24 06:30:00', $reminder->next_reminder_at->format('Y-m-d H:i:s'));

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $firstRow = collect(data_get($payload, 'blocks.1.elements', []))->pluck('text.text')->all();
            $secondRow = collect(data_get($payload, 'blocks.2.elements', []))->pluck('text.text')->all();

            return $payload['text'] === 'Reminder: Call Julian'
                && in_array('Done', $firstRow, true)
                && in_array('Snooze 15 min', $firstRow, true)
                && in_array('Snooze 1 hour', $firstRow, true)
                && in_array('Tonight', $secondRow, true)
                && in_array('Tomorrow', $secondRow, true)
                && in_array('Move to Today', $secondRow, true)
                && in_array('Open task', $secondRow, true);
        });
    }

    public function test_done_completes_task_and_stops_future_reminders(): void
    {
        Http::fake([
            'slack.com/*' => Http::response(['ok' => true]),
            'https://hooks.slack.com/*' => Http::response(['ok' => true]),
        ]);
        $reminder = $this->pendingReminderWithTask();

        $this->postSignedReminderAction('miriam_reminder_done', $reminder->id)
            ->assertOk()
            ->assertJson(['text' => 'Done - Call Julian']);

        $reminder->refresh();

        $this->assertSame('done', $reminder->status);
        $this->assertNull($reminder->next_reminder_at);
        $this->assertSame('completed', $reminder->task->fresh()->status);
        $this->assertDatabaseHas('miriam_reminder_events', ['event_type' => 'future_reminders_cancelled']);

        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);
        $this->artisan('miriam:send-reminders', ['--pretend-now' => '2026-06-24 10:30'])
            ->assertExitCode(0);
        Http::assertNothingSent();
    }

    public function test_snooze_and_tomorrow_reschedule_use_dubai_time(): void
    {
        Http::fake(['https://hooks.slack.com/*' => Http::response(['ok' => true])]);
        $reminder = $this->pendingReminderWithTask();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-24 10:05:00', 'Asia/Dubai'));
        Carbon::setTestNow(Carbon::parse('2026-06-24 06:05:00', 'UTC'));

        $this->postSignedReminderAction('miriam_reminder_snooze_15', $reminder->id)
            ->assertOk()
            ->assertJson(['text' => 'Snoozed until 10:20 AM - Call Julian']);

        $reminder->refresh();
        $this->assertSame('snoozed', $reminder->status);
        $this->assertSame(0, $reminder->reminder_attempts);
        $this->assertSame('2026-06-24 06:20:00', $reminder->next_reminder_at->format('Y-m-d H:i:s'));

        $this->postSignedReminderAction('miriam_reminder_tomorrow', $reminder->id)->assertOk();

        $reminder->refresh();
        $this->assertSame('2026-06-25 06:20:00', $reminder->next_reminder_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-25', $reminder->task->fresh()->due_date->toDateString());
    }

    public function test_miriam_completion_cancels_slack_reminders(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);
        $reminder = $this->pendingReminderWithTask(
            dueAt: CarbonImmutable::parse('2026-06-24 10:00:00', 'Asia/Dubai')
        );
        $task = $reminder->task;

        $task->forceFill([
            'status' => 'completed',
            'completed_at' => CarbonImmutable::now('UTC'),
        ])->save();

        app(MiriamReminderService::class)->syncAfterTaskSaved($task->fresh(), $this->user);

        $this->assertSame('done', $reminder->fresh()->status);
        $this->assertNull($reminder->fresh()->next_reminder_at);

        $this->artisan('miriam:send-reminders', ['--pretend-now' => '2026-06-24 10:00'])
            ->assertExitCode(0);
        Http::assertNothingSent();
    }

    public function test_poker_escalates_three_times_and_never_sends_a_fourth_poke(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);
        $reminder = $this->pendingReminderWithTask(
            dueAt: CarbonImmutable::parse('2026-06-24 10:00:00', 'Asia/Dubai')
        );

        $this->artisan('miriam:send-reminders', ['--pretend-now' => '2026-06-24 10:00'])->assertExitCode(0);
        $this->artisan('miriam:send-reminders', ['--pretend-now' => '2026-06-24 10:30'])->assertExitCode(0);
        $this->artisan('miriam:send-reminders', ['--pretend-now' => '2026-06-24 12:00'])->assertExitCode(0);
        $this->artisan('miriam:send-reminders', ['--pretend-now' => '2026-06-24 12:30'])->assertExitCode(0);

        $reminder->refresh();

        $this->assertSame('exhausted', $reminder->status);
        $this->assertSame(3, $reminder->reminder_attempts);
        $this->assertNull($reminder->next_reminder_at);
        $this->assertDatabaseHas('miriam_reminder_events', ['event_type' => 'reminder_escalation_exhausted']);
        Http::assertSentCount(3);
    }

    public function test_invalid_signature_and_wrong_slack_user_are_rejected(): void
    {
        Http::fake([
            'slack.com/*' => Http::response(['ok' => true]),
            'https://hooks.slack.com/*' => Http::response(['ok' => true]),
        ]);

        $this->withHeaders([
            'X-Slack-Request-Timestamp' => (string) time(),
            'X-Slack-Signature' => 'v0=bad',
        ])->postJson(route('slack.events'), [
            'event' => ['text' => 'Remind me to call Julian tomorrow at 10 AM.'],
        ])->assertStatus(401);

        config(['services.slack.allowed_user_id' => null]);
        $reminder = $this->pendingReminderWithTask();

        $this->postSignedReminderAction('miriam_reminder_done', $reminder->id, userId: 'UOTHER')
            ->assertOk()
            ->assertJson(['text' => 'You cannot change that Miriam item.']);

        $this->assertSame('pending', $reminder->fresh()->status);
        $this->assertSame('todo', $reminder->task->fresh()->status);
    }

    public function test_direct_messages_are_supported_for_capture_and_actions(): void
    {
        Http::fake([
            'slack.com/*' => Http::response(['ok' => true]),
            'https://hooks.slack.com/*' => Http::response(['ok' => true]),
        ]);

        $this->postSignedSlackEvent(
            'Remind me to call Julian tomorrow at 10 AM.',
            channel: 'D123',
            ts: '1710000000.000110',
            eventOverrides: ['channel_type' => 'im']
        )->assertOk();

        $reminder = MiriamReminder::firstOrFail();

        $response = $this->postSignedReminderAction('miriam_capture_confirm', $reminder->id, channel: 'D123')
            ->assertOk();

        $this->assertStringContainsString('Confirmed: Call Julian.', $response->json('text'));

        $this->assertDatabaseCount('tasks', 1);
        $this->assertSame('pending', $reminder->fresh()->status);
    }

    private function pendingReminderWithTask(?CarbonImmutable $dueAt = null): MiriamReminder
    {
        $dueAt ??= CarbonImmutable::parse('2026-06-24 10:00:00', 'Asia/Dubai');

        $task = Task::create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Call Julian',
            'description' => 'Captured from Slack.',
            'status' => 'todo',
            'priority' => 'medium',
            'assignee_id' => $this->user->id,
            'reporter_id' => $this->user->id,
            'due_date' => $dueAt->toDateString(),
            'source' => 'slack',
            'source_dedupe_key' => 'test-task-'.Str::random(12),
        ]);

        return MiriamReminder::create([
            'user_id' => $this->user->id,
            'task_id' => $task->id,
            'category' => 'work',
            'item_type' => 'reminder',
            'title' => 'Call Julian',
            'timezone' => 'Asia/Dubai',
            'confidence' => 1,
            'due_at' => $dueAt->utc(),
            'status' => 'pending',
            'next_reminder_at' => $dueAt->utc(),
            'slack_user_id' => 'U123',
            'slack_channel_id' => 'CMIRIAM',
            'slack_workspace_id' => 'T123',
            'source_dedupe_key' => 'test-reminder-'.Str::random(12),
            'metadata' => [
                'project_name' => 'CF Football Academy',
                'source' => 'slack_thought_capture',
            ],
        ]);
    }

    private function project(string $name): Project
    {
        return Project::create([
            'workspace_id' => $this->workspace->id,
            'owner_id' => $this->user->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'status' => 'active',
            'visibility' => 'workspace',
        ]);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function userWithWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'Miriam Workspace',
            'slug' => 'miriam-workspace-'.Str::random(8),
            'created_by' => $user->id,
        ]);

        $workspace->users()->attach($user->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
    }

    private function postSignedSlackEvent(
        string $text,
        string $channel = 'CMIRIAM',
        string $userId = 'U123',
        string $ts = '1710000000.000100',
        array $eventOverrides = [],
        array $headers = [],
    ) {
        $payload = json_encode([
            'team_id' => 'T123',
            'event' => array_merge([
                'type' => 'message',
                'channel' => $channel,
                'user' => $userId,
                'text' => $text,
                'ts' => $ts,
            ], $eventOverrides),
        ]);
        $timestamp = (string) time();
        $signature = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$payload}", 'test-signing-secret');

        return $this->call(
            'POST',
            route('slack.events', absolute: false),
            [],
            [],
            [],
            array_merge([
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SLACK_REQUEST_TIMESTAMP' => $timestamp,
                'HTTP_X_SLACK_SIGNATURE' => $signature,
            ], $headers),
            $payload
        );
    }

    private function postSignedReminderAction(
        string $actionId,
        int $reminderId,
        string $channel = 'CMIRIAM',
        string $userId = 'U123',
        string $route = 'slack.events',
    ) {
        $body = http_build_query([
            'payload' => json_encode([
                'type' => 'block_actions',
                'response_url' => 'https://hooks.slack.com/actions/T123/ABC',
                'channel' => ['id' => $channel],
                'message' => ['ts' => '1710000000.000200'],
                'user' => ['id' => $userId],
                'actions' => [[
                    'action_id' => $actionId,
                    'value' => (string) $reminderId,
                ]],
            ]),
        ]);
        $timestamp = (string) time();
        $signature = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", 'test-signing-secret');

        return $this->call(
            'POST',
            route($route, absolute: false),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
                'HTTP_X_SLACK_REQUEST_TIMESTAMP' => $timestamp,
                'HTTP_X_SLACK_SIGNATURE' => $signature,
            ],
            $body
        );
    }
}
