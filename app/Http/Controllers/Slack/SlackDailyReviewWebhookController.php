<?php

namespace App\Http\Controllers\Slack;

use App\Http\Controllers\Controller;
use App\Models\DailyReview;
use App\Models\DailyReviewItem;
use App\Models\Task;
use App\Models\User;
use App\Services\Ai\AiBrainService;
use App\Services\Ai\AiRecommendationService;
use App\Services\Ai\AiTranscriptionService;
use App\Services\Slack\SlackCommandParser;
use App\Services\Slack\SlackEventDeduplicator;
use App\Services\Slack\SlackService;
use App\Services\Tasks\TaskTransitionService;
use App\Support\OperationalClock;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `POST /webhooks/slack/events` — the Slack daily-review endpoint.
 *
 * This replaces the previous handler on this route, which could not be
 * constructed at all: it type-hinted nine Development-Manager classes that do
 * not exist in the repository, so every single request returned HTTP 500.
 *
 * What survives here is everything that had working dependencies and belongs
 * to the daily loop: signature and replay checks, event-id idempotency, the
 * `done / move / note / block / waiting / skip` review commands, the AI
 * recommendation approvals, voice notes and AI Brain questions.
 *
 * The Development Manager vocabulary (`/miriam ...`, dev/runner/release
 * commands, interaction payloads) is answered honestly as unavailable rather
 * than pretending the action happened. Restoring that module is out of scope
 * for this phase.
 */
class SlackDailyReviewWebhookController extends Controller
{
    private const ENDPOINT = 'webhooks.slack.events';

