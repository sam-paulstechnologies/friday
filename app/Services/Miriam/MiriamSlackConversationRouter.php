<?php

namespace App\Services\Miriam;

use App\Models\User;
use Illuminate\Support\Str;

class MiriamSlackConversationRouter
{
    public const DEFAULT_TIMEZONE = 'Asia/Dubai';

    public function __construct(
        private readonly MiriamBrainService $brain,
        private readonly MiriamToolExecutor $tools,
    ) {}

    public function route(string $text, string $slackUserId, string $channelId, ?User $user = null): array
    {
        $intent = $this->classify($text);

        if ($intent === 'answer_clarification') {
            return ['handled' => false, 'intent' => $intent];
        }

        if ($intent === 'show_last_result') {
            return $this->showLastResult($slackUserId, $channelId);
        }

        if ($move = $this->moveThatClarification($text, $slackUserId, $channelId, $user)) {
            return $move;
        }

        if ($done = $this->markRecentReminderDone($text, $slackUserId, $channelId, $user)) {
            return $done;
        }

        $selection = $this->brain->selectTool($text, $user);

        if (($selection['intent'] ?? null) === 'approval_required') {
            $this->tools->audit('approval_required', null, ['text' => $text], [
                'message' => $selection['message'] ?? 'Confirmation required.',
            ], $this->toolContext($slackUserId, $channelId, $user, $text, $selection), 'approval_required');

            return [
                'handled' => true,
                'intent' => 'approval_required',
                'text' => $selection['message'] ?? 'I need confirmation before doing that.',
            ];
        }

        $tool = $selection['tool'] ?? null;

        if (in_array($tool, ['create_reminder', 'create_task'], true) && ! Str::contains($this->normalize($text), 'tomorrow morning')) {
            return ['handled' => false, 'intent' => (string) ($selection['intent'] ?? $intent)];
        }

        if ($tool) {
            $result = $this->tools->execute(
                (string) $tool,
                $selection['arguments'] ?? [],
                $this->toolContext($slackUserId, $channelId, $user, $text, $selection),
            );

            $this->tools->storeContext($slackUserId, $channelId, $user, $result);

            return [
                'handled' => true,
                'intent' => (string) ($selection['intent'] ?? $tool),
                'text' => $result['message'] ?? 'Done.',
            ];
        }

        return match ($intent) {
            'ignore' => ['handled' => true, 'intent' => $intent, 'text' => ''],
            'general_question', 'unclear' => [
                'handled' => true,
                'intent' => $intent,
                'text' => 'What would you like me to show or save?',
            ],
            default => ['handled' => false, 'intent' => $intent],
        };
    }

    public function classify(string $text): string
    {
        $normalized = $this->normalize($text);

        if ($normalized === '') {
            return 'ignore';
        }

        if (in_array($normalized, ['am', 'a.m.', 'pm', 'p.m.', 'morning', 'evening', 'night'], true)) {
            return 'answer_clarification';
        }

        if (in_array($normalized, ['show me', 'show', 'details', 'show details'], true)) {
            return 'show_last_result';
        }

        if (Str::contains($normalized, ['what does my tomorrow look like', 'tomorrow look like', 'my tomorrow', 'agenda tomorrow', 'tomorrow agenda'])) {
            return 'calendar_day_query';
        }

        if (Str::contains($normalized, ['what reminders', 'my reminders', 'what is pending', 'what\'s pending', 'pending reminders'])) {
            return 'reminder_list_query';
        }

        if (Str::contains($normalized, ['dose status', 'medication status', 'medicine status', 'evening dose', 'morning dose'])) {
            return 'health_status_query';
        }

        if (preg_match('/\b(remind me|message|call|ping|prepare|follow up|follow-up|create a note|add task|i need to)\b/i', $normalized)) {
            return Str::contains($normalized, ['document', 'prepare', 'add task', 'i need to']) ? 'create_task' : 'create_reminder';
        }

        if (Str::endsWith($normalized, '?') || Str::startsWith($normalized, ['what ', 'how ', 'when ', 'where ', 'why ', 'who '])) {
            return 'general_question';
        }

        return 'unclear';
    }

