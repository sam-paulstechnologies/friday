<?php

namespace App\Services\Ai;

class AiAssistantConfig
{
    public function enabled(): bool
    {
        return (bool) config('services.ai_assistant.enabled', false);
    }

    public function provider(): string
    {
        return (string) config('services.ai_assistant.provider', 'mock');
    }

    public function model(): ?string
    {
        return config('services.ai_assistant.model');
    }

    public function localEndpoint(): ?string
    {
        return config('services.ai_assistant.local_endpoint');
    }

    public function apiKeyConfigured(): bool
    {
        return filled(config('services.ai_assistant.api_key'));
    }
}
