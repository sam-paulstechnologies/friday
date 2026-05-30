<?php

namespace App\Services\Ai;

use App\Models\AiSetting;

class AiSettingsService
{
    public function isEnabled(): bool
    {
        $setting = $this->setting();

        if (! $setting) {
            return false;
        }

        return $setting->is_enabled && filled($this->apiKey());
    }

    public function apiKey(): ?string
    {
        $setting = $this->setting();

        return $setting?->apiKey() ?: config('services.openai.api_key');
    }

    public function defaultModel(): string
    {
        return $this->setting()?->default_model ?: config('services.openai.model_default', 'gpt-4o-mini');
    }

    public function plannerModel(): string
    {
        return $this->setting()?->planner_model ?: config('services.openai.model_planner', 'gpt-5.4-mini');
    }

    public function maxTasksSent(): int
    {
        return $this->setting()?->max_tasks_sent ?: 30;
    }

    public function maxOutputTokens(): int
    {
        return $this->setting()?->max_output_tokens ?: 1200;
    }

    private function setting(): ?AiSetting
    {
        return AiSetting::query()
            ->where('provider', AiSetting::PROVIDER_OPENAI)
            ->whereNull('workspace_id')
            ->first();
    }
}