    private function showLastResult(string $slackUserId, string $channelId): array
    {
        $context = $this->tools->latestContext($slackUserId, $channelId);

        if (! $context) {
            return ['handled' => true, 'intent' => 'show_last_result', 'text' => 'What would you like me to show: tomorrow agenda, reminders, or health status?'];
        }

        return ['handled' => true, 'intent' => 'show_last_result', 'text' => $context->detail ?: $context->summary ?: 'I do not have details to show yet.'];
    }

    private function moveThatClarification(string $text, string $slackUserId, string $channelId, ?User $user): ?array
    {
        if (! preg_match('/\b(move|reschedule)\s+(that|it)\s+to\s+(\d{1,2})(?::\d{2})?\b/i', $this->normalize($text), $matches)) {
            return null;
        }

        $context = $this->tools->latestContext($slackUserId, $channelId, 'reminder');

        if (! $context || ! ($context->payload['reminder_id'] ?? null)) {
            return ['handled' => true, 'intent' => 'unclear', 'text' => 'Which reminder should I move?'];
        }

        if (! preg_match('/\b(am|pm|a\.m\.|p\.m\.)\b/i', $text)) {
            $this->tools->audit('approval_required', 'update_reminder_status', ['text' => $text], [
                'message' => 'Do you mean 10 AM or 10 PM?',
            ], $this->toolContext($slackUserId, $channelId, $user, $text, ['intent' => 'reschedule_reminder']), 'needs_clarification');

            return ['handled' => true, 'intent' => 'unclear', 'text' => 'Do you mean '.$matches[3].' AM or '.$matches[3].' PM?'];
        }

        return ['handled' => true, 'intent' => 'unclear', 'text' => 'I can reschedule reminders after confirmation support is added for time changes.'];
    }

    private function markRecentReminderDone(string $text, string $slackUserId, string $channelId, ?User $user): ?array
    {
        if (! preg_match('/\b(mark|set)\s+(that|it)\s+(done|complete|completed)\b/i', $this->normalize($text))) {
            return null;
        }

        $context = $this->tools->latestContext($slackUserId, $channelId, 'reminder');
        $reminderId = (int) ($context?->payload['reminder_id'] ?? 0);

        if (! $reminderId) {
            return ['handled' => true, 'intent' => 'update_reminder_status', 'text' => 'Which reminder should I mark done?'];
        }

        $result = $this->tools->execute('update_reminder_status', [
            'reminder_id' => $reminderId,
            'status' => 'done',
        ], $this->toolContext($slackUserId, $channelId, $user, $text, [
            'intent' => 'update_reminder_status',
            'confidence' => 1.0,
            'risk_level' => 'low',
        ]));

        $this->tools->storeContext($slackUserId, $channelId, $user, $result);

        return ['handled' => true, 'intent' => 'update_reminder_status', 'text' => $result['message'] ?? 'Done.'];
    }

    private function toolContext(string $slackUserId, string $channelId, ?User $user, string $text, array $selection): array
    {
        return [
            'user' => $user,
            'slack_user_id' => $slackUserId,
            'slack_channel_id' => $channelId,
            'original_text' => $text,
            'confidence' => $selection['confidence'] ?? 1.0,
            'risk_level' => $selection['risk_level'] ?? 'low',
        ];
    }

    private function normalize(string $text): string
    {
        return trim((string) Str::of($text)
            ->lower()
            ->replaceMatches('/<@[a-z0-9]+>/i', '')
            ->replaceMatches('/^@miriam[:,]?\s*/i', '')
            ->replaceMatches('/^miriam[:,]?\s*/i', '')
            ->replaceMatches('/\s+/', ' '));
    }
}
