<?php

namespace App\Http\Controllers;

use App\Models\DailyReview;
use App\Models\DailyReviewItem;
use App\Models\Task;
use App\Models\User;
use App\Services\Ai\AiBrainService;
use App\Services\Ai\AiRecommendationService;
use App\Services\Ai\AiTranscriptionService;
use App\Services\Slack\SlackCommandParser;
use App\Services\Slack\SlackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SlackWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        SlackService $slackService,
        SlackCommandParser $parser,
        AiBrainService $aiBrain,
        AiRecommendationService $recommendations,
        AiTranscriptionService $transcription,
    ) {
        if (! $slackService->verifySignature($request)) {
            abort(403);
        }

        if ($request->input('type') === 'url_verification') {
            return response()->json(['challenge' => $request->input('challenge')]);
        }

        $event = $request->input('event', []);
        $channel = $event['channel'] ?? null;
        $user = $event['user'] ?? null;
        $text = trim((string) ($event['text'] ?? ''));

        if (($event['bot_id'] ?? null) || ($event['subtype'] ?? null) === 'bot_message') {
            return response()->json(['ok' => true]);
        }

        if (config('services.slack.default_channel') && $channel !== config('services.slack.default_channel')) {
            Log::warning('Slack event ignored from unconfigured channel.', ['channel' => $channel]);

            return response()->json(['ok' => true]);
        }

        if (config('services.slack.allowed_user_id') && $user !== config('services.slack.allowed_user_id')) {
            Log::warning('Slack event ignored from unconfigured user.', ['user' => $user]);

            return response()->json(['ok' => true]);
        }

        Log::info('Slack daily review message received.', ['channel' => $channel, 'user' => $user, 'text' => $text]);

        $appUser = $this->resolveUser();

        if ($approval = $this->parseAiApproval($text)) {
            $message = match ($approval['action']) {
                'show' => $recommendations->formatPending($appUser?->id),
                'approve' => $recommendations->applySelection($appUser?->id, $approval['selection']),
                'reject' => $recommendations->rejectSelection($appUser?->id, $approval['selection']),
            };

            $slackService->sendMessage((string) $channel, $message);

            return response()->json(['ok' => true]);
        }

        if ($voicePath = $this->downloadVoiceAttachment($event, $slackService)) {
            $transcribed = $transcription->transcribe($voicePath);

            if (! $transcribed) {
                $slackService->sendMessage((string) $channel, 'I could not transcribe the voice note. Please send it again or type the instruction.');

                return response()->json(['ok' => true]);
            }

            $slackService->sendMessage((string) $channel, 'I heard: '.$transcribed);
            $aiBrain->sendSlackAnswer((string) $channel, $transcribed, $appUser, $slackService, ['source' => 'slack_voice']);

            return response()->json(['ok' => true]);
        }

        if ($aiBrain->isAiPrompt($text)) {
            $aiBrain->sendSlackAnswer((string) $channel, $text, $appUser, $slackService, ['source' => 'slack_text']);

            return response()->json(['ok' => true]);
        }

        $command = $parser->parse($text);

        if ($command['action'] === 'help' || $command['action'] === 'unknown') {
            $slackService->sendMessage((string) $channel, $this->helpText());

            return response()->json(['ok' => true]);
        }

        $review = DailyReview::query()
            ->with(['items.task'])
            ->where('slack_channel_id', $channel)
            ->when(env('TASKFLOW_DAILY_USER_ID'), fn ($query, $userId) => $query->where('user_id', $userId))
            ->whereIn('status', ['sent', 'pending', 'responded'])
            ->latest('sent_at')
            ->latest()
            ->first();

        if (! $review) {
            $slackService->sendMessage((string) $channel, 'No active Friday daily review was found for this channel.');

            return response()->json(['ok' => true]);
        }

        foreach ($command['numbers'] as $number) {
            $item = $review->items->firstWhere('position', $number);

            if (! $item || ! $item->task) {
                continue;
            }

            $this->applyCommand($command, $item);
        }

        $review->update([
            'status' => 'responded',
            'responded_at' => now(),
        ]);

        $slackService->sendMessage((string) $channel, 'Friday updated the matching review item(s).');

        return response()->json(['ok' => true]);
    }

    private function applyCommand(array $command, DailyReviewItem $item): void
    {
        $task = $item->task;
        $text = $command['text'];

        match ($command['action']) {
            'done' => $this->markDone($task, $item),
            'move' => $this->moveTask($task, $item, (string) $command['date']),
            'block' => $this->blockTask($task, $item, $text),
            'waiting' => $this->waitingTask($task, $item, $text),
            'note' => $this->noteTask($task, $item, $text),
            'skip' => $this->skipTask($task, $item),
            default => null,
        };
    }

    private function markDone(Task $task, DailyReviewItem $item): void
    {
        $task->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        $task->comments()->create([
            'user_id' => $item->dailyReview->user_id,
            'body' => 'Marked complete from Slack daily review.',
        ]);
        $item->update([
            'completed_at' => now(),
            'response_text' => 'done',
        ]);
    }

    private function moveTask(Task $task, DailyReviewItem $item, string $dateText): void
    {
        $dueDate = $this->parseDate($dateText);
        $task->update(['due_date' => $dueDate]);
        $this->comment($task, $item, "Moved to {$dueDate} from Slack daily review.");
        $item->update(['response_text' => "move {$dateText}"]);
    }

    private function blockTask(Task $task, DailyReviewItem $item, ?string $text): void
    {
        $data = [];

        if (in_array('blocked', Task::STATUSES, true)) {
            $data['status'] = 'blocked';
        }

        if ($data !== []) {
            $task->update($data);
        }

        $this->comment($task, $item, 'Blocked from Slack daily review: '.($text ?: 'No detail provided.'));
        $item->update(['response_text' => $text]);
    }

    private function waitingTask(Task $task, DailyReviewItem $item, ?string $text): void
    {
        if (in_array('waiting', Task::STATUSES, true)) {
            $task->update(['status' => 'waiting']);
        }

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

    private function parseDate(string $dateText): string
    {
        return match (strtolower(trim($dateText))) {
            'tomorrow' => now()->addDay()->toDateString(),
            'monday' => now()->next('monday')->toDateString(),
            default => \Carbon\Carbon::parse($dateText)->toDateString(),
        };
    }

    private function helpText(): string
    {
        return implode("\n", [
            '*Friday daily review commands*',
            '`done 1` or `done 2,3`',
            '`move 1 tomorrow`',
            '`move 2 monday`',
            '`block 3 waiting for Sunny`',
            '`waiting 4 waiting for client feedback`',
            '`note 2 tested partially, continue tomorrow`',
            '`skip 5`',
            '`friday what should I focus on today?`',
            '`approve ai 1` / `reject ai all` / `show ai pending`',
        ]);
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
        $dailyUserId = env('TASKFLOW_DAILY_USER_ID');

        if ($dailyUserId) {
            return User::find((int) $dailyUserId);
        }

        return User::query()->oldest('id')->first();
    }
}
