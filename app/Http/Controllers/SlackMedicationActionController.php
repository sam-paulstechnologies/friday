<?php

namespace App\Http\Controllers;

use App\Models\MedicationDoseLog;
use App\Services\Health\MedicationReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class SlackMedicationActionController extends Controller
{
    public function __invoke(Request $request, MedicationReminderService $reminders): JsonResponse
    {
        if (! $this->hasValidSlackSignature($request)) {
            return response()->json(['error' => 'Invalid Slack signature.'], 401);
        }

        $payload = json_decode((string) $this->payloadJson($request), true);
        $action = data_get($payload, 'actions.0.action_id');
        $doseLogId = data_get($payload, 'actions.0.value');
        $slackUser = data_get($payload, 'user.id');

        /** @var MedicationDoseLog|null $log */
        $log = MedicationDoseLog::query()->with('schedule')->find($doseLogId);

        if (! $log) {
            return $this->slackResponse('I could not find that medication reminder. Open Miriam Health to review current status.');
        }

        try {
            return match ($action) {
                'medication_taken' => $this->markTaken($log, $reminders, $slackUser),
                'medication_snooze_15' => $this->snooze($log, $reminders, $slackUser),
                'medication_skip' => $this->skip($log, $reminders, $slackUser),
                default => $this->fail($log, $reminders, 'Unknown medication action.', $action, $slackUser),
            };
        } catch (Throwable $exception) {
            $reminders->recordSlackButtonClick($log, 'slack_action_failed', [
                'action_id' => $action,
                'slack_user_id' => $slackUser,
                'exception' => class_basename($exception),
            ]);

            return $this->slackResponse('I could not update that reminder. Open Miriam Health to review current status.');
        }
    }

    private function markTaken(MedicationDoseLog $log, MedicationReminderService $reminders, ?string $slackUser): JsonResponse
    {
        $reminders->recordSlackButtonClick($log, 'slack_taken_clicked', [
            'slack_user_id' => $slackUser,
            'already_acknowledged' => in_array($log->status, MedicationReminderService::ACKNOWLEDGED_STATUSES, true),
        ]);

        if (! in_array($log->status, MedicationReminderService::ACKNOWLEDGED_STATUSES, true)) {
            $reminders->markTaken($log, 'slack', 'slack');
        }

        return $this->slackResponse('Confirmed. Medication marked as taken.');
    }

    private function snooze(MedicationDoseLog $log, MedicationReminderService $reminders, ?string $slackUser): JsonResponse
    {
        $reminders->recordSlackButtonClick($log, 'slack_snooze_clicked', [
            'slack_user_id' => $slackUser,
            'minutes' => 15,
            'already_acknowledged' => in_array($log->status, MedicationReminderService::ACKNOWLEDGED_STATUSES, true),
        ]);

        if (! in_array($log->status, MedicationReminderService::ACKNOWLEDGED_STATUSES, true)) {
            $reminders->snooze($log, 15, 'slack', 'slack');
        }

        return $this->slackResponse('Snoozed for 15 minutes.');
    }

    private function skip(MedicationDoseLog $log, MedicationReminderService $reminders, ?string $slackUser): JsonResponse
    {
        $reminders->recordSlackButtonClick($log, 'slack_skip_clicked', [
            'slack_user_id' => $slackUser,
            'requires_reason' => true,
        ]);

        return $this->slackResponse('Open Miriam to enter a skip reason: '.route('health.index'));
    }

    private function fail(MedicationDoseLog $log, MedicationReminderService $reminders, string $message, ?string $action, ?string $slackUser): JsonResponse
    {
        $reminders->recordSlackButtonClick($log, 'slack_action_failed', [
            'action_id' => $action,
            'slack_user_id' => $slackUser,
        ]);

        return $this->slackResponse($message);
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

        $base = 'v0:'.$timestamp.':'.$request->getContent();
        $expected = 'v0='.hash_hmac('sha256', $base, $secret);

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
}
