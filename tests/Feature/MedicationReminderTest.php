<?php

namespace Tests\Feature;

use App\Models\MedicationDoseLog;
use App\Models\MedicationDoseSchedule;
use App\Models\CalendarConnection;
use App\Models\CalendarEventMapping;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Health\MedicationReminderService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MedicationReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
            'services.slack.webhook_url' => null,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_corrected_named_medication_routine_can_be_configured(): void
    {
        [$user] = $this->context();

        $this->artisan('miriam:medication-routine:configure', [
            '--user' => $user->email,
            '--breakfast' => '08:30',
            '--dinner' => '20:30',
        ])->assertExitCode(0);

        $this->assertSame(3, MedicationDoseSchedule::where('user_id', $user->id)->count());

        $morning = MedicationDoseSchedule::where('user_id', $user->id)->where('dose_key', 'morning')->firstOrFail();
        $evening = MedicationDoseSchedule::where('user_id', $user->id)->where('dose_key', 'evening')->firstOrFail();
        $weekly = MedicationDoseSchedule::where('user_id', $user->id)->where('dose_key', 'weekly_ozempic')->firstOrFail();

        $this->assertSame('Morning medications', $morning->label);
        $this->assertSame('after breakfast', $morning->timing_note);
        $this->assertSame('08:30', $morning->schedule_time);
        $this->assertSame('10:00:00', $morning->hard_deadline_time);
        $this->assertSame([
            'Xigduo XR 5mg/1000mg',
            'Physiotens 0.2mg',
            'Lodiva 10mg/160mg',
            'Aterpen 10mg/20mg',
        ], collect($morning->metadata['medication_items'])->pluck('name')->all());

        $this->assertSame('Evening medication', $evening->label);
        $this->assertSame('Xigduo XR 5mg/1000mg', $evening->dosage_text);
        $this->assertSame('after dinner', $evening->timing_note);
        $this->assertSame('20:30', $evening->schedule_time);

        $this->assertSame('Weekly medication', $weekly->label);
        $this->assertSame('Ozempic', $weekly->dosage_text);
        $this->assertSame('weekly', $weekly->metadata['frequency']);
        $this->assertSame(3, $weekly->metadata['weekday']);
        $this->assertSame('07:00', $weekly->schedule_time);
        $this->assertTrue($morning->hide_details_in_notifications);
        $this->assertTrue($weekly->hide_details_in_notifications);
    }

    public function test_xigduo_is_scheduled_twice_daily(): void
    {
        [$user] = $this->context();

        $this->artisan('miriam:medication-routine:configure', [
            '--user' => $user->email,
            '--breakfast' => '09:00',
            '--dinner' => '21:30',
        ])->assertExitCode(0);

        $xigduoSchedules = MedicationDoseSchedule::where('user_id', $user->id)
            ->get()
            ->filter(fn (MedicationDoseSchedule $schedule) => collect($schedule->metadata['medication_items'] ?? [])->pluck('name')->contains('Xigduo XR 5mg/1000mg'));

        $this->assertSame(['morning', 'evening'], $xigduoSchedules->pluck('dose_key')->values()->all());
    }

    public function test_wednesday_ozempic_weekly_reminder_is_due_at_seven_dubai(): void
    {
        [$user] = $this->context();
        $this->schedule($user, [
            'dose_key' => 'weekly_ozempic',
            'label' => 'Weekly medication',
            'dosage_text' => 'Ozempic',
            'timing_note' => 'every Wednesday at 07:00',
            'schedule_time' => '07:00:00',
            'hard_deadline_time' => null,
            'metadata' => [
                'frequency' => 'weekly',
                'weekday' => 3,
                'weekday_name' => 'Wednesday',
                'medication_items' => [
                    ['name' => 'Ozempic', 'timing' => 'Every Wednesday at 07:00 Asia/Dubai'],
                ],
            ],
        ]);

        $this->travelToDubai('2026-06-23 07:01:00');
        $this->assertCount(0, app(MedicationReminderService::class)->ensureLogsForActiveSchedules());

        $this->travelToDubai('2026-06-24 07:01:00');
        app(MedicationReminderService::class)->queueDueReminders(sync: true);

        $log = MedicationDoseLog::firstOrFail();
        $this->assertSame('weekly_ozempic', $log->schedule->dose_key);
        $this->assertSame('overdue', $log->status);
        $this->assertSame('2026-06-24 03:00:00', $log->scheduled_for->toDateTimeString());
    }

    public function test_configure_command_requires_exact_breakfast_and_dinner_times(): void
    {
        [$user] = $this->context();

        $this->artisan('miriam:medication-routine:configure', [
            '--user' => $user->email,
            '--breakfast' => '',
            '--dinner' => '',
        ])
            ->expectsOutputToContain('Exact breakfast and dinner reminder times are required')
            ->assertExitCode(2);
    }

    public function test_medication_reminder_command_is_scheduled_every_minute(): void
    {
        Artisan::call('schedule:list');

        $output = preg_replace('/\s+/', ' ', Artisan::output());

        $this->assertStringContainsString('* * * * * php artisan miriam:send-medication-reminders', $output);
        $this->assertStringNotContainsString('*/5 * * * * php artisan miriam:send-medication-reminders', $output);
    }

    public function test_due_reminder_sends_generic_notification_and_records_audit_event(): void
    {
        [$user] = $this->context();
        $schedule = $this->schedule($user, ['schedule_time' => '08:30:00']);
        $this->travelToDubai('2026-06-23 08:31:00');

        app(MedicationReminderService::class)->queueDueReminders(sync: true, channel: 'test-database');

        $log = MedicationDoseLog::firstOrFail();
        $notification = $user->notifications()->firstOrFail();

        $this->assertSame($schedule->id, $log->dose_schedule_id);
        $this->assertSame('overdue', $log->status);
        $this->assertSame(1, $log->reminder_attempts);
        $this->assertSame('test-database', $log->last_delivery_channel);
        $this->assertSame('medication_reminder', $notification->data['event_type']);
        $this->assertStringContainsString('scheduled medication is due', $notification->data['message']);
        $this->assertStringNotContainsString('Xigduo', $notification->data['message']);
        $this->assertStringNotContainsString('after breakfast', $notification->data['message']);
        $this->assertDatabaseHas('medication_reminder_events', [
            'dose_log_id' => $log->id,
            'event_type' => 'reminder_sent',
            'channel' => 'test-database',
        ]);
    }

    public function test_overdue_dose_with_past_next_reminder_is_sent_using_utc_due_query(): void
    {
        [$user] = $this->context();
        $schedule = $this->schedule($user, [
            'dose_key' => 'evening',
            'label' => 'Evening medication',
            'schedule_time' => '21:30:00',
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
        ]);

        MedicationDoseLog::create([
            'dose_schedule_id' => $schedule->id,
            'user_id' => $user->id,
            'workspace_id' => $schedule->workspace_id,
            'dose_date' => '2026-06-23',
            'scheduled_for' => CarbonImmutable::parse('2026-06-23 17:30:00', 'UTC'),
            'scheduled_timezone' => 'Asia/Dubai',
            'status' => 'overdue',
            'next_reminder_at' => CarbonImmutable::parse('2026-06-23 18:01:00', 'UTC'),
        ]);

        $result = app(MedicationReminderService::class)->queueDueReminders(
            CarbonImmutable::parse('2026-06-23 18:01:57', 'UTC'),
            sync: true,
            channel: 'test-database'
        );

        $log = MedicationDoseLog::firstOrFail();

        $this->assertSame(1, $result['due_candidate_count']);
        $this->assertSame('2026-06-23 18:01:57', $result['current_utc']);
        $this->assertSame(1, $result['queued']);
        $this->assertSame(1, $log->fresh()->reminder_attempts);
        $this->assertDatabaseHas('medication_reminder_events', [
            'dose_log_id' => $log->id,
            'event_type' => 'reminder_sent',
            'channel' => 'test-database',
        ]);
    }

    public function test_overdue_dose_with_future_next_reminder_is_not_sent(): void
    {
        [$user] = $this->context();
        $schedule = $this->schedule($user, [
            'dose_key' => 'evening',
            'label' => 'Evening medication',
            'schedule_time' => '21:30:00',
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
        ]);

        MedicationDoseLog::create([
            'dose_schedule_id' => $schedule->id,
            'user_id' => $user->id,
            'workspace_id' => $schedule->workspace_id,
            'dose_date' => '2026-06-23',
            'scheduled_for' => CarbonImmutable::parse('2026-06-23 17:30:00', 'UTC'),
            'scheduled_timezone' => 'Asia/Dubai',
            'status' => 'overdue',
            'next_reminder_at' => CarbonImmutable::parse('2026-06-23 18:02:00', 'UTC'),
        ]);

        $result = app(MedicationReminderService::class)->queueDueReminders(
            CarbonImmutable::parse('2026-06-23 18:01:57', 'UTC'),
            sync: true,
            channel: 'test-database'
        );

        $this->assertSame(0, $result['due_candidate_count']);
        $this->assertSame(0, $result['queued']);
        $this->assertSame(0, MedicationDoseLog::firstOrFail()->fresh()->reminder_attempts);
        $this->assertDatabaseMissing('medication_reminder_events', [
            'event_type' => 'reminder_sent',
        ]);
    }

    public function test_pending_dose_due_now_is_sent(): void
    {
        [$user] = $this->context();
        $schedule = $this->schedule($user, [
            'schedule_time' => '08:30:00',
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
        ]);

        MedicationDoseLog::create([
            'dose_schedule_id' => $schedule->id,
            'user_id' => $user->id,
            'workspace_id' => $schedule->workspace_id,
            'dose_date' => '2026-06-23',
            'scheduled_for' => CarbonImmutable::parse('2026-06-23 04:30:00', 'UTC'),
            'scheduled_timezone' => 'Asia/Dubai',
            'status' => 'pending',
            'next_reminder_at' => CarbonImmutable::parse('2026-06-23 04:30:00', 'UTC'),
        ]);

        $result = app(MedicationReminderService::class)->queueDueReminders(
            CarbonImmutable::parse('2026-06-23 04:30:00', 'UTC'),
            sync: true,
            channel: 'test-database'
        );

        $this->assertSame(1, $result['due_candidate_count']);
        $this->assertSame(1, $result['queued']);
        $this->assertSame(1, MedicationDoseLog::firstOrFail()->fresh()->reminder_attempts);
    }

    public function test_taken_and_skipped_doses_are_ignored_by_due_query(): void
    {
        [$user] = $this->context();
        $takenSchedule = $this->schedule($user, [
            'dose_key' => 'morning_taken',
            'schedule_time' => '08:30:00',
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
        ]);
        $skippedSchedule = $this->schedule($user, [
            'dose_key' => 'morning_skipped',
            'schedule_time' => '09:30:00',
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
        ]);

        foreach ([[$takenSchedule, 'taken'], [$skippedSchedule, 'skipped']] as [$schedule, $status]) {
            MedicationDoseLog::create([
                'dose_schedule_id' => $schedule->id,
                'user_id' => $user->id,
                'workspace_id' => $schedule->workspace_id,
                'dose_date' => '2026-06-23',
                'scheduled_for' => CarbonImmutable::parse('2026-06-23 04:30:00', 'UTC'),
                'scheduled_timezone' => 'Asia/Dubai',
                'status' => $status,
                'next_reminder_at' => CarbonImmutable::parse('2026-06-23 04:00:00', 'UTC'),
            ]);
        }

        $result = app(MedicationReminderService::class)->queueDueReminders(
            CarbonImmutable::parse('2026-06-23 05:00:00', 'UTC'),
            sync: true,
            channel: 'test-database'
        );

        $this->assertSame(0, $result['due_candidate_count']);
        $this->assertSame(0, $result['queued']);
        $this->assertSame(0, MedicationDoseLog::query()->sum('reminder_attempts'));
        $this->assertDatabaseMissing('medication_reminder_events', [
            'event_type' => 'reminder_sent',
        ]);
    }

    public function test_reminders_repeat_until_taken_and_stop_after_acknowledgement(): void
    {
        [$user] = $this->context();
        $this->schedule($user, ['schedule_time' => '08:30:00', 'repeat_interval_minutes' => 30]);
        $this->travelToDubai('2026-06-23 08:31:00');

        app(MedicationReminderService::class)->queueDueReminders(sync: true);
        $this->travelToDubai('2026-06-23 09:02:00');
        app(MedicationReminderService::class)->queueDueReminders(sync: true);

        $log = MedicationDoseLog::firstOrFail();
        $this->assertSame(2, $log->reminder_attempts);

        app(MedicationReminderService::class)->markTaken($log->fresh(), 'test', 'test-device');

        $this->travelToDubai('2026-06-23 09:40:00');
        app(MedicationReminderService::class)->queueDueReminders(sync: true);

        $this->assertSame(2, $log->fresh()->reminder_attempts);
        $this->assertSame('taken', $log->fresh()->status);
        $this->assertNotNull($log->fresh()->acknowledged_at);
        $this->assertDatabaseHas('medication_reminder_events', ['dose_log_id' => $log->id, 'event_type' => 'taken']);
    }

    public function test_snooze_defers_next_reminder_until_snooze_expires(): void
    {
        [$user] = $this->context();
        $this->schedule($user, ['schedule_time' => '08:30:00']);
        $this->travelToDubai('2026-06-23 08:31:00');
        app(MedicationReminderService::class)->queueDueReminders(sync: true);
        $log = MedicationDoseLog::firstOrFail();

        app(MedicationReminderService::class)->snooze($log, 30, 'test', 'test-device');

        $this->travelToDubai('2026-06-23 08:50:00');
        app(MedicationReminderService::class)->queueDueReminders(sync: true);
        $this->assertSame(1, $log->fresh()->reminder_attempts);

        $this->travelToDubai('2026-06-23 09:02:00');
        app(MedicationReminderService::class)->queueDueReminders(sync: true);
        $this->assertSame(2, $log->fresh()->reminder_attempts);
    }

    public function test_snoozed_reminder_is_picked_up_within_next_minute(): void
    {
        [$user] = $this->context();
        $this->schedule($user, ['schedule_time' => '08:30:00']);

        $this->artisan('miriam:send-medication-reminders', [
            '--sync' => true,
            '--test-channel' => 'test-database',
            '--pretend-now' => '2026-06-23 08:31',
        ])->assertExitCode(0);

        $log = MedicationDoseLog::firstOrFail();
        $this->travelToDubai('2026-06-23 08:31:00');
        app(MedicationReminderService::class)->snooze($log, 5, 'test', 'test-database');

        $this->artisan('miriam:send-medication-reminders', [
            '--sync' => true,
            '--test-channel' => 'test-database',
            '--pretend-now' => '2026-06-23 08:35',
        ])->expectsOutputToContain('0 queued/delivered')->assertExitCode(0);
        $this->assertSame(1, $log->fresh()->reminder_attempts);

        $this->artisan('miriam:send-medication-reminders', [
            '--sync' => true,
            '--test-channel' => 'test-database',
            '--pretend-now' => '2026-06-23 08:36',
        ])->expectsOutputToContain('1 queued/delivered')->assertExitCode(0);
        $this->assertSame(2, $log->fresh()->reminder_attempts);
    }

    public function test_skip_requires_reason_and_stops_future_reminders(): void
    {
        [$user] = $this->context();
        $this->schedule($user, ['schedule_time' => '08:30:00']);
        $this->travelToDubai('2026-06-23 08:31:00');
        app(MedicationReminderService::class)->queueDueReminders(sync: true);
        $log = MedicationDoseLog::firstOrFail();

        app(MedicationReminderService::class)->skip($log, 'not today', 'test', 'test-device');
        $this->travelToDubai('2026-06-23 09:30:00');
        app(MedicationReminderService::class)->queueDueReminders(sync: true);

        $this->assertSame('skipped', $log->fresh()->status);
        $this->assertSame('not today', $log->fresh()->skip_reason);
        $this->assertSame(1, $log->fresh()->reminder_attempts);
    }

    public function test_morning_reminder_escalates_before_ten_am_deadline(): void
    {
        config(['services.slack.webhook_url' => 'https://hooks.slack.test/medication']);
        Http::fake([
            'hooks.slack.test/*' => Http::response('ok', 200),
        ]);
        [$user] = $this->context();
        $this->schedule($user, ['schedule_time' => '09:00:00']);

        foreach (['09:00:00', '09:15:00', '09:30:00', '09:45:00', '09:55:00'] as $time) {
            $this->travelToDubai('2026-06-23 '.$time);
            app(MedicationReminderService::class)->queueDueReminders(sync: true);
        }

        $log = MedicationDoseLog::firstOrFail();
        $this->assertSame(5, $log->reminder_attempts);
        $this->assertSame('2026-06-23 06:00:00', $log->next_reminder_at->toDateTimeString());
        $this->assertSame(5, $log->events()->where('event_type', 'slack_reminder_sent')->count());
        $this->assertDatabaseHas('medication_reminder_events', [
            'dose_log_id' => $log->id,
            'event_type' => 'reminder_sent',
            'metadata->escalation_level' => 'stronger',
        ]);
        $this->assertDatabaseHas('medication_reminder_events', [
            'dose_log_id' => $log->id,
            'event_type' => 'reminder_sent',
            'metadata->escalation_level' => 'urgent',
        ]);
        $this->assertDatabaseHas('medication_reminder_events', [
            'dose_log_id' => $log->id,
            'event_type' => 'reminder_sent',
            'metadata->escalation_level' => 'final_pre_deadline',
        ]);
        Http::assertSent(function ($request) {
            return $request->data()['text'] === 'Please take your medication.';
        });
    }

    public function test_morning_dose_becomes_overdue_after_ten(): void
    {
        config(['services.slack.webhook_url' => 'https://hooks.slack.test/medication']);
        Http::fake([
            'hooks.slack.test/*' => Http::response('ok', 200),
        ]);
        [$user] = $this->context();
        $this->schedule($user, ['schedule_time' => '09:00:00']);
        $this->travelToDubai('2026-06-23 10:01:00');

        app(MedicationReminderService::class)->queueDueReminders(sync: true);

        $log = MedicationDoseLog::firstOrFail();
        $this->assertSame('overdue', $log->status);
        $this->assertSame('2026-06-23 06:06:00', $log->next_reminder_at->toDateTimeString());
        $this->assertDatabaseHas('medication_reminder_events', [
            'dose_log_id' => $log->id,
            'event_type' => 'slack_reminder_sent',
            'metadata->escalation_level' => 'overdue',
        ]);
        Http::assertSent(function ($request) {
            return $request->data()['text'] === 'Medication is overdue. Please mark Taken or Skip.';
        });
    }

    public function test_overdue_repeats_until_taken_before_final_cutoff(): void
    {
        [$user] = $this->context();
        $this->schedule($user, ['schedule_time' => '09:00:00']);
        $this->travelToDubai('2026-06-23 10:01:00');
        app(MedicationReminderService::class)->queueDueReminders(sync: true);
        $this->travelToDubai('2026-06-23 10:06:00');
        app(MedicationReminderService::class)->queueDueReminders(sync: true);

        $log = MedicationDoseLog::firstOrFail();
        $this->assertSame(2, $log->reminder_attempts);
        $this->assertSame('overdue', $log->status);

        app(MedicationReminderService::class)->markTaken($log->fresh(), 'test', 'test-device');
        $this->travelToDubai('2026-06-23 10:20:00');
        app(MedicationReminderService::class)->queueDueReminders(sync: true);

        $this->assertSame(2, $log->fresh()->reminder_attempts);
        $this->assertSame('taken', $log->fresh()->status);
    }

    public function test_morning_snooze_can_extend_after_deadline_before_final_cutoff(): void
    {
        [$user] = $this->context();
        $this->schedule($user, ['schedule_time' => '09:00:00']);
        $this->travelToDubai('2026-06-23 09:50:00');
        $log = app(MedicationReminderService::class)->ensureLogsForActiveSchedules()->first();

        app(MedicationReminderService::class)->snooze($log, 30, 'test', 'test-device');

        $this->assertSame('snoozed', $log->fresh()->status);
        $this->assertSame('2026-06-23 06:20:00', $log->fresh()->next_reminder_at->toDateTimeString());
    }

    public function test_skip_requires_reason(): void
    {
        [$user] = $this->context();
        $this->schedule($user, ['schedule_time' => '09:00:00']);
        $this->travelToDubai('2026-06-23 09:01:00');
        $log = app(MedicationReminderService::class)->ensureLogsForActiveSchedules()->first();

        $this->actingAs($user)
            ->from(route('health.index'))
            ->post(route('health.medication-doses.skip', $log), ['reason' => ''])
            ->assertRedirect(route('health.index'))
            ->assertSessionHasErrors('reason');

        $this->assertNotSame('skipped', $log->fresh()->status);
    }

    public function test_duplicate_dose_logs_and_duplicate_immediate_reminders_are_prevented(): void
    {
        [$user] = $this->context();
        $this->schedule($user, ['schedule_time' => '08:30:00']);
        $this->travelToDubai('2026-06-23 08:31:00');

        app(MedicationReminderService::class)->queueDueReminders(sync: true);
        app(MedicationReminderService::class)->queueDueReminders(sync: true);

        $this->assertSame(1, MedicationDoseLog::count());
        $this->assertSame(1, MedicationDoseLog::firstOrFail()->reminder_attempts);
        $this->assertSame(1, $user->notifications()->where('data->event_type', 'medication_reminder')->count());
    }

    public function test_slack_webhook_configured_and_called_with_private_message(): void
    {
        config(['services.slack.webhook_url' => 'https://hooks.slack.test/medication']);
        Http::fake([
            'hooks.slack.test/*' => Http::response('ok', 200),
        ]);
        [$user] = $this->context();
        $this->schedule($user, ['schedule_time' => '08:30:00']);
        $this->travelToDubai('2026-06-23 08:31:00');

        app(MedicationReminderService::class)->queueDueReminders(sync: true);

        Http::assertSent(function ($request) {
            $payload = $request->data();
            $buttonLabels = collect($payload['blocks'][1]['elements'])->pluck('text.text')->all();

            return $request->url() === 'https://hooks.slack.test/medication'
                && $payload['text'] === 'Please take your medication.'
                && in_array('Taken', $buttonLabels, true)
                && in_array('Snooze 15 min', $buttonLabels, true)
                && in_array('Skip', $buttonLabels, true)
                && ! str_contains($payload['text'], 'Xigduo')
                && ! str_contains($payload['text'], 'after breakfast');
        });
        $this->assertSame(1, $user->notifications()->where('data->event_type', 'medication_reminder')->count());
        $this->assertDatabaseHas('medication_reminder_events', [
            'event_type' => 'slack_reminder_sent',
            'channel' => 'slack',
        ]);
    }

    public function test_valid_slack_taken_button_marks_dose_taken(): void
    {
        [$user] = $this->context();
        $this->schedule($user, ['schedule_time' => '08:30:00']);
        $this->travelToDubai('2026-06-23 08:31:00');
        app(MedicationReminderService::class)->queueDueReminders(sync: true);
        $log = MedicationDoseLog::firstOrFail();

        $response = $this->postSignedSlackAction('medication_taken', $log->id);

        $response->assertOk()->assertJson(['text' => 'Confirmed. Medication marked as taken.']);
        $this->assertSame('taken', $log->fresh()->status);
        $this->assertNull($log->fresh()->next_reminder_at);
        $this->assertDatabaseHas('medication_reminder_events', [
            'dose_log_id' => $log->id,
            'event_type' => 'slack_taken_clicked',
            'channel' => 'slack',
        ]);
    }

    public function test_slack_taken_button_stops_repeat_reminders(): void
    {
        [$user] = $this->context();
        $this->schedule($user, ['schedule_time' => '08:30:00']);
        $this->travelToDubai('2026-06-23 08:31:00');
        app(MedicationReminderService::class)->queueDueReminders(sync: true);
        $log = MedicationDoseLog::firstOrFail();

        $this->postSignedSlackAction('medication_taken', $log->id)->assertOk();
        $this->travelToDubai('2026-06-23 09:10:00');
        app(MedicationReminderService::class)->queueDueReminders(sync: true);

        $this->assertSame(1, $log->fresh()->reminder_attempts);
        $this->assertSame('taken', $log->fresh()->status);
    }

    public function test_am_taken_closes_duplicate_active_logs_for_same_dose_date(): void
    {
        [$user] = $this->context();
        $schedule = $this->schedule($user, [
            'schedule_time' => '09:00:00',
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
        ]);
        $primary = $this->doseLog($schedule, [
            'dose_date' => '2026-06-30',
            'status' => 'overdue',
            'next_reminder_at' => CarbonImmutable::parse('2026-06-30 06:30:00', 'UTC'),
        ]);
        $duplicate = $this->doseLog($schedule, [
            'dose_date' => '2026-06-30',
            'status' => 'pending',
            'next_reminder_at' => CarbonImmutable::parse('2026-06-30 06:30:00', 'UTC'),
        ]);

        $this->travelToDubai('2026-06-30 10:15:00');
        app(MedicationReminderService::class)->markTaken($primary, 'slack', 'slack');

        $this->assertSame('taken', $primary->fresh()->status);
        $this->assertNull($primary->fresh()->next_reminder_at);
        $this->assertSame('superseded', $duplicate->fresh()->status);
        $this->assertNull($duplicate->fresh()->next_reminder_at);
        $this->assertDatabaseHas('medication_reminder_events', [
            'dose_log_id' => $duplicate->id,
            'event_type' => 'duplicate_dose_log_superseded',
        ]);
    }

    public function test_scheduler_run_after_taken_with_duplicate_sends_nothing(): void
    {
        config(['services.slack.webhook_url' => 'https://hooks.slack.test/medication']);
        Http::fake([
            'hooks.slack.test/*' => Http::response('ok', 200),
        ]);
        [$user] = $this->context();
        $schedule = $this->schedule($user, [
            'schedule_time' => '09:00:00',
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
        ]);
        $primary = $this->doseLog($schedule, [
            'dose_date' => '2026-06-30',
            'status' => 'overdue',
            'reminder_attempts' => 1,
            'next_reminder_at' => CarbonImmutable::parse('2026-06-30 06:30:00', 'UTC'),
        ]);
        $this->doseLog($schedule, [
            'dose_date' => '2026-06-30',
            'status' => 'snoozed',
            'next_reminder_at' => CarbonImmutable::parse('2026-06-30 06:30:00', 'UTC'),
        ]);

        $this->travelToDubai('2026-06-30 10:15:00');
        app(MedicationReminderService::class)->markTaken($primary, 'slack', 'slack');
        app(MedicationReminderService::class)->queueDueReminders(sync: true);

        Http::assertNothingSent();
        $this->assertSame(1, $primary->fresh()->reminder_attempts);
        $this->assertSame(0, MedicationDoseLog::query()->whereIn('status', ['pending', 'snoozed', 'overdue'])->count());
    }

    public function test_slack_snooze_button_updates_next_reminder(): void
    {
        [$user] = $this->context();
        $this->schedule($user, [
            'dose_key' => 'evening',
            'schedule_time' => '21:30:00',
            'hard_deadline_time' => null,
        ]);
        $this->travelToDubai('2026-06-23 21:31:00');
        app(MedicationReminderService::class)->queueDueReminders(sync: true);
        $log = MedicationDoseLog::firstOrFail();

        $this->postSignedSlackAction('medication_snooze_15', $log->id)
            ->assertOk()
            ->assertJson(['text' => 'Snoozed for 15 minutes.']);

        $this->assertSame('snoozed', $log->fresh()->status);
        $this->assertSame('2026-06-23 17:46:00', $log->fresh()->next_reminder_at->toDateTimeString());
        $this->assertDatabaseHas('medication_reminder_events', [
            'dose_log_id' => $log->id,
            'event_type' => 'slack_snooze_clicked',
            'channel' => 'slack',
        ]);
    }

    public function test_snooze_after_taken_is_ignored(): void
    {
        [$user] = $this->context();
        $schedule = $this->schedule($user, ['schedule_time' => '09:00:00']);
        $log = $this->doseLog($schedule, [
            'dose_date' => '2026-06-30',
            'status' => 'overdue',
            'next_reminder_at' => CarbonImmutable::parse('2026-06-30 06:05:00', 'UTC'),
        ]);

        $this->travelToDubai('2026-06-30 10:01:00');
        app(MedicationReminderService::class)->markTaken($log, 'test', 'test-device');
        app(MedicationReminderService::class)->snooze($log->fresh(), 15, 'slack', 'slack');

        $this->assertSame('taken', $log->fresh()->status);
        $this->assertNull($log->fresh()->next_reminder_at);
        $this->assertDatabaseHas('medication_reminder_events', [
            'dose_log_id' => $log->id,
            'event_type' => 'snooze_ignored',
            'channel' => 'slack',
        ]);
    }

    public function test_slack_snooze_button_can_extend_after_ten_before_final_cutoff(): void
    {
        [$user] = $this->context();
        $this->schedule($user, ['schedule_time' => '09:00:00']);
        $this->travelToDubai('2026-06-23 09:50:00');
        $log = app(MedicationReminderService::class)->ensureLogsForActiveSchedules()->first();

        $this->postSignedSlackAction('medication_snooze_15', $log->id)->assertOk();

        $this->assertSame('snoozed', $log->fresh()->status);
        $this->assertSame('2026-06-23 06:05:00', $log->fresh()->next_reminder_at->toDateTimeString());
    }

    public function test_slack_skip_button_marks_dose_skipped(): void
    {
        [$user] = $this->context();
        $this->schedule($user, ['schedule_time' => '08:30:00']);
        $this->travelToDubai('2026-06-23 08:31:00');
        $log = app(MedicationReminderService::class)->ensureLogsForActiveSchedules()->first();

        $response = $this->postSignedSlackAction('medication_skip', $log->id);

        $response->assertOk()->assertJson(['text' => 'Confirmed. Medication skipped.']);
        $this->assertSame('skipped', $log->fresh()->status);
        $this->assertNull($log->fresh()->next_reminder_at);
        $this->assertDatabaseHas('medication_reminder_events', [
            'dose_log_id' => $log->id,
            'event_type' => 'slack_skip_clicked',
            'channel' => 'slack',
        ]);
        $this->assertDatabaseHas('medication_reminder_events', [
            'dose_log_id' => $log->id,
            'event_type' => 'skipped',
            'channel' => 'slack',
        ]);
    }

    public function test_invalid_slack_signature_is_rejected(): void
    {
        config(['services.slack.signing_secret' => 'test-signing-secret']);

        $this->call(
            'POST',
            route('slack.medication.actions', absolute: false),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
                'HTTP_X_SLACK_REQUEST_TIMESTAMP' => (string) time(),
                'HTTP_X_SLACK_SIGNATURE' => 'v0=invalid',
            ],
            http_build_query(['payload' => json_encode($this->slackActionPayload('medication_taken', 1))])
        )->assertUnauthorized();
    }

    public function test_duplicate_slack_taken_clicks_are_idempotent(): void
    {
        [$user] = $this->context();
        $this->schedule($user, ['schedule_time' => '08:30:00']);
        $this->travelToDubai('2026-06-23 08:31:00');
        app(MedicationReminderService::class)->queueDueReminders(sync: true);
        $log = MedicationDoseLog::firstOrFail();

        $this->postSignedSlackAction('medication_taken', $log->id)->assertOk();
        $this->postSignedSlackAction('medication_taken', $log->id)->assertOk();

        $this->assertSame('taken', $log->fresh()->status);
        $this->assertSame(1, $log->fresh()->events()->where('event_type', 'taken')->count());
        $this->assertSame(2, $log->fresh()->events()->where('event_type', 'slack_taken_clicked')->count());
    }

    public function test_missing_slack_webhook_falls_back_to_database_notification_only(): void
    {
        config(['services.slack.webhook_url' => null]);
        Http::fake();
        [$user] = $this->context();
        $this->schedule($user, ['schedule_time' => '08:30:00']);
        $this->travelToDubai('2026-06-23 08:31:00');

        app(MedicationReminderService::class)->queueDueReminders(sync: true);

        Http::assertNothingSent();
        $this->assertSame(1, $user->notifications()->where('data->event_type', 'medication_reminder')->count());
        $this->assertDatabaseHas('medication_reminder_events', [
            'event_type' => 'slack_reminder_skipped',
            'channel' => 'slack',
        ]);
    }

    public function test_slack_delivery_failure_is_logged_without_failing_database_notification(): void
    {
        config(['services.slack.webhook_url' => 'https://hooks.slack.test/medication']);
        Http::fake([
            'hooks.slack.test/*' => Http::response('temporary failure', 500),
        ]);
        [$user] = $this->context();
        $this->schedule($user, ['schedule_time' => '08:30:00']);
        $this->travelToDubai('2026-06-23 08:31:00');

        app(MedicationReminderService::class)->queueDueReminders(sync: true);

        Http::assertSentCount(1);
        $this->assertSame(1, $user->notifications()->where('data->event_type', 'medication_reminder')->count());
        $this->assertDatabaseHas('medication_reminder_events', [
            'event_type' => 'slack_reminder_failed',
            'channel' => 'slack',
        ]);
    }

    public function test_slack_message_includes_dose_details_only_when_privacy_allows_it(): void
    {
        config(['services.slack.webhook_url' => 'https://hooks.slack.test/medication']);
        Http::fake([
            'hooks.slack.test/*' => Http::response('ok', 200),
        ]);
        [$user] = $this->context();
        $this->schedule($user, [
            'schedule_time' => '08:30:00',
            'hide_details_in_notifications' => false,
        ]);
        $this->travelToDubai('2026-06-23 08:31:00');

        app(MedicationReminderService::class)->queueDueReminders(sync: true);

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return str_contains($payload['text'], 'Morning medications')
                && str_contains($payload['text'], 'Xigduo XR 5mg/1000mg')
                && str_contains($payload['text'], 'Physiotens 0.2mg')
                && str_contains($payload['text'], 'after breakfast');
        });
    }

    public function test_duplicate_reminder_attempt_does_not_send_duplicate_slack_delivery(): void
    {
        config(['services.slack.webhook_url' => 'https://hooks.slack.test/medication']);
        Http::fake([
            'hooks.slack.test/*' => Http::response('ok', 200),
        ]);
        [$user] = $this->context();
        $this->schedule($user, ['schedule_time' => '08:30:00']);
        $this->travelToDubai('2026-06-23 08:31:00');

        app(MedicationReminderService::class)->queueDueReminders(sync: true);
        app(MedicationReminderService::class)->queueDueReminders(sync: true);

        Http::assertSentCount(1);
        $this->assertSame(1, MedicationDoseLog::firstOrFail()->reminder_attempts);
        $this->assertDatabaseCount('medication_reminder_events', 5);
        $this->assertSame(1, MedicationDoseLog::firstOrFail()->events()->where('event_type', 'slack_reminder_sent')->count());
    }

    public function test_medication_reminder_creates_private_google_calendar_event_with_morning_medication_details(): void
    {
        [$user, $workspace] = $this->context();
        $connection = $this->connection($user, $workspace);
        $connection->forceFill(['token_expires_at' => Carbon::parse('2026-06-24 00:00:00', 'Asia/Dubai')])->save();
        $this->enableGoogleCalendar();
        $this->schedule($user, ['schedule_time' => '09:00:00']);
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response([
                'id' => 'google-med-dose-1',
                'summary' => "Sam's Medication - Morning",
                'start' => ['dateTime' => '2026-06-23T09:00:00+04:00'],
                'organizer' => ['email' => 'primary'],
            ], 200),
        ]);
        $this->travelToDubai('2026-06-23 09:01:00');

        app(MedicationReminderService::class)->queueDueReminders(sync: true);

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return $request->method() === 'POST'
                && str_contains($request->url(), '/calendar/v3/calendars/primary/events')
                && $payload['summary'] === "Sam's Medication - Morning"
                && $payload['description'] === "Medication due:\n- Xigduo XR 5mg/1000mg\n- Physiotens 0.2mg\n- Lodiva 10mg/160mg\n- Aterpen 10mg/20mg\n\nConfirm Taken, Snooze, or Skip in Miriam/Slack."
                && $payload['visibility'] === 'private'
                && ($payload['extendedProperties']['private']['miriam_source'] ?? null) === 'medication_reminder';
        });
        $this->assertDatabaseHas('calendar_event_mappings', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_event_id' => 'google-med-dose-1',
        ]);
        $this->assertDatabaseHas('medication_reminder_events', [
            'event_type' => 'calendar_event_created',
            'channel' => 'google_calendar',
        ]);
    }

    public function test_evening_medication_calendar_event_uses_xigduo_title_and_description(): void
    {
        [$user, $workspace] = $this->context();
        $connection = $this->connection($user, $workspace);
        $connection->forceFill(['token_expires_at' => Carbon::parse('2026-06-24 00:00:00', 'Asia/Dubai')])->save();
        $this->enableGoogleCalendar();
        $this->schedule($user, [
            'dose_key' => 'evening',
            'label' => 'Evening medication',
            'dosage_text' => 'Xigduo XR 5mg/1000mg',
            'timing_note' => 'after dinner',
            'schedule_time' => '21:30:00',
            'hard_deadline_time' => null,
            'metadata' => [
                'frequency' => 'daily',
                'medication_items' => [
                    ['name' => 'Xigduo XR 5mg/1000mg', 'instruction' => 'Take twice daily', 'timing' => 'Night after dinner'],
                ],
            ],
        ]);
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response([
                'id' => 'google-evening-med-dose',
                'summary' => "Sam's Medication - Evening",
                'start' => ['dateTime' => '2026-06-23T21:30:00+04:00'],
                'organizer' => ['email' => 'primary'],
            ], 200),
        ]);
        $this->travelToDubai('2026-06-23 21:31:00');

        app(MedicationReminderService::class)->queueDueReminders(sync: true);

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return $request->method() === 'POST'
                && str_contains($request->url(), '/calendar/v3/calendars/primary/events')
                && ($payload['summary'] ?? null) === "Sam's Medication - Evening"
                && $payload['description'] === "Medication due:\n- Xigduo XR 5mg/1000mg\n\nConfirm Taken, Snooze, or Skip in Miriam/Slack."
                && $payload['visibility'] === 'private';
        });
    }

    public function test_ozempic_calendar_event_uses_weekly_title_and_description(): void
    {
        [$user, $workspace] = $this->context();
        $connection = $this->connection($user, $workspace);
        $connection->forceFill(['token_expires_at' => Carbon::parse('2026-06-25 00:00:00', 'Asia/Dubai')])->save();
        $this->enableGoogleCalendar();
        $this->schedule($user, [
            'dose_key' => 'weekly_ozempic',
            'label' => 'Weekly medication',
            'dosage_text' => 'Ozempic',
            'timing_note' => 'every Wednesday at 07:00',
            'schedule_time' => '07:00:00',
            'hard_deadline_time' => null,
            'metadata' => [
                'frequency' => 'weekly',
                'weekday' => 3,
                'weekday_name' => 'Wednesday',
                'medication_items' => [
                    ['name' => 'Ozempic', 'timing' => 'Every Wednesday at 07:00 Asia/Dubai'],
                ],
            ],
        ]);
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response([
                'id' => 'google-ozempic-med-dose',
                'summary' => "Sam's Medication - Ozempic",
                'start' => ['dateTime' => '2026-06-24T07:00:00+04:00'],
                'organizer' => ['email' => 'primary'],
            ], 200),
        ]);
        $this->travelToDubai('2026-06-24 07:01:00');

        app(MedicationReminderService::class)->queueDueReminders(sync: true);

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return $request->method() === 'POST'
                && str_contains($request->url(), '/calendar/v3/calendars/primary/events')
                && ($payload['summary'] ?? null) === "Sam's Medication - Ozempic"
                && $payload['description'] === "Medication due:\n- Ozempic\n\nConfirm Taken, Snooze, or Skip in Miriam/Slack."
                && $payload['visibility'] === 'private';
        });
    }

    public function test_medication_reminder_falls_back_when_google_calendar_not_connected(): void
    {
        [$user] = $this->context();
        $this->enableGoogleCalendar();
        $this->schedule($user, ['schedule_time' => '08:30:00']);
        Http::fake();
        $this->travelToDubai('2026-06-23 08:31:00');

        app(MedicationReminderService::class)->queueDueReminders(sync: true);

        Http::assertNothingSent();
        $this->assertSame(1, $user->notifications()->where('data->event_type', 'medication_reminder')->count());
        $this->assertDatabaseHas('medication_reminder_events', [
            'event_type' => 'calendar_event_skipped',
            'channel' => 'google_calendar',
        ]);
    }

    public function test_repeated_medication_reminder_updates_existing_google_calendar_event(): void
    {
        [$user, $workspace] = $this->context();
        $this->connection($user, $workspace);
        $this->enableGoogleCalendar();
        $this->schedule($user, ['schedule_time' => '08:30:00']);
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response([
                'id' => 'google-med-dose-1',
                'summary' => "Sam's Medication - Morning",
                'start' => ['dateTime' => '2026-06-23T08:30:00+04:00'],
                'organizer' => ['email' => 'primary'],
            ], 200),
            'https://www.googleapis.com/calendar/v3/calendars/primary/events/google-med-dose-1' => Http::response([
                'id' => 'google-med-dose-1',
                'summary' => "Sam's Medication - Morning",
                'start' => ['dateTime' => '2026-06-23T08:30:00+04:00'],
                'organizer' => ['email' => 'primary'],
            ], 200),
        ]);

        $this->travelToDubai('2026-06-23 08:31:00');
        app(MedicationReminderService::class)->queueDueReminders(sync: true);
        $this->travelToDubai('2026-06-23 08:46:00');
        app(MedicationReminderService::class)->queueDueReminders(sync: true);

        $this->assertSame(1, CalendarEventMapping::where('provider_event_id', 'google-med-dose-1')->count());
        $this->assertDatabaseHas('medication_reminder_events', ['event_type' => 'calendar_event_created']);
        $this->assertDatabaseHas('medication_reminder_events', ['event_type' => 'calendar_event_updated']);
        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            $payload = $request->data();

            return $request->method() === 'PATCH'
                && str_contains($request->url(), '/calendar/v3/calendars/primary/events/google-med-dose-1')
                && ($payload['summary'] ?? null) === "Sam's Medication - Morning"
                && ($payload['description'] ?? null) === "Medication due:\n- Xigduo XR 5mg/1000mg\n- Physiotens 0.2mg\n- Lodiva 10mg/160mg\n- Aterpen 10mg/20mg\n\nConfirm Taken, Snooze, or Skip in Miriam/Slack."
                && ($payload['visibility'] ?? null) === 'private';
        });
    }

    public function test_calendar_failure_does_not_create_retry_spam(): void
    {
        [$user, $workspace] = $this->context();
        $this->connection($user, $workspace);
        $this->enableGoogleCalendar();
        $this->schedule($user, [
            'schedule_time' => '09:00:00',
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
        ]);
        Http::fake([
            'https://www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response(['error' => 'temporary'], 500),
        ]);

        $this->travelToDubai('2026-06-23 09:01:00');
        app(MedicationReminderService::class)->queueDueReminders(sync: true);
        $this->travelToDubai('2026-06-23 09:15:00');
        app(MedicationReminderService::class)->queueDueReminders(sync: true);

        $log = MedicationDoseLog::firstOrFail();
        $this->assertSame(2, $log->fresh()->reminder_attempts);
        $this->assertSame(1, $log->events()->where('event_type', 'calendar_event_failed')->count());
    }

    public function test_quiet_hours_suppress_delivery_but_flag_overdue(): void
    {
        [$user] = $this->context();
        $this->schedule($user, [
            'dose_key' => 'bedtime',
            'schedule_time' => '23:00:00',
        ]);
        $this->travelToDubai('2026-06-23 23:01:00');

        app(MedicationReminderService::class)->queueDueReminders(sync: true);

        $log = MedicationDoseLog::firstOrFail();
        $this->assertSame('overdue', $log->status);
        $this->assertSame(0, $log->reminder_attempts);
        $this->assertSame(0, $user->notifications()->count());
        $this->assertDatabaseHas('medication_reminder_events', [
            'dose_log_id' => $log->id,
            'event_type' => 'reminder_suppressed_quiet_hours',
        ]);
    }

    public function test_asia_dubai_timezone_and_no_dst_shift_are_respected(): void
    {
        [$user] = $this->context();
        $this->schedule($user, ['schedule_time' => '08:30:00']);

        $this->travelToDubai('2026-01-10 08:31:00');
        $winter = app(MedicationReminderService::class)->ensureLogsForActiveSchedules()->first();
        MedicationDoseLog::query()->delete();

        $this->travelToDubai('2026-07-10 08:31:00');
        $summer = app(MedicationReminderService::class)->ensureLogsForActiveSchedules()->first();

        $this->assertSame('04:30', $winter->scheduled_for->format('H:i'));
        $this->assertSame('04:30', $summer->scheduled_for->format('H:i'));
        $this->assertSame('Asia/Dubai', $summer->scheduled_timezone);
    }

    public function test_acknowledgement_persists_across_service_restarts(): void
    {
        [$user] = $this->context();
        $this->schedule($user, ['schedule_time' => '08:30:00']);
        $this->travelToDubai('2026-06-23 08:31:00');
        app(MedicationReminderService::class)->queueDueReminders(sync: true);
        $log = MedicationDoseLog::firstOrFail();
        app(MedicationReminderService::class)->markTaken($log, 'test', 'test-device');

        $freshService = app()->make(MedicationReminderService::class);
        $this->travelToDubai('2026-06-23 10:00:00');
        $freshService->queueDueReminders(sync: true);

        $this->assertSame('taken', $log->fresh()->status);
        $this->assertSame(1, $log->fresh()->reminder_attempts);
    }

    public function test_health_page_shows_today_dose_status(): void
    {
        [$user] = $this->context();
        $this->schedule($user, ['schedule_time' => '08:30:00']);
        $this->travelToDubai('2026-06-23 08:31:00');

        $this->actingAs($user)
            ->get(route('health.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('medicationDoseStatus.items.0.label', 'Morning medications')
                ->where('medicationDoseStatus.items.0.status', 'overdue')
                ->where('medicationDoseStatus.items.0.medication_items.0.name', 'Xigduo XR 5mg/1000mg')
                ->where('medicationDoseStatus.routine.0.medication_items.0.name', 'Xigduo XR 5mg/1000mg')
                ->where('googleCalendar.connected', false)
                ->where('googleCalendar.connect_url', route('settings.integrations.google.connect'))
            );
    }

    public function test_web_taken_snooze_and_skip_routes_are_authorized_and_persisted(): void
    {
        [$user] = $this->context();
        $other = User::factory()->create();
        $this->schedule($user, ['schedule_time' => '08:30:00']);
        $this->travelToDubai('2026-06-23 08:31:00');
        $log = app(MedicationReminderService::class)->ensureLogsForActiveSchedules()->first();

        $this->actingAs($other)->post(route('health.medication-doses.taken', $log))->assertForbidden();

        $this->actingAs($user)->post(route('health.medication-doses.snooze', $log), ['minutes' => 20])->assertRedirect();
        $this->assertSame('snoozed', $log->fresh()->status);

        $this->actingAs($user)->post(route('health.medication-doses.skip', $log), ['reason' => 'optional reason'])->assertRedirect();
        $this->assertSame('skipped', $log->fresh()->status);
        $this->assertSame('optional reason', $log->fresh()->skip_reason);
    }

    public function test_am_unresponded_after_noon_becomes_not_responded_and_sends_no_more_slack(): void
    {
        config(['services.slack.webhook_url' => 'https://hooks.slack.test/medication']);
        Http::fake([
            'hooks.slack.test/*' => Http::response('ok', 200),
        ]);
        [$user] = $this->context();
        $schedule = $this->schedule($user, [
            'schedule_time' => '09:00:00',
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
        ]);
        $log = $this->doseLog($schedule, [
            'dose_date' => '2026-06-30',
            'status' => 'overdue',
            'next_reminder_at' => CarbonImmutable::parse('2026-06-30 08:00:00', 'UTC'),
        ]);

        app(MedicationReminderService::class)->queueDueReminders(
            CarbonImmutable::parse('2026-06-30 08:00:00', 'UTC'),
            sync: true,
            channel: 'test-database'
        );
        app(MedicationReminderService::class)->queueDueReminders(
            CarbonImmutable::parse('2026-06-30 08:05:00', 'UTC'),
            sync: true,
            channel: 'test-database'
        );

        Http::assertNothingSent();
        $this->assertSame('not_responded', $log->fresh()->status);
        $this->assertNull($log->fresh()->next_reminder_at);
        $this->assertNull($log->fresh()->acknowledged_at);
        $this->assertSame(0, $log->fresh()->reminder_attempts);
        $this->assertSame(1, $log->events()->where('event_type', 'dose_marked_not_responded')->count());
    }

    public function test_pm_unresponded_after_2330_becomes_not_responded_and_sends_no_more_slack(): void
    {
        config(['services.slack.webhook_url' => 'https://hooks.slack.test/medication']);
        Http::fake([
            'hooks.slack.test/*' => Http::response('ok', 200),
        ]);
        [$user] = $this->context();
        $schedule = $this->schedule($user, [
            'dose_key' => 'evening',
            'label' => 'Evening medication',
            'schedule_time' => '21:30:00',
            'hard_deadline_time' => null,
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
        ]);
        $log = $this->doseLog($schedule, [
            'dose_date' => '2026-06-30',
            'scheduled_for' => CarbonImmutable::parse('2026-06-30 17:30:00', 'UTC'),
            'status' => 'overdue',
            'next_reminder_at' => CarbonImmutable::parse('2026-06-30 19:30:00', 'UTC'),
        ]);

        app(MedicationReminderService::class)->queueDueReminders(
            CarbonImmutable::parse('2026-06-30 19:30:00', 'UTC'),
            sync: true,
            channel: 'test-database'
        );
        app(MedicationReminderService::class)->queueDueReminders(
            CarbonImmutable::parse('2026-06-30 19:35:00', 'UTC'),
            sync: true,
            channel: 'test-database'
        );

        Http::assertNothingSent();
        $this->assertSame('not_responded', $log->fresh()->status);
        $this->assertNull($log->fresh()->next_reminder_at);
        $this->assertSame(0, $log->fresh()->reminder_attempts);
        $this->assertSame(1, $log->events()->where('event_type', 'dose_marked_not_responded')->count());
    }

    public function test_morning_and_evening_dose_logs_are_independent(): void
    {
        [$user] = $this->context();
        $morning = $this->schedule($user, [
            'dose_key' => 'morning',
            'schedule_time' => '09:00:00',
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
        ]);
        $evening = $this->schedule($user, [
            'dose_key' => 'evening',
            'label' => 'Evening medication',
            'schedule_time' => '21:30:00',
            'hard_deadline_time' => null,
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
        ]);
        $morningLog = $this->doseLog($morning, [
            'dose_date' => '2026-06-30',
            'status' => 'overdue',
            'next_reminder_at' => CarbonImmutable::parse('2026-06-30 06:00:00', 'UTC'),
        ]);
        $eveningLog = $this->doseLog($evening, [
            'dose_date' => '2026-06-30',
            'scheduled_for' => CarbonImmutable::parse('2026-06-30 17:30:00', 'UTC'),
            'status' => 'pending',
            'next_reminder_at' => CarbonImmutable::parse('2026-06-30 17:30:00', 'UTC'),
        ]);

        $this->travelToDubai('2026-06-30 10:05:00');
        app(MedicationReminderService::class)->markTaken($morningLog, 'test', 'test-device');
        app(MedicationReminderService::class)->queueDueReminders(
            CarbonImmutable::parse('2026-06-30 17:31:00', 'UTC'),
            sync: true,
            channel: 'test-database'
        );

        $this->assertSame('taken', $morningLog->fresh()->status);
        $this->assertSame('overdue', $eveningLog->fresh()->status);
        $this->assertSame(1, $eveningLog->fresh()->reminder_attempts);
    }

    public function test_already_queued_send_rechecks_taken_status_before_slack_post(): void
    {
        config(['services.slack.webhook_url' => 'https://hooks.slack.test/medication']);
        Http::fake([
            'hooks.slack.test/*' => Http::response('ok', 200),
        ]);
        [$user] = $this->context();
        $schedule = $this->schedule($user, [
            'schedule_time' => '09:00:00',
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
        ]);
        $log = $this->doseLog($schedule, [
            'dose_date' => '2026-06-30',
            'status' => 'overdue',
            'next_reminder_at' => CarbonImmutable::parse('2026-06-30 06:01:00', 'UTC'),
        ]);

        $this->travelToDubai('2026-06-30 10:01:00');
        app(MedicationReminderService::class)->markTaken($log, 'slack', 'slack');
        app(MedicationReminderService::class)->deliverReminder($log->id, 'slack', CarbonImmutable::parse('2026-06-30 06:01:00', 'UTC'));

        Http::assertNothingSent();
        $this->assertSame('taken', $log->fresh()->status);
        $this->assertSame(0, $log->fresh()->reminder_attempts);
        $this->assertDatabaseHas('medication_reminder_events', [
            'dose_log_id' => $log->id,
            'event_type' => 'duplicate_prevented',
            'channel' => 'slack',
        ]);
    }

    public function test_previous_day_overdue_dose_with_newer_same_schedule_is_closed_by_cleanup(): void
    {
        [$user] = $this->context();
        $schedule = $this->schedule($user, ['schedule_time' => '09:00:00']);
        $old = $this->doseLog($schedule, [
            'dose_date' => '2026-06-29',
            'scheduled_for' => CarbonImmutable::parse('2026-06-29 05:00:00', 'UTC'),
            'status' => 'overdue',
            'reminder_attempts' => 128,
            'next_reminder_at' => CarbonImmutable::parse('2026-06-30 03:00:00', 'UTC'),
        ]);
        $this->doseLog($schedule, [
            'dose_date' => '2026-06-30',
            'scheduled_for' => CarbonImmutable::parse('2026-06-30 05:00:00', 'UTC'),
            'status' => 'pending',
            'next_reminder_at' => CarbonImmutable::parse('2026-06-30 05:00:00', 'UTC'),
        ]);

        $result = app(MedicationReminderService::class)->closeStaleOverdueLogs(CarbonImmutable::parse('2026-06-30 04:00:00', 'UTC'));

        $this->assertSame(2, $result['inspected']);
        $this->assertSame(1, $result['closed']);
        $this->assertSame('not_responded', $old->fresh()->status);
        $this->assertNull($old->fresh()->next_reminder_at);
        $this->assertSame('final_response_window_closed', $old->fresh()->acknowledgement_source);
        $this->assertNull($old->fresh()->acknowledged_at);
        $this->assertDatabaseHas('medication_reminder_events', [
            'dose_log_id' => $old->id,
            'event_type' => 'dose_marked_not_responded',
            'channel' => 'system',
        ]);
    }

    public function test_quiet_hours_do_not_roll_stale_previous_day_logs_forward(): void
    {
        [$user] = $this->context();
        $schedule = $this->schedule($user, [
            'dose_key' => 'evening',
            'label' => 'Evening medication',
            'schedule_time' => '21:30:00',
        ]);
        $old = $this->doseLog($schedule, [
            'dose_date' => '2026-06-29',
            'scheduled_for' => CarbonImmutable::parse('2026-06-29 17:30:00', 'UTC'),
            'status' => 'overdue',
            'reminder_attempts' => 12,
            'next_reminder_at' => CarbonImmutable::parse('2026-06-30 19:01:00', 'UTC'),
        ]);
        $today = $this->doseLog($schedule, [
            'dose_date' => '2026-06-30',
            'scheduled_for' => CarbonImmutable::parse('2026-06-30 17:30:00', 'UTC'),
            'status' => 'pending',
            'next_reminder_at' => CarbonImmutable::parse('2026-06-30 17:30:00', 'UTC'),
        ]);

        app(MedicationReminderService::class)->queueDueReminders(
            CarbonImmutable::parse('2026-06-30 19:01:00', 'UTC'),
            sync: true,
            channel: 'test-database'
        );

        $this->assertSame('not_responded', $old->fresh()->status);
        $this->assertNull($old->fresh()->next_reminder_at);
        $this->assertSame('overdue', $today->fresh()->status);
        $this->assertSame('2026-06-30 19:30:00', $today->fresh()->next_reminder_at->toDateTimeString());
        $this->assertDatabaseHas('medication_reminder_events', [
            'dose_log_id' => $old->id,
            'event_type' => 'dose_marked_not_responded',
        ]);
        $this->assertDatabaseHas('medication_reminder_events', [
            'dose_log_id' => $today->id,
            'event_type' => 'reminder_sent',
        ]);
    }

    public function test_slack_taken_on_newer_dose_closes_older_overdue_logs_for_same_schedule(): void
    {
        [$user] = $this->context();
        $schedule = $this->schedule($user, ['schedule_time' => '09:00:00']);
        $old = $this->doseLog($schedule, [
            'dose_date' => '2026-06-29',
            'scheduled_for' => CarbonImmutable::parse('2026-06-29 05:00:00', 'UTC'),
            'status' => 'overdue',
            'reminder_attempts' => 40,
            'next_reminder_at' => CarbonImmutable::parse('2026-06-30 05:00:00', 'UTC'),
        ]);
        $newer = $this->doseLog($schedule, [
            'dose_date' => '2026-06-30',
            'scheduled_for' => CarbonImmutable::parse('2026-06-30 05:00:00', 'UTC'),
            'status' => 'overdue',
            'next_reminder_at' => CarbonImmutable::parse('2026-06-30 05:30:00', 'UTC'),
        ]);

        $this->postSignedSlackAction('medication_taken', $newer->id)->assertOk();

        $this->assertSame('taken', $newer->fresh()->status);
        $this->assertNull($newer->fresh()->next_reminder_at);
        $this->assertSame('superseded', $old->fresh()->status);
        $this->assertNull($old->fresh()->next_reminder_at);
        $this->assertSame('taken_stale_closure', $old->fresh()->acknowledgement_source);
        $this->assertDatabaseHas('medication_reminder_events', [
            'dose_log_id' => $old->id,
            'event_type' => 'duplicate_dose_log_superseded',
        ]);
    }

    public function test_today_pending_dose_is_not_closed_by_stale_cleanup(): void
    {
        [$user] = $this->context();
        $schedule = $this->schedule($user, ['schedule_time' => '09:00:00']);
        $today = $this->doseLog($schedule, [
            'dose_date' => '2026-06-30',
            'scheduled_for' => CarbonImmutable::parse('2026-06-30 05:00:00', 'UTC'),
            'status' => 'pending',
            'next_reminder_at' => CarbonImmutable::parse('2026-06-30 05:00:00', 'UTC'),
        ]);

        $result = app(MedicationReminderService::class)->closeStaleOverdueLogs(CarbonImmutable::parse('2026-06-30 07:00:00', 'UTC'));

        $this->assertSame(0, $result['closed']);
        $this->assertSame('pending', $today->fresh()->status);
        $this->assertNotNull($today->fresh()->next_reminder_at);
    }

    public function test_today_overdue_dose_still_nags_until_final_cutoff(): void
    {
        [$user] = $this->context();
        $this->schedule($user, [
            'schedule_time' => '09:00:00',
            'quiet_hours_start' => null,
            'quiet_hours_end' => null,
        ]);
        $this->travelToDubai('2026-06-30 10:01:00');

        app(MedicationReminderService::class)->queueDueReminders(sync: true, channel: 'test-database');
        $log = MedicationDoseLog::firstOrFail();

        $this->assertSame('overdue', $log->status);
        $this->assertSame(1, $log->reminder_attempts);
        $this->assertNotNull($log->next_reminder_at);
        $this->assertDatabaseMissing('medication_reminder_events', [
            'dose_log_id' => $log->id,
            'event_type' => 'stale_overdue_closed',
        ]);
    }

    public function test_tomorrows_dose_can_still_be_scheduled_after_stale_cleanup(): void
    {
        [$user] = $this->context();
        $schedule = $this->schedule($user, ['schedule_time' => '09:00:00']);
        $this->doseLog($schedule, [
            'dose_date' => '2026-06-29',
            'scheduled_for' => CarbonImmutable::parse('2026-06-29 05:00:00', 'UTC'),
            'status' => 'overdue',
            'next_reminder_at' => CarbonImmutable::parse('2026-06-30 03:00:00', 'UTC'),
        ]);
        $this->doseLog($schedule, [
            'dose_date' => '2026-06-30',
            'scheduled_for' => CarbonImmutable::parse('2026-06-30 05:00:00', 'UTC'),
            'status' => 'taken',
            'acknowledged_at' => CarbonImmutable::parse('2026-06-30 05:05:00', 'UTC'),
            'next_reminder_at' => null,
        ]);

        app(MedicationReminderService::class)->closeStaleOverdueLogs(CarbonImmutable::parse('2026-06-30 06:00:00', 'UTC'));
        $this->travelToDubai('2026-07-01 09:01:00');
        $tomorrow = app(MedicationReminderService::class)->ensureLogForSchedule($schedule->fresh());

        $this->assertSame('2026-07-01', $tomorrow->dose_date->toDateString());
        $this->assertSame('pending', $tomorrow->status);
        $this->assertSame('2026-07-01 05:00:00', $tomorrow->scheduled_for->toDateTimeString());
    }

    public function test_stale_overdue_cleanup_command_is_idempotent(): void
    {
        [$user] = $this->context();
        $schedule = $this->schedule($user, ['schedule_time' => '09:00:00']);
        $old = $this->doseLog($schedule, [
            'dose_date' => '2026-06-29',
            'scheduled_for' => CarbonImmutable::parse('2026-06-29 05:00:00', 'UTC'),
            'status' => 'overdue',
            'next_reminder_at' => CarbonImmutable::parse('2026-06-30 03:00:00', 'UTC'),
        ]);
        $this->doseLog($schedule, [
            'dose_date' => '2026-06-30',
            'scheduled_for' => CarbonImmutable::parse('2026-06-30 05:00:00', 'UTC'),
            'status' => 'pending',
            'next_reminder_at' => CarbonImmutable::parse('2026-06-30 05:00:00', 'UTC'),
        ]);

        $this->artisan('medication:close-stale-overdue', ['--pretend-now' => '2026-06-30 08:00'])
            ->expectsOutputToContain('closed=1')
            ->assertExitCode(0);
        $this->artisan('medication:close-stale-overdue', ['--pretend-now' => '2026-06-30 08:00'])
            ->expectsOutputToContain('closed=0')
            ->assertExitCode(0);

        $this->assertSame('not_responded', $old->fresh()->status);
        $this->assertSame(1, $old->events()->where('event_type', 'dose_marked_not_responded')->count());
    }

    public function test_duplicate_pending_logs_are_safely_superseded_by_repair_command(): void
    {
        [$user] = $this->context();
        $schedule = $this->schedule($user, ['schedule_time' => '09:00:00']);
        $oldest = $this->doseLog($schedule, [
            'dose_date' => '2026-06-30',
            'status' => 'pending',
            'next_reminder_at' => CarbonImmutable::parse('2026-06-30 05:00:00', 'UTC'),
        ]);
        $middle = $this->doseLog($schedule, [
            'dose_date' => '2026-06-30',
            'status' => 'snoozed',
            'next_reminder_at' => CarbonImmutable::parse('2026-06-30 05:15:00', 'UTC'),
        ]);
        $latest = $this->doseLog($schedule, [
            'dose_date' => '2026-06-30',
            'status' => 'overdue',
            'next_reminder_at' => CarbonImmutable::parse('2026-06-30 05:30:00', 'UTC'),
        ]);

        $this->artisan('medication:repair-duplicate-dose-logs', ['--pretend-now' => '2026-06-30 10:00'])
            ->expectsOutputToContain('closed=2')
            ->assertExitCode(0);

        $this->assertSame('superseded', $oldest->fresh()->status);
        $this->assertSame('superseded', $middle->fresh()->status);
        $this->assertSame('overdue', $latest->fresh()->status);
        $this->assertNull($oldest->fresh()->next_reminder_at);
        $this->assertNull($middle->fresh()->next_reminder_at);
        $this->assertSame(2, MedicationDoseLog::query()->where('status', 'superseded')->count());
        $this->assertSame(2, $schedule->events()->where('event_type', 'duplicate_dose_log_superseded')->count());
    }

    public function test_slack_taken_is_idempotent_when_clicked_twice(): void
    {
        [$user] = $this->context();
        $schedule = $this->schedule($user, ['schedule_time' => '09:00:00']);
        $log = $this->doseLog($schedule, [
            'dose_date' => '2026-06-30',
            'scheduled_for' => CarbonImmutable::parse('2026-06-30 05:00:00', 'UTC'),
            'status' => 'overdue',
            'reminder_attempts' => 3,
            'next_reminder_at' => CarbonImmutable::parse('2026-06-30 05:30:00', 'UTC'),
        ]);

        $this->postSignedSlackAction('medication_taken', $log->id)->assertOk();
        $this->postSignedSlackAction('medication_taken', $log->id)->assertOk();

        $this->assertSame('taken', $log->fresh()->status);
        $this->assertNull($log->fresh()->next_reminder_at);
        $this->assertSame(3, $log->fresh()->reminder_attempts);
        $this->assertSame(1, $log->events()->where('event_type', 'taken')->count());
        $this->assertSame(2, $log->events()->where('event_type', 'slack_taken_clicked')->count());
    }

    private function context(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'Medication Workspace',
            'slug' => 'medication-workspace-'.$user->id,
            'created_by' => $user->id,
        ]);
        $workspace->users()->attach($user->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
    }

    private function schedule(User $user, array $overrides = []): MedicationDoseSchedule
    {
        $workspaceId = collect($user->accessibleWorkspaceIds())->first();

        return MedicationDoseSchedule::create(array_merge([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'dose_key' => 'morning',
            'label' => 'Morning medications',
            'dosage_text' => 'Xigduo XR 5mg/1000mg; Physiotens 0.2mg; Lodiva 10mg/160mg; Aterpen 10mg/20mg',
            'timing_note' => 'after breakfast',
            'schedule_time' => '08:30:00',
            'timezone' => 'Asia/Dubai',
            'active' => true,
            'repeat_interval_minutes' => 30,
            'quiet_hours_start' => '22:00:00',
            'quiet_hours_end' => '07:00:00',
            'hide_details_in_notifications' => true,
            'default_channel' => 'database',
            'metadata' => [
                'frequency' => 'daily',
                'medication_items' => [
                    ['name' => 'Xigduo XR 5mg/1000mg', 'instruction' => 'Take twice daily', 'timing' => 'Morning after breakfast'],
                    ['name' => 'Physiotens 0.2mg', 'timing' => 'Morning after breakfast'],
                    ['name' => 'Lodiva 10mg/160mg', 'timing' => 'Morning after breakfast'],
                    ['name' => 'Aterpen 10mg/20mg', 'timing' => 'Morning after breakfast'],
                ],
            ],
        ], $overrides));
    }

    private function doseLog(MedicationDoseSchedule $schedule, array $overrides = []): MedicationDoseLog
    {
        return MedicationDoseLog::create(array_merge([
            'dose_schedule_id' => $schedule->id,
            'user_id' => $schedule->user_id,
            'workspace_id' => $schedule->workspace_id,
            'dose_date' => '2026-06-30',
            'scheduled_for' => CarbonImmutable::parse('2026-06-30 05:00:00', 'UTC'),
            'scheduled_timezone' => $schedule->timezone ?: MedicationReminderService::DEFAULT_TIMEZONE,
            'status' => 'pending',
            'reminder_attempts' => 0,
            'next_reminder_at' => CarbonImmutable::parse('2026-06-30 05:00:00', 'UTC'),
        ], $overrides));
    }

    private function enableGoogleCalendar(): void
    {
        config([
            'services.google_calendar.enabled' => true,
            'services.google_calendar.client_id' => 'test-client-id',
            'services.google_calendar.client_secret' => 'test-client-secret',
            'services.google_calendar.redirect_uri' => 'https://friday.paulstechnologies.com/google/calendar/callback',
        ]);
    }

    private function connection(User $user, Workspace $workspace): CalendarConnection
    {
        return CalendarConnection::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'provider' => 'google',
            'provider_account_email' => $user->email,
            'access_token' => 'fixture-access-value',
            'refresh_token' => 'fixture-refresh-value',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['https://www.googleapis.com/auth/calendar.events'],
            'is_active' => true,
        ]);
    }

    private function travelToDubai(string $dateTime): void
    {
        $now = CarbonImmutable::parse($dateTime, 'Asia/Dubai')->utc();
        CarbonImmutable::setTestNow($now);
        Carbon::setTestNow(Carbon::parse($now->toDateTimeString(), 'UTC'));
    }

    private function postSignedSlackAction(string $actionId, int $doseLogId)
    {
        config(['services.slack.signing_secret' => 'test-signing-secret']);

        $body = http_build_query([
            'payload' => json_encode($this->slackActionPayload($actionId, $doseLogId)),
        ]);
        $timestamp = (string) time();
        $signature = 'v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.$body, 'test-signing-secret');

        return $this->call(
            'POST',
            route('slack.medication.actions', absolute: false),
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

    private function slackActionPayload(string $actionId, int $doseLogId): array
    {
        return [
            'type' => 'block_actions',
            'user' => ['id' => 'U123'],
            'actions' => [
                [
                    'action_id' => $actionId,
                    'value' => (string) $doseLogId,
                ],
            ],
        ];
    }
}
