<?php

namespace App\Http\Controllers;

use App\Models\MiriamReminder;
use App\Models\User;
use App\Services\MiriamReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SlackEventsController extends Controller
{
    public function __invoke(Request $request, MiriamReminderService $reminders): JsonResponse
    {
        if ($request->input('type') === 'url_verification') {
            $this->auditSlackEvent($request, ignoredReason: 'url_verification');

            return response()->json(['challenge' => $request->input('challenge')]);
        }

        if (! $this->hasValidSlackSignature($request)) {
            $this->auditSlackEvent($request, ignoredReason: 'invalid_signature');

            return response()->json(['error' => 'Invalid Slack signature.'], 401);
        }

        if ($this->payloadJson($request) !== null) {
            $this->auditSlackEvent($request, parseResult: 'interaction_payload');

            return $this->handleAction($request, $reminders);
        }

        if ($request->header('X-Slack-Retry-Num') !== null) {
            $this->auditSlackEvent($request, ignoredReason: 'retry');

            return response()->json(['ok' => true, 'ignored' => 'retry']);
        }

        $event = $request->input('event', []);
        $eventType = (string) ($event['type'] ?? '');
        $text = trim((string) ($event['text'] ?? ''));
        $channel = (string) ($event['channel'] ?? '');
        $slackUser = (string) ($event['user'] ?? '');

        if (($event['bot_id'] ?? null) || ($event['subtype'] ?? null) === 'bot_message') {
            $this->auditSlackEvent($request, ignoredReason: 'bot_message');

            return response()->json(['ok' => true, 'ignored' => 'bot_message']);
        }

        if (! in_array($eventType, ['message', 'message.groups', 'app_mention'], true)) {
            $this->auditSlackEvent($request, ignoredReason: 'unsupported_event_type');

            return response()->json(['ok' => true, 'ignored' => 'unsupported_event_type']);
        }

        if ($text === '' || $channel === '' || $slackUser === '') {
            $this->auditSlackEvent($request, ignoredReason: 'missing_event_data');

            return response()->json(['ok' => true, 'ignored' => 'missing_event_data']);
        }

        if (! $this->isMiriamChannel($channel)) {
            $this->auditSlackEvent($request, ignoredReason: 'wrong_channel');

            return response()->json(['ok' => true, 'ignored' => 'wrong_channel']);
        }

        $reminder = $reminders->captureFromSlack(
            $text,
            $slackUser,
            $channel,
            isset($event['ts']) ? (string) $event['ts'] : null,
            $this->resolveUser()
        );

        if (! $reminder) {
            $reminders->sendParseHelp($channel);
            $this->auditSlackEvent($request, ignoredReason: 'parse_failed', parseResult: 'failed');

            return response()->json(['ok' => true, 'ignored' => 'not_a_reminder']);
        }

        $reminders->sendConfirmation($reminder);
        $this->auditSlackEvent($request, parseResult: 'reminder_created', reminderId: $reminder->id);

        return response()->json(['ok' => true]);
    }

    private function handleAction(Request $request, MiriamReminderService $reminders): JsonResponse
    {
        $payload = json_decode((string) $this->payloadJson($request), true) ?: [];
        $action = data_get($payload, 'actions.0.action_id');
        $reminderId = (int) data_get($payload, 'actions.0.value');
        $slackUser = (string) data_get($payload, 'user.id', '');

        /** @var MiriamReminder|null $reminder */
        $reminder = MiriamReminder::query()->find($reminderId);

        if (! $reminder) {
            return $this->slackResponse('I could not find that reminder.');
        }

        if ($action === 'miriam_reminder_done') {
            return $this->slackResponse($reminders->handleSlackAction($reminder, $action, $slackUser, $payload));
        }

        if ($action === 'miriam_reminder_snooze_15') {
            return $this->slackResponse($reminders->handleSlackAction($reminder, $action, $slackUser, $payload));
        }

        if ($action === 'miriam_reminder_cancel') {
            return $this->slackResponse($reminders->handleSlackAction($reminder, $action, $slackUser, $payload));
        }

        return $this->slackResponse('Unknown Miriam reminder action.');
    }

    private function slackResponse(string $text): JsonResponse
    {
        return response()->json([
            'response_type' => 'ephemeral',
            'replace_original' => false,
            'text' => $text,
        ]);
    }

    private function hasValidSlackSignature(Request $request): bool
    {
        $secret = config('services.slack.signing_secret');
        $timestamp = (string) $request->header('X-Slack-Request-Timestamp');
        $signature = (string) $request->header('X-Slack-Signature');

        if (! filled($secret) || ! ctype_digit($timestamp) || ! str_starts_with($signature, 'v0=')) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $expected = 'v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.$request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    private function payloadJson(Request $request): ?string
    {
        if ($request->filled('payload')) {
            return (string) $request->input('payload');
        }

        parse_str($request->getContent(), $parsed);

        return isset($parsed['payload']) ? (string) $parsed['payload'] : null;
    }

    private function isMiriamChannel(string $channel): bool
    {
        $configured = config('services.slack.miriam_channel_id') ?: env('SLACK_MIRIAM_CHANNEL_ID');

        return ! filled($configured) || $channel === $configured;
    }

    private function auditSlackEvent(Request $request, ?string $ignoredReason = null, ?string $parseResult = null, ?int $reminderId = null): void
    {
        $event = (array) $request->input('event', []);

        Log::info('Miriam Slack event processed.', array_filter([
            'payload_type' => $request->input('type'),
            'event_type' => $event['type'] ?? null,
            'channel_id' => $event['channel'] ?? null,
            'user_id' => $event['user'] ?? null,
            'text' => $event['text'] ?? null,
            'ignored_reason' => $ignoredReason,
            'parse_result' => $parseResult,
            'reminder_id' => $reminderId,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    private function resolveUser(): ?User
    {
        $configured = env('TASKFLOW_DAILY_USER_ID');

        if ($configured) {
            return User::query()->find($configured);
        }

        return User::query()->orderBy('id')->first();
    }
}
