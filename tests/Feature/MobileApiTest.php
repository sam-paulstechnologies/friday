<?php

namespace Tests\Feature;

use App\Models\CalendarEventMapping;
use App\Models\MedicationDoseLog;
use App\Models\MedicationDoseSchedule;
use App\Models\MiriamDevelopmentLedger;
use App\Models\MiriamReminder;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-26 09:00:00', 'Asia/Dubai'));
        Carbon::setTestNow(Carbon::parse('2026-06-26 05:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_mobile_login_me_and_logout_use_secure_bearer_token(): void
    {
        $user = User::factory()->create(['email' => 'sam@example.test']);

        $login = $this->postJson('/api/mobile/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'Sam Android',
        ])
            ->assertOk()
            ->assertJsonPath('user.email', 'sam@example.test')
            ->json();

        $token = $login['token'];

        $this->assertDatabaseHas('miriam_mobile_tokens', [
            'user_id' => $user->id,
            'name' => 'Sam Android',
            'abilities' => json_encode(['mobile']),
        ]);
        $this->assertDatabaseMissing('miriam_mobile_tokens', ['token_hash' => $token]);

        $this->withToken($token)
            ->getJson('/api/mobile/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'sam@example.test')
            ->assertJsonStructure(['dashboard' => ['today_summary', 'next_reminders', 'medication', 'shortcuts']]);

        $this->withToken($token)->postJson('/api/mobile/logout')->assertOk();
        $this->withToken($token)->getJson('/api/mobile/me')->assertUnauthorized();
    }

    public function test_mobile_routes_require_authentication(): void
    {
        $this->getJson('/api/mobile/reminders')->assertUnauthorized();
        $this->postJson('/api/mobile/miriam/chat', ['message' => 'what is pending'])->assertUnauthorized();
    }

    public function test_mobile_chat_uses_backend_miriam_tools_to_create_reminder(): void
    {
        $user = User::factory()->create();
        $token = $this->mobileToken($user);

        $this->withToken($token)
            ->postJson('/api/mobile/miriam/chat', [
                'message' => 'remind me tomorrow morning to call Jasion',
            ])
            ->assertOk()
            ->assertJsonPath('action', 'create_reminder');

        $reminder = MiriamReminder::firstOrFail();

        $this->assertSame($user->id, $reminder->user_id);
        $this->assertSame('Call Jasion', $reminder->title);
        $this->assertSame('2026-06-27 05:00:00', $reminder->due_at->format('Y-m-d H:i:s'));
    }

    public function test_mobile_reminders_are_user_scoped_and_can_be_done_snoozed_or_cancelled(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $token = $this->mobileToken($user);
        $reminder = MiriamReminder::create([
            'user_id' => $user->id,
            'category' => 'personal',
            'title' => 'Call Sunny',
            'timezone' => 'Asia/Dubai',
            'due_at' => CarbonImmutable::parse('2026-06-26 12:00', 'Asia/Dubai')->utc(),
            'status' => 'pending',
            'next_reminder_at' => CarbonImmutable::parse('2026-06-26 12:00', 'Asia/Dubai')->utc(),
        ]);
        MiriamReminder::create([
            'user_id' => $other->id,
            'category' => 'personal',
            'title' => 'Other user reminder',
            'timezone' => 'Asia/Dubai',
            'due_at' => CarbonImmutable::parse('2026-06-26 12:00', 'Asia/Dubai')->utc(),
            'status' => 'pending',
        ]);

        $this->withToken($token)
            ->getJson('/api/mobile/reminders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Call Sunny');

        $this->withToken($token)
            ->postJson("/api/mobile/reminders/{$reminder->id}/snooze")
            ->assertOk()
            ->assertJsonPath('data.status', 'snoozed');

        $this->withToken($token)
            ->postJson("/api/mobile/reminders/{$reminder->id}/done")
            ->assertOk()
            ->assertJsonPath('data.status', 'done');

        $this->assertNull($reminder->fresh()->next_reminder_at);
    }

    public function test_mobile_medication_status_and_actions_use_existing_backend_logic(): void
    {
        $user = User::factory()->create();
        $token = $this->mobileToken($user);
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
            'metadata' => ['frequency' => 'daily', 'medication_items' => [['name' => 'Xigduo XR 5mg/1000mg']]],
        ]);
        $log = MedicationDoseLog::create([
            'dose_schedule_id' => $schedule->id,
            'user_id' => $user->id,
            'dose_date' => '2026-06-26',
            'scheduled_for' => CarbonImmutable::parse('2026-06-26 09:00', 'Asia/Dubai')->utc(),
            'scheduled_timezone' => 'Asia/Dubai',
            'status' => 'pending',
            'next_reminder_at' => CarbonImmutable::parse('2026-06-26 09:00', 'Asia/Dubai')->utc(),
        ]);

        $this->withToken($token)
            ->getJson('/api/mobile/medication/status')
            ->assertOk()
            ->assertJsonPath('routine.0.label', 'Morning medications')
            ->assertJsonPath('today.0.status', 'pending');

        $this->withToken($token)
            ->postJson("/api/mobile/medication/{$log->id}/snooze", ['minutes' => 15])
            ->assertOk()
            ->assertJsonPath('data.status', 'snoozed');

        $this->withToken($token)
            ->postJson("/api/mobile/medication/{$log->id}/taken")
            ->assertOk()
            ->assertJsonPath('data.status', 'taken');
    }

    public function test_mobile_agenda_includes_calendar_and_reminders_without_miriam_calendar_duplicates(): void
    {
        $user = User::factory()->create();
        $token = $this->mobileToken($user);
        CalendarEventMapping::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_event_id' => 'external-1',
            'provider_calendar_id' => 'primary',
            'last_synced_at' => now(),
            'metadata' => ['title' => 'External meeting', 'date' => '2026-06-27', 'time' => '10:00 AM'],
        ]);
        CalendarEventMapping::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_event_id' => 'miriam-duplicate',
            'provider_calendar_id' => 'primary',
            'last_synced_at' => now(),
            'metadata' => ['title' => 'Reminder: Call Sunny', 'date' => '2026-06-27', 'source' => 'miriam_general_reminder'],
        ]);
        MiriamReminder::create([
            'user_id' => $user->id,
            'category' => 'personal',
            'title' => 'Call Sunny',
            'timezone' => 'Asia/Dubai',
            'due_at' => CarbonImmutable::parse('2026-06-27 09:00', 'Asia/Dubai')->utc(),
            'status' => 'pending',
        ]);

        $this->withToken($token)
            ->getJson('/api/mobile/agenda/tomorrow')
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonFragment(['title' => 'External meeting'])
            ->assertJsonFragment(['title' => 'Call Sunny']);
    }

    public function test_mobile_development_status_returns_jobs_ledgers_and_attention(): void
    {
        $user = User::factory()->create();
        $token = $this->mobileToken($user);
        MiriamDevelopmentLedger::create([
            'app_name' => 'Miriam',
            'development_name' => 'Mobile API',
            'status' => 'completed',
            'summary' => 'Mobile API completed.',
            'commit_hash' => 'abc123',
            'completed_at' => now(),
        ]);

        $this->withToken($token)
            ->getJson('/api/mobile/development/status')
            ->assertOk()
            ->assertJsonPath('recent.0.development_name', 'Mobile API')
            ->assertJsonPath('recent.0.commit', 'abc123');
    }

    private function mobileToken(User $user): string
    {
        return $this->postJson('/api/mobile/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('token');
    }
}
