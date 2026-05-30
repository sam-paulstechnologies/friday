<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DailyReview\DailyBriefingImageService;
use App\Services\DailyReview\DailyBriefingService;
use App\Services\DailyReview\DailyReviewService;
use App\Services\Slack\SlackService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendDailyBriefing extends Command
{
    protected $signature = 'taskflow:send-daily-briefing
        {--user_id=}
        {--portfolio=all}
        {--limit=20}
        {--priority=urgent-high}
        {--format=image}';

    protected $description = 'Send the morning Friday daily briefing to Slack.';

    public function handle(
        DailyReviewService $dailyReviewService,
        DailyBriefingService $briefingService,
        DailyBriefingImageService $imageService,
        SlackService $slackService
    ): int {
        $channel = config('services.slack.default_channel');
        $userId = $this->option('user_id') ?: env('TASKFLOW_DAILY_USER_ID');
        $portfolio = strtolower((string) $this->option('portfolio'));
        $priority = (string) $this->option('priority');
        $format = strtolower((string) $this->option('format'));
        $limit = max(1, (int) $this->option('limit'));

        if (! $channel) {
            $this->warn('SLACK_DEFAULT_CHANNEL is not configured.');
        }

        $users = User::query()
            ->when($userId, fn ($query) => $query->whereKey($userId))
            ->orderBy('id')
            ->get();

        foreach ($users as $user) {
            $review = $dailyReviewService->createMorningReview($user, [
                'portfolio' => $portfolio,
                'priority' => $priority,
            ]);

            $briefing = $briefingService->build($review, [
                'portfolio' => $portfolio,
                'priority' => $priority,
                'limit' => $limit,
            ]);
            $caption = $briefingService->caption($briefing);
            $response = null;

            if ($format === 'image') {
                try {
                    $imagePath = $imageService->generate($briefing);
                    $response = $slackService->sendImage(
                        (string) $channel,
                        $caption,
                        $imagePath,
                        'friday-daily-briefing-'.$briefing['date'].'.png',
                        'Friday Daily Briefing '.$briefing['date'],
                    );
                } catch (\Throwable $exception) {
                    Log::error('Daily briefing image generation failed; falling back to text.', [
                        'message' => $exception->getMessage(),
                    ]);

                    $response = $slackService->sendMessage((string) $channel, $briefingService->textMessage($briefing));
                }
            } else {
                $response = $slackService->sendMessage((string) $channel, $briefingService->textMessage($briefing));
            }

            $review->update([
                'status' => ($response['ok'] ?? false) ? 'sent' : 'failed',
                'slack_channel_id' => $response['channel'] ?? $channel,
                'slack_message_ts' => $response['ts'] ?? null,
                'sent_at' => ($response['ok'] ?? false) ? now() : null,
            ]);
        }

        $this->info("Daily briefing processed for {$users->count()} user(s).");

        return self::SUCCESS;
    }
}
