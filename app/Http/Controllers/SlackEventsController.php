<?php

namespace App\Http\Controllers;

use App\Models\MiriamReminder;
use App\Models\User;
use App\Services\MiriamReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SlackEventsController extends Controller
{
    public function __invoke(Request $request, MiriamReminderService $reminders): JsonResponse
    {
        if ($request->input('type') === 'url_verification') {
            return response()->json(['challenge' => $request->input('challenge')]);
        }

        if (! $this->hasValidSlackSignature($request)) {
            return response()->json(['error' => 'Invalid Slack signature.'], 401);
        }

        if ($this->payloadJson($request) !== null) {
            return $this->handleAction($request, $reminders);
        }

        if ($request->header('X-Slack-Retry-Num') !== null) {
            return response()->json(['ok' => true, 'ignored' => 'retry']);
        }

        $event = $request->input('event', []);

        if (($event['bot_id'] ?? null) || ($event['subtype'] ?? null) === 'bot_message') {
            return response()->json(['ok' => true, 'ignored' => 'bot_message']);
        }

        $text = trim((string) ($event['text'] ?? ''));
        $channel = (string) ($event['channel'] ?? '');
        $slackUser = (string) ($event['user'] ?? '');

        if ($text === '' || $channel === '' || $slackUser === '') {
            return response()->json(['ok' => true, 'ignored' => 'missing_event_data']);
        }

        $reminder = $reminders->captureFromSlack(
            $text,
            $slackUser,
            $channel,
            isset($event['ts']) ? (string) $event['ts'] : null,
            $this->resolveUser()
        );

        if (! $reminder) {
            return response()->json(['ok' => true, 'ignored' => 'not_a_reminder']);
        }

        $reminders->sendConfirmation($reminder);

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
            $reminders->markDone($reminder, $slackUser);

            return $this->slackResponse('Done. I marked that reminder complete.');
        }

        if ($action === 'miriam_reminder_snooze_15') {
            $reminders->snooze($reminder, $slackUser, 15);

            return $this->slackResponse('Snoozed for 15 minutes.');
        }

        if ($action === 'miriam_reminder_cancel') {
            $reminders->cancel($reminder, $slackUser);

            return $this->slackResponse('Cancelled.');
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

    private function resolveUser(): ?User
    {
        $configured = env('TASKFLOW_DAILY_USER_ID');

        if ($configured) {
            return User::query()->find($configured);
        }

        return User::query()->orderBy('id')->first();
    }
}
