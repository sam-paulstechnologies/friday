<?php

namespace App\Services\Slack;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SlackService
{
    public function sendMessage(string $channel, string $text, array $blocks = []): array
    {
        $token = config('services.slack.bot_token');

        if (! $token || ! $channel) {
            Log::warning('Slack message skipped because token or channel is missing.');

            return ['ok' => false, 'error' => 'missing_slack_configuration'];
        }

        $payload = [
            'channel' => $channel,
            'text' => $text,
        ];

        if ($blocks !== []) {
            $payload['blocks'] = $blocks;
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->post('https://slack.com/api/chat.postMessage', $payload);

            $data = $response->json() ?? [];

            if (! ($data['ok'] ?? false)) {
                Log::warning('Slack chat.postMessage failed.', ['response' => $data]);
            }

            return $data;
        } catch (\Throwable $exception) {
            Log::error('Slack chat.postMessage exception.', ['message' => $exception->getMessage()]);

            return ['ok' => false, 'error' => $exception->getMessage()];
        }
    }

    public function sendImage(string $channel, string $caption, string $path, string $filename, string $title): array
    {
        $token = config('services.slack.bot_token');

        if (! $token || ! $channel) {
            Log::warning('Slack image skipped because token or channel is missing.');

            return ['ok' => false, 'error' => 'missing_slack_configuration'];
        }

        if (! File::exists($path)) {
            return ['ok' => false, 'error' => 'image_file_missing'];
        }

        try {
            $uploadUrlResponse = Http::withToken($token)
                ->asForm()
                ->post('https://slack.com/api/files.getUploadURLExternal', [
                    'filename' => $filename,
                    'length' => File::size($path),
                ]);

            $uploadUrlData = $uploadUrlResponse->json() ?? [];

            if (! ($uploadUrlData['ok'] ?? false) || blank($uploadUrlData['upload_url'] ?? null) || blank($uploadUrlData['file_id'] ?? null)) {
                Log::warning('Slack files.getUploadURLExternal failed.', ['response' => $uploadUrlData]);

                return $uploadUrlData + ['ok' => false];
            }

            $handle = fopen($path, 'rb');
            $uploadResponse = Http::attach('file', $handle, $filename)
                ->post($uploadUrlData['upload_url']);

            if (is_resource($handle)) {
                fclose($handle);
            }

            if (! $uploadResponse->successful()) {
                Log::warning('Slack external image upload failed.', ['status' => $uploadResponse->status()]);

                return ['ok' => false, 'error' => 'slack_upload_failed'];
            }

            $completeResponse = Http::withToken($token)
                ->acceptJson()
                ->post('https://slack.com/api/files.completeUploadExternal', [
                    'channel_id' => $channel,
                    'initial_comment' => $caption,
                    'files' => [
                        [
                            'id' => $uploadUrlData['file_id'],
                            'title' => $title,
                        ],
                    ],
                ]);

            $data = $completeResponse->json() ?? [];

            if (! ($data['ok'] ?? false)) {
                Log::warning('Slack files.completeUploadExternal failed.', ['response' => $data]);
            }

            return $data;
        } catch (\Throwable $exception) {
            Log::error('Slack image upload exception.', ['message' => $exception->getMessage()]);

            return ['ok' => false, 'error' => $exception->getMessage()];
        }
    }

    public function verifySignature(Request $request): bool
    {
        $secret = config('services.slack.signing_secret');

        if (! $secret) {
            Log::warning('Slack signing secret is not configured.');

            return false;
        }

        $timestamp = $request->header('X-Slack-Request-Timestamp');
        $signature = $request->header('X-Slack-Signature');

        if (! $timestamp || ! $signature || abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $base = "v0:{$timestamp}:".$request->getContent();
        $expected = 'v0='.hash_hmac('sha256', $base, $secret);

        return hash_equals($expected, $signature);
    }

    public function downloadFile(string $url): ?string
    {
        $token = config('services.slack.bot_token');

        if (! $token || ! $url) {
            return null;
        }

        try {
            $response = Http::withToken($token)->get($url);

            if (! $response->successful()) {
                Log::warning('Slack file download failed.', ['status' => $response->status()]);

                return null;
            }

            $directory = storage_path('app/slack-audio');
            File::ensureDirectoryExists($directory);

            $extension = pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'bin';
            $path = $directory.'/voice-'.uniqid().'.'.$extension;
            File::put($path, $response->body());

            return $path;
        } catch (\Throwable $exception) {
            Log::error('Slack file download exception.', ['message' => $exception->getMessage()]);

            return null;
        }
    }
}
