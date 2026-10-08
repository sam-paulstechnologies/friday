<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GlobalAiSettingsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['security.platform_admin_user_ids' => []]);
        Http::preventStrayRequests();
    }

    public static function actions(): array
    {
        return [['read'], ['update'], ['connection']];
    }

    #[DataProvider('actions')]
    public function test_guest_cannot_access_settings(string $action): void
    {
        $this->requestAction($action)->assertRedirect(route('login'));
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_settings', 0);
    }

    #[DataProvider('actions')]
    public function test_ordinary_user_is_denied_even_with_confirmed_password(string $action): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['auth.password_confirmed_at' => time()]);
        $this->requestAction($action)->assertForbidden();
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_settings', 0);
    }

    public function test_newly_registered_account_does_not_become_platform_admin(): void
    {
        $this->post(route('register'), [
            'name' => 'Synthetic Registrant',
            'email' => 'synthetic@example.test',
            'password' => 'synthetic-password',
            'password_confirmation' => 'synthetic-password',
            'platform_admin' => true,
            'role' => 'admin',
        ])->assertRedirect();
        $this->get(route('settings.ai.edit'))->assertForbidden();
        $this->requestAction('connection')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_workspace_owner_is_not_a_platform_administrator(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Synthetic workspace', 'slug' => 'synthetic-workspace', 'created_by' => $user->id]);
        $workspace->users()->attach($user, ['role' => 'owner', 'joined_at' => now()]);
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
        foreach (self::actions() as [$action]) {
            $this->requestAction($action)->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public static function invalidConfirmations(): array
    {
        return [[0], [-20000], [3600]];
    }

    #[DataProvider('invalidConfirmations')]
    public function test_missing_stale_or_future_confirmation_cannot_save_or_test(int $offset): void
    {
        $user = User::factory()->create();
        config(['security.platform_admin_user_ids' => [$user->id]]);
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => $offset === 0 ? 0 : time() + $offset]);
        foreach ([false, true] as $connection) {
            $this->patchJson(route('settings.ai.update'), $this->payload(['test_connection' => $connection]))->assertStatus(423);
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_settings', 0);
    }

    public function test_recent_admin_can_test_without_persisting_submitted_key(): void
    {
        $this->admin();
        Http::fake(['api.openai.com/*' => Http::response(['data' => []])]);
        $this->requestAction('connection')->assertSessionHas('success')->assertSessionMissing('_old_input.api_key');
        Http::assertSentCount(1);
        $this->assertDatabaseCount('ai_settings', 0);
    }

    public function test_provider_failure_body_is_not_returned_or_logged(): void
    {
        $this->admin();
        Log::spy();
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'synthetic-sensitive-key'], 401)]);
        $response = $this->requestAction('connection');
        $response->assertSessionHas('error', 'OpenAI connection failed. Check the key and try again.')
            ->assertSessionMissing('_old_input.api_key')
            ->assertDontSee('synthetic-sensitive-key');
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_validation_never_flashes_credentials(): void
    {
        $this->admin();
        $this->patch(route('settings.ai.update'), $this->payload(['default_model' => 'invalid']))
            ->assertSessionHasErrors('default_model')->assertSessionMissing('_old_input.api_key');
        Http::assertNothingSent();
    }

    public function test_security_log_contains_only_safe_fields_and_response_hides_key(): void
    {
        $this->admin();
        Log::spy();
        $this->requestAction('update')->assertSessionHas('success')->assertSessionMissing('_old_input.api_key');
        Log::shouldHaveReceived('info')->once()->with('global_ai_settings.updated', \Mockery::on(
            fn (array $context): bool => ! str_contains(json_encode($context), 'synthetic-sensitive-key')
                && $context['credential_replaced'] === true
        ));
        $this->get(route('settings.ai.edit'))->assertOk()->assertDontSee('synthetic-sensitive-key');
        $this->assertArrayNotHasKey('encrypted_api_key', AiSetting::firstOrFail()->toArray());
    }

    private function admin(): void
    {
        $user = User::factory()->create();
        config(['security.platform_admin_user_ids' => [$user->id]]);
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function requestAction(string $action): TestResponse
    {
        return $action === 'read' ? $this->get(route('settings.ai.edit'))
            : $this->patch(route('settings.ai.update'), $this->payload(['test_connection' => $action === 'connection']));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'api_key' => 'synthetic-sensitive-key',
            'default_model' => 'gpt-4o-mini',
            'planner_model' => 'gpt-5.4-mini',
            'max_tasks_sent' => 30,
            'max_output_tokens' => 1200,
            'is_enabled' => true,
        ], $overrides);
    }
}
