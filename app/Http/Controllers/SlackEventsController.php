<?php

namespace App\Http\Controllers;

use App\Models\MiriamReminder;
use App\Models\User;
use App\Services\Miriam\MiriamSlackConversationRouter;
use App\Services\Miriam\MiriamSlackThoughtCaptureService;
use App\Services\MiriamReminderService;
use App\Services\Slack\SlackEventDeduplicator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SlackEventsController extends Controller
{
    private const ENDPOINT = 'slack.events';

    public function __construct(private readonly SlackEventDeduplicator $deduplicator) {}

    public function __invoke(
        Request $request,
        MiriamReminderService $reminders,
        MiriamSlackConversationRouter $router,
        MiriamSlackThoughtCaptureService $thoughtCapture,
    ): JsonResponse
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

            return $this->handleAction($request, $reminders, $thoughtCapture);
        }

        $eventId = $request->input('event_id');

        // Deduplicate on Slack's own event id rather than dropping every
        // retry: a retry of an event we already handled is a no-op, while a
        // retry of a delivery that genuinely failed still gets processed.
        if (filled($eventId)) {
            if (! $this->deduplicator->claim(self::ENDPOINT, $eventId, (string) $request->input('event.type'))) {
                $this->auditSlackEvent($request, ignoredReason: 'duplicate_event');

                return response()->json(['ok' => true, 'ignored' => 'duplicate_event']);
            }
        } elseif ($request->header('X-Slack-Retry-Num') !== null) {
            // No event id to deduplicate on, so we cannot tell a first delivery
            // from a replay. Drop it rather than risk a duplicate capture.
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

        if (config('services.slack.allowed_user_id') && $slackUser !== config('services.slack.allowed_user_id')) {
            $this->auditSlackEvent($request, ignoredReason: 'wrong_user');

            return response()->json(['ok' => true, 'ignored' => 'wrong_user']);
        }

        if (! $this->isMiriamChannel($channel, (string) ($event['channel_type'] ?? ''))) {
            $this->auditSlackEvent($request, ignoredReason: 'wrong_channel');

            return response()->json(['ok' => true, 'ignored' => 'wrong_channel']);
        }

        $user = $this->resolveUser();
        $conversation = $router->route($text, $slackUser, $channel, $user);

        if (($conversation['handled'] ?? false) && ($conversation['intent'] ?? null) !== 'answer_clarification') {
            if (filled($conversation['text'] ?? null)) {
                $reminders->sendSlackMessage($channel, (string) $conversation['text']);
            }

            $this->auditSlackEvent($request, parseResult: (string) ($conversation['intent'] ?? 'conversation_handled'));

            return response()->json(['ok' => true, 'intent' => $conversation['intent'] ?? null]);
        }

        $capture = $thoughtCapture->capture(
            $text,
            $slackUser,
            $channel,
            isset($event['ts']) ? (string) $event['ts'] : null,
            isset($event['thread_ts']) ? (string) $event['thread_ts'] : null,
            (string) ($request->input('team_id') ?? ($event['team'] ?? '')),
            $user
        );

        if ($capture['status'] === 'failed') {
            $reminders->sendSlackMessage($channel, (string) ($capture['text'] ?? 'I captured the wording, but I could not save it safely.'));
            $this->auditSlackEvent($request, ignoredReason: 'capture_failed', parseResult: 'failed');

            return response()->json(['ok' => true, 'ignored' => 'capture_failed']);
        }

        if (($capture['text'] ?? '') !== '') {
            $reminders->sendSlackMessage($channel, (string) $capture['text'], $capture['blocks'] ?? []);
        }

        $this->auditSlackEvent(
            $request,
            parseResult: (string) ($capture['status'] ?? 'captured'),
            reminderId: $capture['reminder']->id ?? null
        );

        return response()->json([
            'ok' => true,
            'status' => $capture['status'] ?? 'captured',
            'needs_confirmation' => ($capture['status'] ?? null) === 'needs_confirmation',
        ]);
    }

    private function handleAction(Request $request, MiriamReminderService $reminders, MiriamSlackThoughtCaptureService $thoughtCapture): JsonResponse
    {
        $payload = json_decode((string) $this->payloadJson($request), true) ?: [];
        $action = data_get($payload, 'actions.0.action_id');
        $reminderId = (int) data_get($payload, 'actions.0.value');
        $slackUser = (string) data_get($payload, 'user.id', '');
        $channel = (string) data_get($payload, 'channel.id', '');

        /** @var MiriamReminder|null $reminder */
        $reminder = MiriamReminder::query()->find($reminderId);

        if (! $reminder) {
            return $this->slackResponse('I could not find that reminder.');
        }

        if (config('services.slack.allowed_user_id') && $slackUser !== config('services.slack.allowed_user_id')) {
            return $this->slackResponse('You are not allowed to run this Miriam action.');
        }

        if (! $this->isMiriamActionChannel($reminder, $channel, (string) data_get($payload, 'channel.type', ''))) {
            return $this->slackResponse('This Miriam action is not enabled for this Slack channel.');
        }

        if (! $thoughtCapture->canSlackUserAct($reminder, $slackUser)) {
            return $this->slackResponse('You cannot change that Miriam item.');
        }

        if (is_string($action) && str_starts_with($action, 'miriam_capture_')) {
            $result = match ($action) {
                'miriam_capture_confirm' => $thoughtCapture->confirm($reminder, $slackUser),
                'miriam_capture_move_today' => $thoughtCapture->confirm($reminder, $slackUser, moveToToday: true),
                'miriam_capture_cancel' => $thoughtCapture->cancel($reminder, $slackUser),
                'miriam_capture_edit' => $thoughtCapture->edit($reminder, $slackUser),
                default => ['text' => 'Unknown Miriam capture action.'],
            };

            return $this->slackResponse((string) ($result['text'] ?? 'Done.'));
        }

        if (is_string($action) && str_starts_with($action, 'miriam_reminder_')) {
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

    private function isMiriamChannel(string $channel, ?string $channelType = null): bool
    {
        if (in_array($channelType, ['im', 'mpim'], true)) {
            return true;
        }

        // Read through config only: env() returns null once the config is
        // cached, which used to open this gate completely in production.
        $configured = config('services.slack.miriam_channel_id');

        return ! filled($configured) || $channel === $configured;
    }

    private function isMiriamActionChannel(MiriamReminder $reminder, string $channel, ?string $channelType = null): bool
    {
        if (filled($channel) && filled($reminder->slack_channel_id) && $channel === $reminder->slack_channel_id) {
            return true;
        }

        return $this->isMiriamChannel($channel, $channelType);
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
        // Captures are filed against this user, so the Inbox only shows them
        // to the right person. config() keeps that stable under config:cache.
        $configured = config('services.slack.daily_user_id');

        if ($configured) {
            return User::query()->find($configured) ?: User::query()->orderBy('id')->first();
        }

        return User::query()->orderBy('id')->first();
    }
}
