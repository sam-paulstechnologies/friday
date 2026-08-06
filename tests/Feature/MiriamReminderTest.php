<?php

namespace Tests\Feature;

use App\Models\MedicationDoseLog;
use App\Models\MedicationDoseSchedule;
use App\Models\CalendarConnection;
use App\Models\CalendarEventMapping;
use App\Models\MiriamReminder;
use App\Models\MiriamSlackClarification;
use App\Models\User;
use App\Services\Miriam\MiriamToolExecutor;
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

    public function test_tomorrow_agenda_question_returns_agenda_and_creates_no_reminder(): void
    {
        $user = User::factory()->create();
        CalendarEventMapping::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_event_id' => 'google-event-1',
            'provider_calendar_id' => 'primary',
            'last_synced_at' => now(),
            'metadata' => [
                'title' => 'Smart Matrix review',
                'date' => '2026-06-24',
                'time' => '10:00 AM',
            ],
        ]);
        MiriamReminder::create([
            'user_id' => $user->id,
            'category' => 'work',
            'title' => 'Call Jasion',
            'timezone' => 'Asia/Dubai',
            'due_at' => CarbonImmutable::parse('2026-06-24 09:00', 'Asia/Dubai')->utc(),
            'status' => 'pending',
            'next_reminder_at' => CarbonImmutable::parse('2026-06-24 09:00', 'Asia/Dubai')->utc(),
        ]);
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('what does my tomorrow look like?')
            ->assertOk()
            ->assertJson(['intent' => 'calendar_day_query']);

        $this->assertDatabaseCount('miriam_reminders', 1);
        $this->assertDatabaseCount('miriam_slack_conversation_contexts', 1);
        $this->assertDatabaseHas('miriam_tool_audits', [
            'event_type' => 'tool_selected',
            'tool_name' => 'read_tomorrow_agenda',
        ]);
        $this->assertDatabaseHas('miriam_tool_audits', [
            'event_type' => 'tool_executed',
            'tool_name' => 'read_tomorrow_agenda',
        ]);
        Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
            && str_contains($request['text'], 'Tomorrow: 1 calendar event, 1 Miriam reminder')
            && str_contains($request['text'], 'Reply "show me"'));
    }

    public function test_show_me_my_meds_uses_medication_status_tool_and_creates_no_reminder(): void
    {
        $user = User::factory()->create();
        $schedule = MedicationDoseSchedule::create([
            'user_id' => $user->id,
            'dose_key' => 'morning',
            'label' => 'Morning medications',
            'dosage_text' => 'Private',
            'timing_note' => 'after breakfast',
            'schedule_time' => '09:00:00',
            'timezone' => 'Asia/Dubai',
            'active' => true,
            'hide_details_in_notifications' => true,
        ]);
        MedicationDoseLog::create([
            'dose_schedule_id' => $schedule->id,
            'user_id' => $user->id,
            'dose_date' => '2026-06-23',
            'scheduled_for' => CarbonImmutable::parse('2026-06-23 09:00', 'Asia/Dubai')->utc(),
            'scheduled_timezone' => 'Asia/Dubai',
            'status' => 'pending',
        ]);
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('show me my meds')
            ->assertOk()
            ->assertJson(['intent' => 'health_status_query']);

        $this->assertDatabaseCount('miriam_reminders', 0);
        $this->assertDatabaseHas('miriam_tool_audits', [
            'event_type' => 'tool_executed',
            'tool_name' => 'read_medication_status',
        ]);
        Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
            && str_contains($request['text'], 'Morning medications: pending'));
    }

    public function test_show_me_after_agenda_shows_detail(): void
    {
        $user = User::factory()->create();
        CalendarEventMapping::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_event_id' => 'google-event-1',
            'provider_calendar_id' => 'primary',
            'last_synced_at' => now(),
            'metadata' => [
                'title' => 'Smart Matrix review',
                'date' => '2026-06-24',
                'time' => '10:00 AM',
            ],
        ]);
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('what does my tomorrow look like?')->assertOk();
        $this->postSignedSlackEvent('show me', ['event' => ['ts' => '1710000001.000100']])
            ->assertOk()
            ->assertJson(['intent' => 'show_last_result']);

        $this->assertDatabaseCount('miriam_reminders', 0);
        Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
            && str_contains($request['text'], 'Tomorrow agenda:')
            && str_contains($request['text'], '10:00 AM - Smart Matrix review'));
    }

    public function test_show_me_without_context_asks_what_to_show(): void
    {
        User::factory()->create();
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('show me')
            ->assertOk()
            ->assertJson(['intent' => 'show_last_result']);

        $this->assertDatabaseCount('miriam_reminders', 0);
        $this->assertDatabaseCount('miriam_slack_conversation_contexts', 0);
        Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
            && $request['text'] === 'What would you like me to show: tomorrow agenda, reminders, or health status?');
    }

    public function test_slack_event_stores_reminder_and_sends_summary(): void
    {
        User::factory()->create();
        Http::fake(['slack.com/*' => Http::response(['ok' => true, 'channel' => 'CMIRIAM', 'ts' => '1.2'])]);

        $this->postSignedSlackEvent('Remind me to pay DEWA tomorrow 10 am')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $reminder = MiriamReminder::firstOrFail();

        $this->assertSame('Pay DEWA', $reminder->title);
        $this->assertSame('personal', $reminder->category);
        $this->assertSame('reminder', $reminder->item_type);
        $this->assertSame('CMIRIAM', $reminder->slack_channel_id);
        $this->assertSame('2026-06-24 06:00:00', $reminder->due_at->format('Y-m-d H:i:s'));

        Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
            && $request['channel'] === 'CMIRIAM'
            && str_contains($request['text'], 'Captured 1 item:')
            && str_contains($request['text'], 'Tomorrow 10:00 AM — Pay DEWA'));
    }

    public function test_tool_gateway_creates_tomorrow_morning_reminder_and_calendar_safely(): void
    {
        config([
            'services.google_calendar.enabled' => true,
            'services.google_calendar.client_id' => 'client',
            'services.google_calendar.client_secret' => 'secret',
            'services.google_calendar.redirect_uri' => 'https://example.test/callback',
        ]);

        $user = User::factory()->create();
        CalendarConnection::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'is_active' => true,
        ]);
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response([
                'id' => 'google-tool-reminder-1',
                'organizer' => ['email' => 'primary@example.test'],
            ]),
            'slack.com/*' => Http::response(['ok' => true]),
        ]);

        $this->postSignedSlackEvent('remind me tomorrow morning to call Jasion')
            ->assertOk()
            ->assertJson(['intent' => 'create_reminder']);

        $reminder = MiriamReminder::firstOrFail();

        $this->assertSame('Call Jasion', $reminder->title);
        $this->assertSame('2026-06-24 05:00:00', $reminder->due_at->format('Y-m-d H:i:s'));
        $this->assertSame('google-tool-reminder-1', $reminder->google_calendar_event_id);
        $this->assertDatabaseHas('miriam_tool_audits', [
            'event_type' => 'tool_executed',
            'tool_name' => 'create_reminder',
        ]);
        Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
            && str_contains($request['text'], 'Saved reminder: Call Jasion'));
    }

    public function test_move_that_to_ambiguous_time_asks_am_pm_using_previous_context(): void
    {
        User::factory()->create();
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('remind me tomorrow morning to call Jasion')->assertOk();
        $this->postSignedSlackEvent('move that to 10', ['event' => ['ts' => '1710000001.000100']])
            ->assertOk()
            ->assertJson(['intent' => 'unclear']);

        $this->assertSame('pending', MiriamReminder::firstOrFail()->status);
        Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
            && $request['text'] === 'Do you mean 10 AM or 10 PM?');
    }

    public function test_mark_it_done_updates_recent_reminder_when_context_is_clear(): void
    {
        User::factory()->create();
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('remind me tomorrow morning to call Jasion')->assertOk();
        $this->postSignedSlackEvent('mark it done', ['event' => ['ts' => '1710000001.000100']])
            ->assertOk()
            ->assertJson(['intent' => 'update_reminder_status']);

        $this->assertSame('done', MiriamReminder::firstOrFail()->status);
        $this->assertDatabaseHas('miriam_tool_audits', [
            'event_type' => 'tool_executed',
            'tool_name' => 'update_reminder_status',
        ]);
        Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
            && str_contains($request['text'], 'Done - Call Jasion'));
    }

    public function test_risky_external_message_asks_approval_and_creates_no_reminder(): void
    {
        User::factory()->create();
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('Message Jasion now saying I am late')
            ->assertOk()
            ->assertJson(['intent' => 'approval_required']);

        $this->assertDatabaseCount('miriam_reminders', 0);
        $this->assertDatabaseHas('miriam_tool_audits', [
            'event_type' => 'approval_required',
            'status' => 'approval_required',
        ]);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'sending external messages needs confirmation'));
    }

    public function test_medication_schedule_change_asks_approval_and_creates_no_reminder(): void
    {
        User::factory()->create();
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('change my medication schedule to 8am')
            ->assertOk()
            ->assertJson(['intent' => 'approval_required']);

        $this->assertDatabaseCount('miriam_reminders', 0);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Changing medication schedules needs confirmation'));
    }

    public function test_tool_failure_replies_gracefully(): void
    {
        User::factory()->create();
        $this->mock(MiriamToolExecutor::class, function ($mock): void {
            $mock->shouldReceive('execute')->once()->andReturn([
                'ok' => false,
                'message' => 'I hit a safe tool error while doing that. I stored the failure in Miriam.',
            ]);
            $mock->shouldReceive('storeContext')->once();
        });
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('what does my tomorrow look like?')
            ->assertOk()
            ->assertJson(['intent' => 'calendar_day_query']);

        Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
            && $request['text'] === 'I hit a safe tool error while doing that. I stored the failure in Miriam.');
    }

    public function test_private_channel_message_creates_reminder(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('Remind me to call Jasion in 5 minutes', [
            'event' => ['type' => 'message.groups', 'channel_type' => 'group'],
        ])->assertOk();

        $reminder = MiriamReminder::firstOrFail();

        $this->assertSame('Call Jasion', $reminder->title);
        $this->assertSame('2026-06-23 08:05:00', $reminder->due_at->format('Y-m-d H:i:s'));
    }

    public function test_message_jasion_tomorrow_at_nine_asks_am_pm_clarification(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('Message Jasion tomorrow at 9 asking for his time')
            ->assertOk()
            ->assertJson(['needs_confirmation' => true]);

        $this->assertDatabaseCount('miriam_reminders', 0);
        $this->assertDatabaseCount('miriam_slack_clarifications', 1);
        Http::assertSent(fn ($request) => $request['text'] === 'Do you mean tomorrow 9 AM or 9 PM?');
    }

    public function test_am_resolves_previous_clarification(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('Message Jasion tomorrow at 9 asking for his time')->assertOk();
        $this->postSignedSlackEvent('AM', ['event' => ['ts' => '1710000001.000100']])->assertOk();

        $reminder = MiriamReminder::firstOrFail();

        $this->assertSame('Message Jasion asking for his time', $reminder->title);
        $this->assertSame('2026-06-24 05:00:00', $reminder->due_at->format('Y-m-d H:i:s'));
        $this->assertSame('resolved', MiriamSlackClarification::firstOrFail()->status);
        $this->assertTrue($reminder->events()->where('event_type', 'clarification_created')->exists());
        $this->assertTrue($reminder->events()->where('event_type', 'clarification_resolved')->exists());
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Tomorrow 9:00 AM'));
    }

    public function test_message_jasion_tomorrow_at_nine_creates_reminder(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('Message Jasion tomorrow at 9am asking for his time')->assertOk();

        $reminder = MiriamReminder::firstOrFail();

        $this->assertSame('Message Jasion asking for his time', $reminder->title);
        $this->assertSame('2026-06-24 05:00:00', $reminder->due_at->format('Y-m-d H:i:s'));
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Tomorrow 9:00 AM — Message Jasion asking for his time'));
    }

    public function test_message_jasion_tomorrow_at_nine_pm_creates_reminder(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('Message Jasion tomorrow at 9pm asking for his time')->assertOk();

        $reminder = MiriamReminder::firstOrFail();

        $this->assertSame('Message Jasion asking for his time', $reminder->title);
        $this->assertSame('2026-06-24 17:00:00', $reminder->due_at->format('Y-m-d H:i:s'));
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Tomorrow 9:00 PM'));
    }

    public function test_tonight_prepare_document_creates_document_task(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('Tonight prepare a document of what Meta permission would I need to create a WABA account in Smart Matrix')->assertOk();

        $reminder = MiriamReminder::firstOrFail();

        $this->assertSame('document_task', $reminder->item_type);
        $this->assertSame('Prepare a document of what Meta permission would I need to create a WABA account in Smart Matrix', $reminder->title);
        $this->assertSame('2026-06-23 17:00:00', $reminder->due_at->format('Y-m-d H:i:s'));
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Tonight 9:00 PM — Prepare a document'));
    }

    public function test_tonight_before_eight_defaults_to_nine_pm_today(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-23 19:30:00', 'Asia/Dubai'));
        Carbon::setTestNow(Carbon::parse('2026-06-23 15:30:00', 'UTC'));
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('Tonight prepare a document about Meta permissions')->assertOk();

        $reminder = MiriamReminder::firstOrFail();

        $this->assertSame('2026-06-23 17:00:00', $reminder->due_at->format('Y-m-d H:i:s'));
    }

    public function test_tonight_after_eight_uses_future_rounded_time(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-23 21:38:00', 'Asia/Dubai'));
        Carbon::setTestNow(Carbon::parse('2026-06-23 17:38:00', 'UTC'));
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('Tonight prepare a document about Meta permissions')->assertOk();

        $reminder = MiriamReminder::firstOrFail();

        $this->assertSame('2026-06-23 18:10:00', $reminder->due_at->format('Y-m-d H:i:s'));
        $this->assertTrue($reminder->due_at->gt(CarbonImmutable::now('UTC')));
    }

    public function test_multi_line_message_creates_multiple_items(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent("Message Jasion tomorrow at 9am asking for his time\nTonight prepare a document about Meta permissions")->assertOk();

        $this->assertDatabaseCount('miriam_reminders', 2);
        $this->assertSame([
            'Message Jasion asking for his time',
            'Prepare a document about Meta permissions',
        ], MiriamReminder::query()->orderBy('id')->pluck('title')->all());

        Http::assertSent(fn ($request) => str_contains($request['text'], 'Captured 2 items:')
            && str_contains($request['text'], '1. Tomorrow 9:00 AM — Message Jasion asking for his time')
            && str_contains($request['text'], '2. Tonight 9:00 PM — Prepare a document about Meta permissions'));
    }

    public function test_saved_reminders_always_have_title_and_due_at(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('Remind me to call Jasion in 5 minutes')->assertOk();

        $reminder = MiriamReminder::firstOrFail();

        $this->assertNotSame('', trim($reminder->title));
        $this->assertNotNull($reminder->due_at);
    }

    public function test_app_mention_creates_reminder_and_strips_mention_token(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('<@U999BOT> remind me to call Jasion in 5 minutes', [
            'event' => ['type' => 'app_mention'],
        ])->assertOk();

        $this->assertSame('Call Jasion', MiriamReminder::firstOrFail()->title);

        Http::assertSent(fn ($request) => str_contains($request['text'], 'Captured 1 item:')
            && str_contains($request['text'], 'Jun 23, 12:05 PM — Call Jasion'));
    }

    public function test_named_mention_is_stripped_before_parsing(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('@Miriam remind me to call Jasion in 5 minutes')->assertOk();

        $this->assertSame('Call Jasion', MiriamReminder::firstOrFail()->title);
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
            ->assertJson(['intent' => 'unclear']);

        $this->assertDatabaseCount('miriam_reminders', 0);
        Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
            && $request['channel'] === 'CMIRIAM'
            && $request['text'] === 'What would you like me to show or save?');
    }

    public function test_low_confidence_capture_asks_clarification(): void
    {
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSignedSlackEvent('Create a note for Smart Matrix ideas')
            ->assertOk()
            ->assertJson(['needs_confirmation' => true]);

        $this->assertDatabaseCount('miriam_reminders', 0);
        Http::assertSent(fn ($request) => $request['text'] === 'I found 1 possible task. Should I save them?');
    }

    public function test_openai_failure_falls_back_gracefully(): void
    {
        config([
            'services.miriam_ai.enabled' => true,
            'services.miriam_ai.api_key' => 'test-openai-key',
            'services.miriam_ai.model' => 'gpt-5.4-mini',
        ]);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response(['error' => ['message' => 'nope']], 500),
            'slack.com/*' => Http::response(['ok' => true]),
        ]);

        $this->postSignedSlackEvent('follow up with Jasion sometime')->assertOk()
            ->assertJson(['needs_confirmation' => true]);

        $this->assertDatabaseCount('miriam_reminders', 0);
        Http::assertSent(fn ($request) => $request->url() === 'https://slack.com/api/chat.postMessage'
            && $request['channel'] === 'CMIRIAM'
            && $request['text'] === 'I found 1 possible task. Should I save them?');
    }

    public function test_openai_fallback_can_create_reminder_with_audit_events(): void
    {
        config([
            'services.miriam_ai.enabled' => true,
            'services.miriam_ai.api_key' => 'test-openai-key',
            'services.miriam_ai.model' => 'gpt-5.4-mini',
            'services.miriam_ai.reasoning_effort' => 'low',
        ]);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output_text' => json_encode([
                    'intent' => 'create_task',
                    'title' => 'Review Smart Matrix permissions',
                    'description' => 'Review Meta WABA permission requirements.',
                    'due_at_local' => '2026-06-24 09:00:00',
                    'timezone' => 'Asia/Dubai',
                    'confidence' => 0.91,
                    'needs_clarification' => false,
                    'clarification_question' => null,
                    'risk_level' => 'low',
                    'should_create_calendar_event' => true,
                ]),
            ]),
            'slack.com/*' => Http::response(['ok' => true]),
        ]);

        $this->postSignedSlackEvent('follow up with Smart Matrix permissions sometime')->assertOk();

        $reminder = MiriamReminder::firstOrFail();

        $this->assertSame('Review Smart Matrix permissions', $reminder->title);
        $this->assertSame('task', $reminder->item_type);
        $this->assertSame('2026-06-24 05:00:00', $reminder->due_at->format('Y-m-d H:i:s'));
        $this->assertTrue($reminder->events()->where('event_type', 'ai_request_created')->exists());
        $this->assertTrue($reminder->events()->where('event_type', 'ai_response_received')->exists());
        $this->assertTrue($reminder->events()->where('event_type', 'reminder_created_from_ai')->exists());
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/responses'
            && $request['model'] === 'gpt-5.4-mini'
            && $request['text']['format']['type'] === 'json_schema');
    }

    public function test_google_calendar_event_created_when_connected(): void
    {
        config([
            'services.google_calendar.enabled' => true,
            'services.google_calendar.client_id' => 'client',
            'services.google_calendar.client_secret' => 'secret',
            'services.google_calendar.redirect_uri' => 'https://example.test/callback',
        ]);

        $user = User::factory()->create();

        CalendarConnection::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'is_active' => true,
        ]);

        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response([
                'id' => 'google-reminder-1',
                'organizer' => ['email' => 'primary@example.test'],
                'summary' => 'Reminder: Message Jasion asking for his time',
                'start' => ['dateTime' => '2026-06-24T09:00:00+04:00'],
                'htmlLink' => 'https://calendar.example.test/event',
            ]),
            'slack.com/*' => Http::response(['ok' => true]),
        ]);

        $this->postSignedSlackEvent('Message Jasion tomorrow at 9am asking for his time')->assertOk();

        $reminder = MiriamReminder::firstOrFail();

        $this->assertSame('google-reminder-1', $reminder->google_calendar_event_id);
        $this->assertSame('google-reminder-1', CalendarEventMapping::firstOrFail()->provider_event_id);
        Http::assertSent(fn ($request) => $request->url() === 'https://www.googleapis.com/calendar/v3/calendars/primary/events'
            && $request['summary'] === 'Reminder: Message Jasion asking for his time'
            && $request['visibility'] === 'private'
            && $request['end']['dateTime'] === '2026-06-24T09:15:00+04:00');
    }

    public function test_no_reminder_or_calendar_event_is_created_in_the_past(): void
    {
        config([
            'services.google_calendar.enabled' => true,
            'services.google_calendar.client_id' => 'client',
            'services.google_calendar.client_secret' => 'secret',
            'services.google_calendar.redirect_uri' => 'https://example.test/callback',
        ]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-23 21:38:00', 'Asia/Dubai'));
        Carbon::setTestNow(Carbon::parse('2026-06-23 17:38:00', 'UTC'));

        $user = User::factory()->create();
        CalendarConnection::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'is_active' => true,
        ]);

        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response([
                'id' => 'google-reminder-late',
                'organizer' => ['email' => 'primary@example.test'],
            ]),
            'slack.com/*' => Http::response(['ok' => true]),
        ]);

        $this->postSignedSlackEvent('Tonight prepare a document about Meta permissions')->assertOk();

        $reminder = MiriamReminder::firstOrFail();

        $this->assertTrue($reminder->due_at->gt(CarbonImmutable::now('UTC')));
        Http::assertSent(fn ($request) => $request->url() === 'https://www.googleapis.com/calendar/v3/calendars/primary/events'
            && $request['start']['dateTime'] === '2026-06-23T22:10:00+04:00');
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
                // Message prefix is plain "Reminder:" since the escalation
                // refactor; the buttons are what this test is really about.
                && $payload['text'] === 'Reminder: check the oven'
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
            ->assertJson(['text' => 'Snoozed until 12:15 PM - call sunny']);

        $this->assertSame('snoozed', $reminder->fresh()->status);
        $this->assertNotNull($reminder->fresh()->next_reminder_at);

        $this->postSignedReminderAction('miriam_reminder_done', $reminder->id)
            ->assertOk()
            ->assertJson(['text' => 'Done - call sunny']);

        $this->assertSame('done', $reminder->fresh()->status);
        $this->assertNull($reminder->fresh()->next_reminder_at);
    }

    public function test_done_updates_slack_message_and_removes_buttons(): void
    {
        Http::fake(['https://hooks.slack.com/*' => Http::response(['ok' => true])]);

        $reminder = $this->pendingReminder('call jasion');

        $this->postSignedReminderAction('miriam_reminder_done', $reminder->id)
            ->assertOk()
            ->assertJson(['text' => 'Done - call jasion']);

        $this->assertSame('done', $reminder->fresh()->status);
        $this->assertNull($reminder->fresh()->next_reminder_at);
        $this->assertSlackMessageUpdated('Done - call jasion');
    }

    public function test_snooze_updates_slack_message_and_next_time(): void
    {
        Http::fake(['https://hooks.slack.com/*' => Http::response(['ok' => true])]);

        $reminder = $this->pendingReminder('check the oven');

        $this->postSignedReminderAction('miriam_reminder_snooze_15', $reminder->id)
            ->assertOk()
            ->assertJson(['text' => 'Snoozed until 12:15 PM - check the oven']);

        $this->assertSame('snoozed', $reminder->fresh()->status);
        $this->assertSame('2026-06-23 08:15:00', $reminder->fresh()->next_reminder_at->format('Y-m-d H:i:s'));
        $this->assertSlackMessageUpdated('Snoozed until 12:15 PM - check the oven');
    }

    public function test_cancel_updates_slack_message_and_status(): void
    {
        Http::fake(['https://hooks.slack.com/*' => Http::response(['ok' => true])]);

        $reminder = $this->pendingReminder('pay dewa');

        $this->postSignedReminderAction('miriam_reminder_cancel', $reminder->id)
            ->assertOk()
            ->assertJson(['text' => 'Cancelled - pay dewa']);

        $this->assertSame('cancelled', $reminder->fresh()->status);
        $this->assertNull($reminder->fresh()->next_reminder_at);
        $this->assertSlackMessageUpdated('Cancelled - pay dewa');
    }

    public function test_duplicate_clicks_keep_current_terminal_status(): void
    {
        Http::fake(['https://hooks.slack.com/*' => Http::response(['ok' => true])]);

        $reminder = $this->pendingReminder('send invoice');

        $this->postSignedReminderAction('miriam_reminder_done', $reminder->id)
            ->assertOk()
            ->assertJson(['text' => 'Done - send invoice']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-23 12:10:00', 'Asia/Dubai'));

        $this->postSignedReminderAction('miriam_reminder_cancel', $reminder->id)
            ->assertOk()
            ->assertJson(['text' => 'Done - send invoice']);

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
            ->assertJson(['text' => 'Cancelled - send invoice']);

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
