<?php

namespace Tests\Feature;

use App\Models\MedicationDoseLog;
use App\Models\MedicationDoseSchedule;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Health\MedicationReminderService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class MedicationReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_daily_morning_and_evening_routine_can_be_configured_without_medication_names(): void
    {
        [$user] = $this->context();

        $this->artisan('miriam:medication-routine:configure', [
            '--user' => $user->email,
            '--breakfast' => '08:30',
            '--dinner' => '20:30',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('medication_dose_schedules', [
            'user_id' => $user->id,
            'dose_key' => 'morning',
            'dosage_text' => '3 tablets',
            'timing_note' => 'after breakfast',
            'schedule_time' => '08:30',
            'timezone' => 'Asia/Dubai',
        ]);
        $this->assertDatabaseHas('medication_dose_schedules', [
            'user_id' => $user->id,
            'dose_key' => 'evening',
            'dosage_text' => '1 tablet',
            'timing_note' => 'after dinner',
            'schedule_time' => '20:30',
            'timezone' => 'Asia/Dubai',
        ]);
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
        $this->assertStringContainsString('scheduled dose is due', $notification->data['message']);
        $this->assertStringNotContainsString('3 tablets', $notification->data['message']);
        $this->assertStringNotContainsString('after breakfast', $notification->data['message']);
        $this->assertDatabaseHas('medication_reminder_events', [
            'dose_log_id' => $log->id,
            'event_type' => 'reminder_sent',
            'channel' => 'test-database',
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

    public function test_skip_accepts_optional_reason_and_stops_future_reminders(): void
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

    public function test_quiet_hours_suppress_delivery_but_flag_overdue(): void
    {
        [$user] = $this->context();
        $this->schedule($user, ['schedule_time' => '23:00:00']);
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
                ->where('medicationDoseStatus.items.0.label', 'Morning dose')
                ->where('medicationDoseStatus.items.0.status', 'overdue')
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
            'label' => 'Morning dose',
            'dosage_text' => '3 tablets',
            'timing_note' => 'after breakfast',
            'schedule_time' => '08:30:00',
            'timezone' => 'Asia/Dubai',
            'active' => true,
            'repeat_interval_minutes' => 30,
            'quiet_hours_start' => '22:00:00',
            'quiet_hours_end' => '07:00:00',
            'hide_details_in_notifications' => true,
            'default_channel' => 'database',
        ], $overrides));
    }

    private function travelToDubai(string $dateTime): void
    {
        $now = CarbonImmutable::parse($dateTime, 'Asia/Dubai')->utc();
        CarbonImmutable::setTestNow($now);
        Carbon::setTestNow(Carbon::parse($now->toDateTimeString(), 'UTC'));
    }
}
