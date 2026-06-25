<?php

namespace App\Services;

use App\Services\Slack\SlackService;
use Illuminate\Support\Facades\Cache;

class MiriamSmartSlackNotificationService
{
    private const DEDUPE_MINUTES = 30;

    private const ALLOWED_TYPES = [
        'development_started',
        'development_completed',
        'safety_gate',
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

    public function notifyDevelopmentStarted(string $developmentName, string $shortDescription, ?int $jobId = null, ?int $phaseId = null): array
    {
        return $this->notify('development_started', implode("\n", [
            "Codex started development: {$developmentName}",
            $shortDescription,
        ]), [
            'app' => $developmentName,
            'job_id' => $jobId,
            'phase' => $phaseId ?: 'none',
            'status' => 'started',
        ]);
    }

    public function notifyDevelopmentCompleted(string $developmentName, array $summary, ?int $jobId = null, ?int $phaseId = null, array $context = []): array
    {
        $shortSummary = (string) ($summary['short_summary'] ?? $summary['work_done'] ?? 'Development completed.');
        $tests = (string) ($summary['tests'] ?? 'not recorded');
        $commit = (string) ($summary['commit'] ?? '-');

        return $this->notify('development_completed', implode("\n", [
            "Codex completed development: {$developmentName}",
            $shortSummary,
            "Validation: {$tests}",
            "Commit: {$commit}",
        ]), array_merge([
            'app' => $developmentName,
            'job_id' => $jobId,
            'phase' => $phaseId ?: 'none',
            'status' => (string) ($summary['status'] ?? 'completed'),
            'summary_hash' => sha1(json_encode($summary)),
        ], $context));
    }

    public function notifyDevelopmentSummary(string $app, string $summary, ?int $jobId = null, ?string $status = null, array $context = []): array
    {
        return ['sent' => false, 'reason' => 'signal_only_development_summary_suppressed'];
    }

    public function notifyDevelopmentBlocked(string $app, string $phase, string $rootCause, ?int $jobId = null): array
    {
        return ['sent' => false, 'reason' => 'blocker_progress_notification_suppressed'];
    }

    public function notifyHardSafetyBlocker(string $app, string $phase, string $rootCause, ?int $jobId = null): array
    {
        return $this->notify('safety_gate', implode("\n", [
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
            $context['summary_hash'] ?? 'none',
            $context['notification_dedupe_key'] ?? 'none',
        ]));
    }

    private function field(string $label, string $value): array
    {
        $clean = (string) str(trim(str_replace(["\r", "\n", '|'], [' ', ' ', '/'], $value)) ?: '-')->limit(180);

        return [
            'type' => 'mrkdwn',
            'text' => "*{$label}*\n{$clean}",
        ];
    }
}
