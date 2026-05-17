<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DailyReview\DailyReviewService;
use App\Services\Slack\SlackService;
use Illuminate\Console\Command;

class SendEveningCheckin extends Command
{
    protected $signature = 'taskflow:send-evening-checkin {--user_id=}';

    protected $description = 'Send the evening Friday follow-up to Slack.';

    public function handle(DailyReviewService $dailyReviewService, SlackService $slackService): int
    {
        $channel = config('services.slack.default_channel');
        $userId = $this->option('user_id') ?: env('TASKFLOW_DAILY_USER_ID');

        if (! $channel) {
            $this->warn('SLACK_DEFAULT_CHANNEL is not configured.');
        }

        $users = User::query()
            ->when($userId, fn ($query) => $query->whereKey($userId))
            ->orderBy('id')
            ->get();

        foreach ($users as $user) {
            $review = $dailyReviewService->createEveningReview($user);
            $message = $dailyReviewService->formatEveningSlackMessage($review);
            $response = $slackService->sendMessage((string) $channel, $message);

            $review->update([
                'status' => ($response['ok'] ?? false) ? 'sent' : 'failed',
                'slack_channel_id' => $response['channel'] ?? $channel,
                'slack_message_ts' => $response['ts'] ?? null,
                'sent_at' => ($response['ok'] ?? false) ? now() : null,
            ]);
        }

        $this->info("Evening check-in processed for {$users->count()} user(s).");

        return self::SUCCESS;
    }
}
