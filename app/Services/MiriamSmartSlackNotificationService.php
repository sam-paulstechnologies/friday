<?php

namespace App\Services;

use App\Services\Slack\SlackService;
use Illuminate\Support\Facades\Cache;

class MiriamSmartSlackNotificationService
{
    private const DEDUPE_MINUTES = 30;

    private const ALLOWED_TYPES = [
        'queue_started',
        'phase_started',
        'phase_passed',
        'phase_blocked',
        'hard_safety_blocker',
        'runner_offline_active_work',
        'manual_business_decision_needed',
        'release_package_ready',
        'development_summary',
        'development_blocked',
        'queue_completed',
    ];

    public function notify(string $type, string $text, array $context = [], array $blocks = []): array
    {
        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            return ['sent' => false, 'reason' => 'notification_type_suppressed'];
        }

        $channel = (string) (config('services.slack.codex_output_channel') ?: config('services.slack.default_channel'));

        if ($channel === '') {
            return ['sent' => false, 'reason' => 'missing_slack_channel'];
        }

        $key = $this->dedupeKey($type, $context);

        if (Cache::has($key)) {
            return ['sent' => false, 'reason' => 'duplicate_suppressed'];
        }

        $response = app(SlackService::class)->sendMessage($channel, $text, $blocks);
        Cache::put($key, true, now()->addMinutes(self::DEDUPE_MINUTES));

        return ['sent' => (bool) ($response['ok'] ?? false), 'response' => $response];
    }

    public function notifyPhasePassed(string $app, string $phase, ?int $jobId = null): array
    {
        return $this->notify('phase_passed', "Miriam phase passed: {$app} / {$phase}.", [
            'app' => $app,
            'phase' => $phase,
            'job_id' => $jobId,
        ]);
    }

    public function notifyQueueCompleted(string $summary, ?string $app = null, ?int $jobId = null): array
    {
        return $this->notify('queue_completed', "Miriam queue completed. {$summary}", [
            'app' => $app,
            'job_id' => $jobId,
            'summary' => sha1($summary),
        ]);
    }

    public function notifyDevelopmentSummary(string $app, string $summary, ?int $jobId = null, ?string $status = null): array
    {
        return $this->notify('development_summary', $summary, [
            'app' => $app,
            'job_id' => $jobId,
            'status' => $status ?: 'summary',
        ]);
    }

    public function notifyDevelopmentBlocked(string $app, string $phase, string $rootCause, ?int $jobId = null): array
    {
        return $this->notify('development_blocked', implode("\n", [
            '*Miriam development blocker*',
            "App: {$app}",
            "Phase: {$phase}",
            "Root cause: {$rootCause}",
            'Normal auto-repair attempts are complete or a human decision is needed.',
        ]), [
            'app' => $app,
            'phase' => $phase,
            'job_id' => $jobId,
            'failure' => sha1($rootCause),
        ]);
    }

    public function notifyHardSafetyBlocker(string $app, string $phase, string $rootCause, ?int $jobId = null): array
    {
        return $this->notify('hard_safety_blocker', implode("\n", [
            '*Miriam hard safety blocker*',
            "App: {$app}",
            "Phase: {$phase}",
            "Root cause: {$rootCause}",
            'Miriam stopped before deployment, upload, destructive DB work, or secret exposure.',
        ]), [
            'app' => $app,
            'phase' => $phase,
            'job_id' => $jobId,
            'failure' => sha1($rootCause),
        ]);
    }

    private function dedupeKey(string $type, array $context): string
    {
        return 'miriam_smart_slack_notice:'.sha1(implode(':', [
            $type,
            $context['app'] ?? 'none',
            $context['job_id'] ?? 'none',
            $context['phase'] ?? 'none',
            $context['failure_id'] ?? ($context['failure'] ?? 'none'),
            $context['status'] ?? 'none',
        ]));
    }
}
