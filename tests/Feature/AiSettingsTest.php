<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class AiSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_settings_page_loads(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('settings.ai.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Ai/Edit')
                ->where('settings.default_model', 'gpt-4o-mini')
                ->where('settings.planner_model', 'gpt-5.4-mini')
                ->where('settings.max_tasks_sent', 30)
                ->where('settings.max_output_tokens', 1200)
                ->where('settings.is_enabled', false)
            );
    }

    public function test_api_key_is_encrypted_at_rest(): void
    {
        $user = User::factory()->create();
        $apiKey = 'sk-test-secretabcd';

        $this->actingAs($user)->patch(route('settings.ai.update'), $this->payload([
            'api_key' => $apiKey,
            'is_enabled' => true,
        ]))->assertRedirect();

        $setting = AiSetting::firstOrFail();

        $this->assertNotSame($apiKey, $setting->encrypted_api_key);
        $this->assertSame($apiKey, Crypt::decryptString($setting->encrypted_api_key));
        $this->assertTrue($setting->is_enabled);
    }

    public function test_api_key_is_masked_when_returned(): void
    {
        $user = User::factory()->create();
        $apiKey = 'sk-test-secretabcd';
        $setting = new AiSetting(['provider' => AiSetting::PROVIDER_OPENAI]);
        $setting->setApiKey($apiKey);
        $setting->fill([
            'default_model' => 'gpt-4o-mini',
            'planner_model' => 'gpt-5.4-mini',
            'max_tasks_sent' => 30,
            'max_output_tokens' => 1200,
            'is_enabled' => true,
        ])->save();

        $this->actingAs($user)
            ->get(route('settings.ai.edit'))
            ->assertOk()
            ->assertDontSee($apiKey)
            ->assertInertia(fn ($page) => $page
                ->where('settings.api_key_mask', 'sk-...abcd')
                ->where('settings.has_api_key', true)
            );
    }

    public function test_updating_models_works(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('settings.ai.update'), $this->payload([
            'api_key' => 'sk-test-secretabcd',
            'default_model' => 'gpt-5.4-nano',
            'planner_model' => 'gpt-5.4',
            'max_tasks_sent' => 45,
            'max_output_tokens' => 1800,
            'is_enabled' => true,
        ]))->assertRedirect();

        $this->assertDatabaseHas('ai_settings', [
            'provider' => AiSetting::PROVIDER_OPENAI,
            'default_model' => 'gpt-5.4-nano',
            'planner_model' => 'gpt-5.4',
            'max_tasks_sent' => 45,
            'max_output_tokens' => 1800,
            'is_enabled' => true,
        ]);
    }

    public function test_env_fallback_works_when_db_settings_are_missing(): void
    {
        config([
            'services.openai.api_key' => 'sk-env-secret',
            'services.openai.model_default' => 'gpt-5.4-nano',
            'services.openai.model_planner' => 'gpt-5.4',
        ]);

        $service = app(AiSettingsService::class);

        $this->assertSame('sk-env-secret', $service->apiKey());
        $this->assertSame('gpt-5.4-nano', $service->defaultModel());
        $this->assertSame('gpt-5.4', $service->plannerModel());
    }

    public function test_disabled_setting_prevents_ai_usage(): void
    {
        $setting = new AiSetting(['provider' => AiSetting::PROVIDER_OPENAI]);
        $setting->setApiKey('sk-test-secretabcd');
        $setting->fill([
            'default_model' => 'gpt-4o-mini',
            'planner_model' => 'gpt-5.4-mini',
            'max_tasks_sent' => 30,
            'max_output_tokens' => 1200,
            'is_enabled' => false,
        ])->save();

        $this->assertFalse(app(AiSettingsService::class)->isEnabled());
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'api_key' => null,
            'default_model' => 'gpt-4o-mini',
            'planner_model' => 'gpt-5.4-mini',
            'max_tasks_sent' => 30,
            'max_output_tokens' => 1200,
            'is_enabled' => false,
        ], $overrides);
    }
}