    public function __invoke(
        Request $request,
        SlackService $slackService,
        SlackCommandParser $parser,
        AiBrainService $aiBrain,
        AiRecommendationService $recommendations,
        AiTranscriptionService $transcription,
        SlackEventDeduplicator $deduplicator,
        TaskTransitionService $transitions,
        OperationalClock $clock,
    ): JsonResponse {
        // Signature verification also enforces the 300-second window, so an
        // expired or replayed-by-timestamp request never reaches any handler.
        if (! $slackService->verifySignature($request)) {
            Log::warning('Slack webhook rejected: signature verification failed.', [
                'endpoint' => self::ENDPOINT,
                'has_timestamp' => filled($request->header('X-Slack-Request-Timestamp')),
            ]);

            return response()->json(['ok' => false, 'error' => 'invalid_signature'], 403);
        }

        if ($request->input('type') === 'url_verification') {
            return response()->json(['challenge' => $request->input('challenge')]);
        }

        $eventId = $request->input('event_id');
        $event = (array) $request->input('event', []);
        $eventType = $event['type'] ?? $request->input('type');

        if (filled($eventId)) {
            if (! $deduplicator->claim(self::ENDPOINT, $eventId, is_string($eventType) ? $eventType : null)) {
                return response()->json(['ok' => true, 'ignored' => 'duplicate_event']);
            }
        } elseif ($request->header('X-Slack-Retry-Num') !== null && $this->interactionPayload($request) === null) {
            // Nothing to deduplicate on, so a replay is indistinguishable from
            // a first delivery. Drop it rather than act twice.
            return response()->json(['ok' => true, 'ignored' => 'retry']);
        }

        try {
            $response = $this->handle($request, $event, $slackService, $parser, $aiBrain, $recommendations, $transcription, $transitions, $clock);
        } catch (Throwable $exception) {
            // Release the claim so Slack's retry can try again, and never leak
            // tokens or request bodies into the log.
            $deduplicator->release(self::ENDPOINT, $eventId);

            Log::error('Slack webhook handling failed.', [
                'endpoint' => self::ENDPOINT,
                'event_type' => is_string($eventType) ? $eventType : null,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            // Acknowledge without claiming anything happened.
            return response()->json(['ok' => false, 'error' => 'internal_error']);
        }

        $deduplicator->complete(self::ENDPOINT, $eventId, (string) ($response['ok'] ?? false ? 'handled' : 'ignored'));

        return response()->json($response);
    }

    private function handle(
        Request $request,
        array $event,
        SlackService $slackService,
        SlackCommandParser $parser,
        AiBrainService $aiBrain,
        AiRecommendationService $recommendations,
        AiTranscriptionService $transcription,
        TaskTransitionService $transitions,
        OperationalClock $clock,
    ): array {
        if ($this->isSlashCommand($request) || $this->interactionPayload($request) !== null) {
            return $this->unavailableCapability($request, $slackService);
        }

        $channel = $event['channel'] ?? null;
        $slackUser = $event['user'] ?? null;
        $text = trim((string) ($event['text'] ?? ''));

        if (($event['bot_id'] ?? null) || ($event['subtype'] ?? null) === 'bot_message') {
            return ['ok' => true, 'ignored' => 'bot_message'];
        }

        if (config('services.slack.default_channel') && $channel !== config('services.slack.default_channel')) {
            Log::warning('Slack event ignored from unconfigured channel.', ['channel' => $channel]);

            return ['ok' => true, 'ignored' => 'unconfigured_channel'];
        }

        if (config('services.slack.allowed_user_id') && $slackUser !== config('services.slack.allowed_user_id')) {
            Log::warning('Slack event ignored from unconfigured user.', ['user' => $slackUser]);

            return ['ok' => true, 'ignored' => 'unconfigured_user'];
        }

        $appUser = $this->resolveUser();

        if ($parser->parseMiriamPromptCommand($text) || $parser->parseMiriamNaturalLanguage($text)) {
            return $this->unavailableCapability($request, $slackService, (string) $channel);
        }

        if ($approval = $this->parseAiApproval($text)) {
            $message = match ($approval['action']) {
                'show' => $recommendations->formatPending($appUser?->id),
                'approve' => $recommendations->applySelection($appUser?->id, $approval['selection']),
                'reject' => $recommendations->rejectSelection($appUser?->id, $approval['selection']),
            };

            $slackService->sendMessage((string) $channel, $message);

            return ['ok' => true, 'handled' => 'ai_recommendation'];
        }

        if ($voicePath = $this->downloadVoiceAttachment($event, $slackService)) {
            $transcribed = $transcription->transcribe($voicePath);

            if (! $transcribed) {
                $slackService->sendMessage((string) $channel, 'I could not transcribe the voice note. Please send it again or type the instruction.');

                return ['ok' => true, 'handled' => 'voice_transcription_failed'];
            }

            $slackService->sendMessage((string) $channel, 'I heard: '.$transcribed);
            $aiBrain->sendSlackAnswer((string) $channel, $transcribed, $appUser, $slackService, ['source' => 'slack_voice']);

            return ['ok' => true, 'handled' => 'voice'];
        }

        if ($aiBrain->isAiPrompt($text)) {
            $aiBrain->sendSlackAnswer((string) $channel, $text, $appUser, $slackService, ['source' => 'slack_text']);

            return ['ok' => true, 'handled' => 'ai_brain'];
        }

        $command = $parser->parse($text);

        if ($command['action'] === 'help' || $command['action'] === 'unknown') {
            $slackService->sendMessage((string) $channel, $this->helpText());

            return ['ok' => true, 'handled' => 'help'];
        }

        $review = DailyReview::query()
            ->with(['items.task'])
            ->where('slack_channel_id', $channel)
            // Scope to the resolved operator rather than to the raw configured
            // id: a stale TASKFLOW_DAILY_USER_ID pointing at a user that no
            // longer exists used to make every review lookup return nothing,
            // which read as "no active review" instead of a configuration fault.
            ->when($appUser, fn ($query) => $query->where('user_id', $appUser->id))
            ->whereIn('status', ['sent', 'pending', 'responded'])
            ->latest('sent_at')
            ->latest()
            ->first();

        if (! $review) {
            $slackService->sendMessage((string) $channel, 'No active Miriam daily review was found for this channel.');

            return ['ok' => true, 'ignored' => 'no_active_review'];
        }

        $applied = 0;

        foreach ($command['numbers'] as $number) {
            $item = $review->items->firstWhere('position', $number);

            if (! $item || ! $item->task) {
                continue;
            }

            $this->applyCommand($command, $item, $appUser, $transitions, $clock);
            $applied++;
        }

        $review->update([
            'status' => 'responded',
            'responded_at' => now(),
        ]);

        // Report what actually changed rather than a blanket confirmation.
        $slackService->sendMessage(
            (string) $channel,
            $applied > 0
                ? "Miriam updated {$applied} review item(s)."
                : 'Miriam found no matching review item to update.'
        );

        return ['ok' => true, 'handled' => 'daily_review', 'updated' => $applied];
    }

    private function applyCommand(array $command, DailyReviewItem $item, ?User $actor, TaskTransitionService $transitions, OperationalClock $clock): void
    {
        $task = $item->task;
        $text = $command['text'];

        match ($command['action']) {
            'done' => $this->markDone($task, $item, $actor, $transitions),
            'move' => $this->moveTask($task, $item, (string) $command['date'], $clock),
            'block' => $this->blockTask($task, $item, $text),
            'waiting' => $this->waitingTask($task, $item, $text, $actor, $transitions),
            'note' => $this->noteTask($task, $item, $text),
            'skip' => $this->skipTask($task, $item),
            default => null,
        };
    }

    private function markDone(Task $task, DailyReviewItem $item, ?User $actor, TaskTransitionService $transitions): void
    {
        // Slack and the UI complete a task through the same domain service, so
        // audit history, reminder cancellation and list membership cannot drift.
        $transitions->apply($task, TaskTransitionService::COMPLETE, $actor, [
            'source' => 'slack_daily_review',
            'reason' => 'Marked complete from Slack daily review.',
        ]);

        $this->comment($task, $item, 'Marked complete from Slack daily review.');
        $item->update([
            'completed_at' => now(),
            'response_text' => 'done',
        ]);
    }

    private function moveTask(Task $task, DailyReviewItem $item, string $dateText, OperationalClock $clock): void
    {
        $dueDate = $this->parseDate($dateText, $clock);
        $task->update(['due_date' => $dueDate]);
        $this->comment($task, $item, "Moved to {$dueDate} from Slack daily review.");
        $item->update(['response_text' => "move {$dateText}"]);
    }

    private function blockTask(Task $task, DailyReviewItem $item, ?string $text): void
    {
        if (in_array('blocked', Task::STATUSES, true)) {
            $task->update(['status' => 'blocked']);
        }

        $this->comment($task, $item, 'Blocked from Slack daily review: '.($text ?: 'No detail provided.'));
        $item->update(['response_text' => $text]);
    }

    private function waitingTask(Task $task, DailyReviewItem $item, ?string $text, ?User $actor, TaskTransitionService $transitions): void
    {
        $transitions->apply($task, TaskTransitionService::MOVE_WAITING, $actor, [
            'source' => 'slack_daily_review',
            'reason' => 'Marked waiting from Slack daily review.',
        ]);

        $this->comment($task, $item, 'Waiting update from Slack daily review: '.($text ?: 'No detail provided.'));
        $item->update(['response_text' => $text]);
    }

    private function noteTask(Task $task, DailyReviewItem $item, ?string $text): void
    {
        $this->comment($task, $item, 'Slack daily review note: '.($text ?: 'No detail provided.'));
        $item->update(['response_text' => $text]);
    }

    private function skipTask(Task $task, DailyReviewItem $item): void
    {
        $this->comment($task, $item, 'Skipped in Slack evening review.');
        $item->update(['response_text' => 'skip']);
    }

    private function comment(Task $task, DailyReviewItem $item, string $body): void
    {
        $task->comments()->create([
            'user_id' => $item->dailyReview->user_id,
            'body' => $body,
        ]);
    }

    /** Relative dates are resolved on the operator's calendar, not on UTC's. */
    private function parseDate(string $dateText, OperationalClock $clock): string
    {
        return match (strtolower(trim($dateText))) {
            'today' => $clock->todayString(),
            'tomorrow' => $clock->tomorrowString(),
            'monday' => $clock->now()->next('monday')->toDateString(),
            default => Carbon::parse($dateText)->toDateString(),
        };
    }

    /**
     * The Development Manager commands are routed here so Slack still gets a
     * fast 200, but the reply must not imply anything was started or changed.
     */
    private function unavailableCapability(Request $request, SlackService $slackService, ?string $channel = null): array
    {
        $target = $channel
            ?: (string) (data_get($this->decodedInteraction($request), 'channel.id')
                ?: $request->input('channel_id')
                ?: config('services.slack.default_channel'));

        $message = implode("\n", [
            'That Miriam command is not available in this build.',
            'Nothing was started, stopped, approved, or changed.',
            '',
            $this->helpText(),
        ]);

        if (filled($target)) {
            $slackService->sendMessage($target, $message);
        }

        return ['ok' => true, 'ignored' => 'capability_unavailable'];
    }

    private function helpText(): string
    {
        return implode("\n", [
            '*Miriam daily review commands*',
            '`done 1` or `done 2,3`',
            '`move 1 today` / `move 1 tomorrow` / `move 2 monday`',
            '`block 3 waiting for Sunny`',
            '`waiting 4 waiting for client feedback`',
            '`note 2 tested partially, continue tomorrow`',
            '`skip 5`',
            '`friday what should I focus on today?`',
            '`approve ai 1` / `reject ai all` / `show ai pending`',
        ]);
    }

    private function isSlashCommand(Request $request): bool
    {
        return $request->isMethod('post')
            && filled($request->input('command'))
            && $this->interactionPayload($request) === null;
    }

    private function interactionPayload(Request $request): ?string
    {
        if ($request->filled('payload')) {
            return (string) $request->input('payload');
        }

        parse_str((string) $request->getContent(), $parsed);

        return isset($parsed['payload']) ? (string) $parsed['payload'] : null;
    }

    private function decodedInteraction(Request $request): array
    {
        $payload = $this->interactionPayload($request);

        return $payload ? (json_decode($payload, true) ?: []) : [];
    }

    private function parseAiApproval(string $text): ?array
    {
        if (preg_match('/^\s*show\s+ai\s+pending\s*$/i', $text)) {
            return ['action' => 'show', 'selection' => 'all'];
        }

        if (preg_match('/^\s*(approve|reject)\s+ai\s+(.+)\s*$/i', $text, $matches)) {
            return ['action' => strtolower($matches[1]), 'selection' => trim($matches[2])];
        }

        return null;
    }

    private function downloadVoiceAttachment(array $event, SlackService $slackService): ?string
    {
        foreach (($event['files'] ?? []) as $file) {
            $mime = strtolower((string) ($file['mimetype'] ?? $file['filetype'] ?? ''));

            if (! str_contains($mime, 'audio') && ! str_contains($mime, 'ogg') && ! str_contains($mime, 'mpeg')) {
                continue;
            }

            $url = $file['url_private_download'] ?? $file['url_private'] ?? null;

            if ($url) {
                return $slackService->downloadFile((string) $url);
            }
        }

        return null;
    }

    private function resolveUser(): ?User
    {
        $dailyUserId = config('services.slack.daily_user_id');

        if ($dailyUserId) {
            return User::query()->find((int) $dailyUserId) ?: User::query()->oldest('id')->first();
        }

        return User::query()->oldest('id')->first();
    }
}
