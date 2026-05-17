<?php

namespace App\Services\Slack;

use Illuminate\Http\Request;
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
}
