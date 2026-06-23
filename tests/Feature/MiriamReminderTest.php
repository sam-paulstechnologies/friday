<?php

namespace Tests\Feature;

use App\Models\MedicationDoseLog;
use App\Models\MedicationDoseSchedule;
use App\Models\MiriamReminder;
use App\Models\User;
use App\Services\MiriamReminderService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MiriamReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.slack.signing_secret' => 'test-signing-secret',
            'services.slack.bot_token' => 'xoxb-test',
            'services.slack.miriam_channel_id' => 'CMIRIAM',
            'services.slack.webhook_url' => null,
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-23 12:00:00', 'Asia/Dubai'));
        Carbon::setTestNow(Carbon::parse('2026-06-23 08:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_general_reminder_text_is_parsed_in_dubai_timezone(): void
    {
        $parsed = app(MiriamReminderService::class)->parse('Remind me to call Sunny at 3 pm today');

        $this->assertSame('call sunny', $parsed['title']);
        $this->assertSame('family', $parsed['category']);
        $this->assertSame('Asia/Dubai', $parsed['timezone']);
        $this->assertSame('2026-06-23 15:00:00', $parsed['due_at']->format('Y-m-d H:i:s'));

        $relative = app(MiriamReminderService::class)->parse('Remind me in 30 minutes to check the oven');

        $this->assertSame('check the oven', $relative['title']);
        $this->assertSame('2026-06-23 12:30:00', $relative['due_at']->format('Y-m-d H:i:s'));
    }

    public function test_slack_url_verification_returns_challenge_without_csrf_or_auth(): void
    {
        $this->postJson(route('slack.events'), [
            'type' => 'url_verification',
            'challenge' => 'challenge-value',
        ])
            ->assertOk()
            ->assertJson(['challenge' => 'challenge-value']);
    }

    public function test_slack_event_stores_reminder_and_sends_confirmation(): void
    {
        User::factory()->create();
        Http::fake(['slack.com/*' => Http::response(['ok' => true, 'channel' => 'CMIRIAM', 'ts' => '1.2'])]);

        $this->postSignedSlackEvent('Remind me to pay DEWA tomorrow 10 am')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $reminder = MiriamReminder::firstOrFail();

        $this->assertSame('pay dewa', $reminder->title);
        $this->assertSame('personal', $reminder->category);
        $this->assertSame('CMIRIAM', $reminder->slack_channel_id);
        $this->assertSame('2026-06-24 06:00:00', $reminder->due_at->format('Y-m-d H:i:s'));

        Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
            && $request['channel'] === 'CMIRIAM'
            && str_contains($request['text'], 'Saved reminder: pay dewa'));
    }

    public function test_private_channel_message_creates_reminder(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('Remind me to call Jasion in 5 minutes', [
            'event' => ['type' => 'message.groups', 'channel_type' => 'group'],
        ])->assertOk();

        $reminder = MiriamReminder::firstOrFail();

        $this->assertSame('call jasion', $reminder->title);
        $this->assertSame('2026-06-23 08:05:00', $reminder->due_at->format('Y-m-d H:i:s'));
    }

    public function test_app_mention_creates_reminder_and_strips_mention_token(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('<@U999BOT> remind me to call Jasion in 5 minutes', [
            'event' => ['type' => 'app_mention'],
        ])->assertOk();

        $this->assertSame('call jasion', MiriamReminder::firstOrFail()->title);

        Http::assertSent(fn ($request) => $request['text'] === 'Saved reminder: call jasion at Jun 23, 12:05 PM.');
    }

    public function test_named_mention_is_stripped_before_parsing(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('@Miriam remind me to call Jasion in 5 minutes')->assertOk();

        $this->assertSame('call jasion', MiriamReminder::firstOrFail()->title);
    }

    public function test_wrong_channel_is_ignored_with_logged_reason(): void
    {
        Log::spy();
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('Remind me to call Jasion in 5 minutes', [
            'event' => ['channel' => 'CWRONG'],
        ])
            ->assertOk()
            ->assertJson(['ignored' => 'wrong_channel']);

        $this->assertDatabaseCount('miriam_reminders', 0);
        Http::assertNothingSent();
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $message === 'Miriam Slack event processed.'
            && ($context['channel_id'] ?? null) === 'CWRONG'
            && ($context['ignored_reason'] ?? null) === 'wrong_channel');
    }

    public function test_parse_failure_replies_with_example_format(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('please remember Jasion sometime')->assertOk()
            ->assertJson(['ignored' => 'not_a_reminder']);

        $this->assertDatabaseCount('miriam_reminders', 0);
        Http::assertSent(fn ($request) => $request['channel'] === 'CMIRIAM'
            && $request['text'] === 'I can save reminders like: Remind me to call Jasion in 5 minutes.');
    }

    public function test_bot_messages_are_ignored_safely(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('Remind me to call Jasion in 5 minutes', [
            'event' => ['bot_id' => 'B123', 'subtype' => 'bot_message'],
        ])
            ->assertOk()
            ->assertJson(['ignored' => 'bot_message']);

        $this->assertDatabaseCount('miriam_reminders', 0);
        Http::assertNothingSent();
    }

    public function test_due_reminder_delivery_uses_done_snooze_and_cancel_buttons(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true, 'channel' => 'CMIRIAM', 'ts' => '1.2'])]);

        $reminder = MiriamReminder::create([
            'category' => 'personal',
            'title' => 'check the oven',
            'timezone' => 'Asia/Dubai',
            'due_at' => CarbonImmutable::now('UTC')->subMinute(),
            'status' => 'pending',
            'next_reminder_at' => CarbonImmutable::now('UTC')->subMinute(),
            'slack_channel_id' => 'CSOURCE',
        ]);

        $this->artisan('miriam:send-reminders')->assertExitCode(0);

        $reminder->refresh();
        $this->assertSame(1, $reminder->reminder_attempts);
        $this->assertNotNull($reminder->last_sent_at);

        Http::assertSent(function ($request) {
            $payload = $request->data();
            $labels = collect($payload['blocks'][1]['elements'])->pluck('text.text')->all();

            return $payload['channel'] === 'CMIRIAM'
                && $payload['text'] === 'Miriam reminder: check the oven'
                && in_array('Done', $labels, true)
                && in_array('Snooze 15 min', $labels, true)
                && in_array('Cancel', $labels, true);
        });
    }

    public function test_general_reminder_buttons_update_status(): void
    {
        Http::fake(['https://hooks.slack.com/*' => Http::response(['ok' => true])]);

        $reminder = MiriamReminder::create([
            'category' => 'personal',
            'title' => 'call sunny',
            'timezone' => 'Asia/Dubai',
            'due_at' => CarbonImmutable::now('UTC'),
            'status' => 'pending',
            'next_reminder_at' => CarbonImmutable::now('UTC'),
        ]);

        $this->postSignedReminderAction('miriam_reminder_snooze_15', $reminder->id)
            ->assertOk()
            ->assertJson(['text' => '⏰ Snoozed until 12:15 PM — call sunny']);

        $this->assertSame('snoozed', $reminder->fresh()->status);
        $this->assertNotNull($reminder->fresh()->next_reminder_at);

        $this->postSignedReminderAction('miriam_reminder_done', $reminder->id)
            ->assertOk()
            ->assertJson(['text' => '✅ Done — call sunny']);

        $this->assertSame('done', $reminder->fresh()->status);
        $this->assertNull($reminder->fresh()->next_reminder_at);
    }

    public function test_done_updates_slack_message_and_removes_buttons(): void
    {
        Http::fake(['https://hooks.slack.com/*' => Http::response(['ok' => true])]);

        $reminder = $this->pendingReminder('call jasion');

        $this->postSignedReminderAction('miriam_reminder_done', $reminder->id)
            ->assertOk()
            ->assertJson(['text' => '✅ Done — call jasion']);

        $this->assertSame('done', $reminder->fresh()->status);
        $this->assertNull($reminder->fresh()->next_reminder_at);
        $this->assertSlackMessageUpdated('✅ Done — call jasion');
    }

    public function test_snooze_updates_slack_message_and_next_time(): void
    {
        Http::fake(['https://hooks.slack.com/*' => Http::response(['ok' => true])]);

        $reminder = $this->pendingReminder('check the oven');

        $this->postSignedReminderAction('miriam_reminder_snooze_15', $reminder->id)
            ->assertOk()
            ->assertJson(['text' => '⏰ Snoozed until 12:15 PM — check the oven']);

        $this->assertSame('snoozed', $reminder->fresh()->status);
        $this->assertSame('2026-06-23 08:15:00', $reminder->fresh()->next_reminder_at->format('Y-m-d H:i:s'));
        $this->assertSlackMessageUpdated('⏰ Snoozed until 12:15 PM — check the oven');
    }

    public function test_cancel_updates_slack_message_and_status(): void
    {
        Http::fake(['https://hooks.slack.com/*' => Http::response(['ok' => true])]);

        $reminder = $this->pendingReminder('pay dewa');

        $this->postSignedReminderAction('miriam_reminder_cancel', $reminder->id)
            ->assertOk()
            ->assertJson(['text' => '🛑 Cancelled — pay dewa']);

        $this->assertSame('cancelled', $reminder->fresh()->status);
        $this->assertNull($reminder->fresh()->next_reminder_at);
        $this->assertSlackMessageUpdated('🛑 Cancelled — pay dewa');
    }

    public function test_duplicate_clicks_keep_current_terminal_status(): void
    {
        Http::fake(['https://hooks.slack.com/*' => Http::response(['ok' => true])]);

        $reminder = $this->pendingReminder('send invoice');

        $this->postSignedReminderAction('miriam_reminder_done', $reminder->id)
            ->assertOk()
            ->assertJson(['text' => '✅ Done — send invoice']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-23 12:10:00', 'Asia/Dubai'));

        $this->postSignedReminderAction('miriam_reminder_cancel', $reminder->id)
            ->assertOk()
            ->assertJson(['text' => '✅ Done — send invoice']);

        $this->assertSame('done', $reminder->fresh()->status);
        $this->assertNull($reminder->fresh()->cancelled_at);
        $this->assertNull($reminder->fresh()->next_reminder_at);
    }

    public function test_existing_medication_action_endpoint_handles_general_reminder_buttons(): void
    {
        Http::fake(['https://hooks.slack.com/*' => Http::response(['ok' => true])]);

        $reminder = MiriamReminder::create([
            'category' => 'work',
            'title' => 'send invoice',
            'timezone' => 'Asia/Dubai',
            'due_at' => CarbonImmutable::now('UTC'),
            'status' => 'pending',
            'next_reminder_at' => CarbonImmutable::now('UTC'),
        ]);

        $this->postSignedReminderAction('miriam_reminder_cancel', $reminder->id, 'slack.medication.actions')
            ->assertOk()
            ->assertJson(['text' => '🛑 Cancelled — send invoice']);

        $this->assertSame('cancelled', $reminder->fresh()->status);
        $this->assertNull($reminder->fresh()->next_reminder_at);
    }

    public function test_invalid_slack_signature_is_rejected_and_retry_is_ignored(): void
    {
        $this->withHeaders([
            'X-Slack-Request-Timestamp' => (string) time(),
            'X-Slack-Signature' => 'v0=bad',
        ])->postJson(route('slack.events'), ['event' => ['text' => 'Remind me in 30 minutes to check the oven']])
            ->assertStatus(401);

        $this->postSignedSlackEvent('Remind me in 30 minutes to check the oven', [
            'headers' => ['X-Slack-Retry-Num' => '1'],
        ])
            ->assertOk()
            ->assertJson(['ignored' => 'retry']);

        $this->assertDatabaseCount('miriam_reminders', 0);
    }

    public function test_medication_reminder_still_posts_private_buttons_to_miriam_channel(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true, 'channel' => 'CMIRIAM', 'ts' => '1.2'])]);

        $user = User::factory()->create();
        $schedule = MedicationDoseSchedule::create([
            'user_id' => $user->id,
            'dose_key' => 'morning',
            'label' => 'Morning medications',
            'dosage_text' => 'Xigduo XR 5mg/1000mg',
            'timing_note' => 'after breakfast',
            'schedule_time' => '09:00:00',
            'timezone' => 'Asia/Dubai',
            'active' => true,
            'hide_details_in_notifications' => true,
            'metadata' => ['frequency' => 'daily'],
        ]);

        MedicationDoseLog::create([
            'dose_schedule_id' => $schedule->id,
            'user_id' => $user->id,
            'dose_date' => '2026-06-23',
            'scheduled_for' => CarbonImmutable::parse('2026-06-23 09:00', 'Asia/Dubai')->utc(),
            'scheduled_timezone' => 'Asia/Dubai',
            'status' => 'pending',
            'next_reminder_at' => CarbonImmutable::parse('2026-06-23 09:00', 'Asia/Dubai')->utc(),
        ]);

        $this->artisan('miriam:send-medication-reminders', [
            '--sync' => true,
            '--test-channel' => 'test-database',
            '--pretend-now' => '2026-06-23 09:01',
        ])->assertExitCode(0);

        Http::assertSent(function ($request) {
            $payload = $request->data();
            $labels = collect($payload['blocks'][1]['elements'])->pluck('text.text')->all();

            return $payload['channel'] === 'CMIRIAM'
                && $payload['text'] === 'Medication reminder: scheduled medication is due.'
                && in_array('Taken', $labels, true)
                && in_array('Snooze 15 min', $labels, true)
                && in_array('Skip', $labels, true)
                && ! str_contains($payload['text'], 'Xigduo');
        });
    }

    private function postSignedSlackEvent(string $text, array $overrides = [])
    {
        $headers = $overrides['headers'] ?? [];
        $eventOverrides = $overrides['event'] ?? [];
        $payload = json_encode([
            'event' => array_merge([
                'type' => 'message',
                'channel' => 'CMIRIAM',
                'user' => 'U123',
                'text' => $text,
                'ts' => '1710000000.000100',
            ], $eventOverrides),
        ]);
        $timestamp = (string) time();
        $signature = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$payload}", 'test-signing-secret');

        return $this->withHeaders(array_merge([
            'X-Slack-Request-Timestamp' => $timestamp,
            'X-Slack-Signature' => $signature,
            'Content-Type' => 'application/json',
        ], $headers))->postJson(route('slack.events'), json_decode($payload, true));
    }

    private function postSignedReminderAction(string $actionId, int $reminderId, string $route = 'slack.events')
    {
        $body = http_build_query([
            'payload' => json_encode([
                'type' => 'block_actions',
                'response_url' => 'https://hooks.slack.com/actions/T123/ABC',
                'channel' => ['id' => 'CMIRIAM'],
                'message' => ['ts' => '1710000000.000200'],
                'user' => ['id' => 'U123'],
                'actions' => [
                    [
                        'action_id' => $actionId,
                        'value' => (string) $reminderId,
                    ],
                ],
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

    private function pendingReminder(string $title): MiriamReminder
    {
        return MiriamReminder::create([
            'category' => 'personal',
            'title' => $title,
            'timezone' => 'Asia/Dubai',
            'due_at' => CarbonImmutable::now('UTC'),
            'status' => 'pending',
            'next_reminder_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    private function assertSlackMessageUpdated(string $text): void
    {
        Http::assertSent(function ($request) use ($text) {
            $payload = $request->data();

            return $request->url() === 'https://hooks.slack.com/actions/T123/ABC'
                && ($payload['replace_original'] ?? null) === true
                && ($payload['text'] ?? null) === $text
                && count($payload['blocks'] ?? []) === 1
                && ($payload['blocks'][0]['type'] ?? null) === 'section'
                && ! collect($payload['blocks'])->contains(fn ($block) => ($block['type'] ?? null) === 'actions');
        });
    }
}
