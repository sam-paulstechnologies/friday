<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AiSetting;
use App\Services\Ai\AiSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AiSettingsController extends Controller
{
    public function edit(AiSettingsService $settings): Response
    {
        $aiSetting = $this->setting();

        return Inertia::render('Settings/Ai/Edit', [
            'settings' => [
                'api_key_mask' => $aiSetting?->maskedApiKey(),
                'has_api_key' => filled($aiSetting?->encrypted_api_key),
                'default_model' => $settings->defaultModel(),
                'planner_model' => $settings->plannerModel(),
                'max_tasks_sent' => $settings->maxTasksSent(),
                'max_output_tokens' => $settings->maxOutputTokens(),
                'is_enabled' => $aiSetting?->is_enabled ?? false,
            ],
            'modelOptions' => [
                ['value' => 'gpt-4o-mini', 'label' => 'gpt-4o-mini - Cheap daily Slack chat'],
                ['value' => 'gpt-5.4-mini', 'label' => 'gpt-5.4-mini - Priority review / planning'],
                ['value' => 'gpt-5.4-nano', 'label' => 'gpt-5.4-nano - Lowest cost simple tasks'],
                ['value' => 'gpt-5.4', 'label' => 'gpt-5.4 - Deep planning only'],
            ],
        ]);
    }

    public function update(Request $request, AiSettingsService $settings): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'api_key' => ['nullable', 'string', 'max:500'],
            'default_model' => ['required', Rule::in(AiSetting::MODELS)],
            'planner_model' => ['required', Rule::in(AiSetting::MODELS)],
            'max_tasks_sent' => ['required', 'integer', 'min:1', 'max:200'],
            'max_output_tokens' => ['required', 'integer', 'min:100', 'max:20000'],
            'is_enabled' => ['required', 'boolean'],
            'test_connection' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return back(303)->withErrors($validator)->withInput($request->except('api_key'));
        }

        $data = $validator->validated();

        if ($request->boolean('test_connection')) {
            return $this->testConnection($data['api_key'] ?? null, $settings);
        }

        $aiSetting = $this->setting() ?? new AiSetting([
            'provider' => AiSetting::PROVIDER_OPENAI,
        ]);

        $apiKey = trim((string) ($data['api_key'] ?? ''));

        if (filled($apiKey)) {
            $aiSetting->setApiKey($apiKey);
        }

        $hasSavedKey = filled($aiSetting->encrypted_api_key);

        $aiSetting->fill([
            'default_model' => $data['default_model'],
            'planner_model' => $data['planner_model'],
            'max_tasks_sent' => $data['max_tasks_sent'],
            'max_output_tokens' => $data['max_output_tokens'],
            'is_enabled' => $hasSavedKey && (bool) $data['is_enabled'],
        ]);

        $aiSetting->save();

        return back(303)->with('success', 'AI Brain settings saved.')->withInput([]);
    }

    private function testConnection(?string $submittedApiKey, AiSettingsService $settings): RedirectResponse
    {
        $apiKey = trim((string) $submittedApiKey) ?: $settings->apiKey();

        if (blank($apiKey)) {
            return back(303)->with('error', 'Add an OpenAI API key before testing the connection.')->withInput([]);
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(8)
                ->get('https://api.openai.com/v1/models');
        } catch (\Throwable) {
            return back(303)->with('error', 'OpenAI connection failed. Check the key and try again.')->withInput([]);
        }

        if (! $response->successful()) {
            return back(303)->with('error', 'OpenAI connection failed. Check the key and try again.')->withInput([]);
        }

        return back(303)->with('success', 'OpenAI connection succeeded.')->withInput([]);
    }

    private function setting(): ?AiSetting
    {
        return AiSetting::query()
            ->where('provider', AiSetting::PROVIDER_OPENAI)
            ->whereNull('workspace_id')
            ->first();
    }
}
