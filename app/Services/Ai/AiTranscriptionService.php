<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class AiTranscriptionService
{
    public function __construct(private readonly AiSettingsService $settings)
    {
    }

    public function transcribe(string $path): ?string
    {
        if (! $this->settings->isEnabled() || ! $this->settings->apiKey() || ! File::exists($path)) {
            return null;
        }

        try {
            $handle = fopen($path, 'rb');
            $response = Http::withToken((string) $this->settings->apiKey())
                ->attach('file', $handle, basename($path))
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => 'whisper-1',
                ]);

            if (is_resource($handle)) {
                fclose($handle);
            }

            if (! $response->successful()) {
                return null;
            }

            $text = trim((string) ($response->json('text') ?? ''));

            return $text !== '' ? $text : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
