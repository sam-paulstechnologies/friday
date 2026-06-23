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
        'blocker',
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

    public function notifyDevelopmentStarted(string $app, string $work, string $goal, ?int $jobId = null, ?int $phaseId = null): array
    {
        return $this->notify('development_started', 'Development started', [
            'app' => $app,
            'job_id' => $jobId,
            'phase' => $phaseId ?: 'none',
            'status' => 'started',
        ], $this->developmentStartedBlocks($app, $goal));
    }

    public function notifyDevelopmentCompleted(string $app, array $summary, ?int $jobId = null, ?int $phaseId = null, array $context = []): array
    {
        return $this->notify('development_completed', 'Development completed', array_merge([
            'app' => $app,
            'job_id' => $jobId,
            'phase' => $phaseId ?: 'none',
            'status' => (string) ($summary['status'] ?? 'completed'),
            'summary_hash' => sha1(json_encode($summary)),
        ], $context), $this->developmentCompletedBlocks($summary));
    }

    public function notifyDevelopmentSummary(string $app, string $summary, ?int $jobId = null, ?string $status = null, array $context = []): array
    {
        return ['sent' => false, 'reason' => 'signal_only_development_summary_suppressed'];
    }

    public function notifyDevelopmentBlocked(string $app, string $phase, string $rootCause, ?int $jobId = null): array
    {
        return $this->notify('blocker', implode("\n", [
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

    private function developmentStartedBlocks(string $app, string $goal): array
    {
        return [
            [
                'type' => 'header',
                'text' => ['type' => 'plain_text', 'text' => 'Development started'],
            ],
            [
                'type' => 'section',
                'fields' => [
                    $this->field('App', $app),
                    $this->field('Goal', $goal),
                    $this->field('Started at', now()->toDateTimeString()),
                    $this->field('Details', 'Stored in Miriam DB'),
                ],
            ],
        ];
    }

    private function developmentCompletedBlocks(array $summary): array
    {
        return [
            [
                'type' => 'header',
                'text' => ['type' => 'plain_text', 'text' => 'Development completed'],
            ],
            [
                'type' => 'section',
                'fields' => [
                    $this->field('App', (string) ($summary['app'] ?? 'Miriam')),
                    $this->field('Work done', (string) ($summary['work_done'] ?? '-')),
                    $this->field('Status', (string) ($summary['status'] ?? '-')),
                    $this->field('Commit', (string) ($summary['commit'] ?? '-')),
                    $this->field('Tests', (string) ($summary['tests'] ?? '-')),
                    $this->field('Deployment', (string) ($summary['deployment'] ?? '-')),
                    $this->field('Next', (string) ($summary['next'] ?? '-')),
                ],
            ],
            [
                'type' => 'context',
                'elements' => [
                    ['type' => 'mrkdwn', 'text' => 'Full details stored in Miriam Development Ledger.'],
                ],
            ],
        ];
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
