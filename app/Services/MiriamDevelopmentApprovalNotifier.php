<?php

namespace App\Services;

use App\Models\MiriamDevelopmentFailure;
use App\Models\MiriamDevelopmentJob;
use App\Models\MiriamDevelopmentJobEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class MiriamDevelopmentApprovalNotifier
{
    private const DEDUPE_MINUTES = 30;

    public function notifyJobNeedsAttention(MiriamDevelopmentJob $job, ?string $reason = null): array
    {
        $job->loadMissing(['managedApp', 'runnerAgent']);

        if ($this->shouldSuppressJob($job)) {
            return ['sent' => false, 'reason' => 'job_suppressed'];
        }

        if (! in_array($job->status, ['waiting_for_approval', 'waiting_for_manual_fix'], true)) {
            return ['sent' => false, 'reason' => 'job_status_not_notifiable'];
        }

        $reason ??= $this->reasonForJob($job);
        $isSafetyGate = $this->isSafetyGate($job, null, $reason);

        if (! $isSafetyGate && $this->isPreQuietModeGate($job)) {
            return ['sent' => false, 'reason' => 'old_quiet_mode_gate_suppressed'];
        }

        if (! $isSafetyGate) {
            return ['sent' => false, 'reason' => 'quiet_development_mode'];
        }

        return $this->send($job, null, $reason);
    }

    public function notifyFailureNeedsAttention(MiriamDevelopmentFailure $failure, ?string $reason = null): array
    {
        $failure->loadMissing(['job.managedApp', 'runnerAgent', 'phaseRun.phase', 'fixAttempts']);

        if (! $failure->job) {
            return ['sent' => false, 'reason' => 'failure_has_no_job'];
        }

        if ($this->shouldSuppressJob($failure->job) || ! in_array($failure->status, ['open', 'fix_requested', 'fixing', 'manual_attention_required', 'failed'], true)) {
            return ['sent' => false, 'reason' => 'failure_suppressed'];
        }

        if ($reason === null && $this->shouldSuppressNormalAutoFixFailure($failure)) {
            return ['sent' => false, 'reason' => 'normal_failure_auto_fix_in_progress'];
        }

        $reason ??= $this->reasonForFailure($failure);
        $isSafetyGate = $this->isSafetyGate($failure->job, $failure, $reason);

        if (! $isSafetyGate && $this->isPreQuietModeGate($failure->job)) {
            return ['sent' => false, 'reason' => 'old_quiet_mode_gate_suppressed'];
        }

        if (! $isSafetyGate) {
            return ['sent' => false, 'reason' => 'quiet_development_mode'];
        }

        return $this->send(
            $failure->job,
            $failure,
            $reason
        );
    }

    private function send(MiriamDevelopmentJob $job, ?MiriamDevelopmentFailure $failure, string $reason): array
    {
        $channel = (string) (config('services.slack.codex_output_channel') ?: config('services.slack.default_channel'));

        if ($channel === '') {
            return ['sent' => false, 'reason' => 'missing_slack_channel'];
        }

        $reasonHash = sha1($reason);
        $dedupeKey = $this->dedupeKey($job, $failure, $reasonHash);

        if (Cache::has($dedupeKey) || $this->attentionNoticeAlreadySent($job, $failure, $reasonHash)) {
            return ['sent' => false, 'reason' => 'duplicate_suppressed'];
        }

        $message = $this->message($job, $failure, $reason);
        $result = app(MiriamSmartSlackNotificationService::class)->notify('safety_gate', $message['text'], [
            'app' => $job->managedApp?->name ?: 'Miriam',
            'job_id' => $job->id,
            'phase' => $failure?->phase_run_id ?: $job->current_phase_id ?: 'none',
            'failure_id' => $failure?->id ?: 'none',
            'status' => $job->status,
            'summary_hash' => $reasonHash,
        ]);
        $response = $result['response'] ?? [];

        Cache::put($dedupeKey, true, now()->addMinutes(self::DEDUPE_MINUTES));

        if ($response['ok'] ?? false) {
            $this->recordAttentionNoticeSent($job, $failure, $reason, $reasonHash);

            if ($failure) {
                $failure->update([
                    'slack_channel_id' => (string) ($response['channel'] ?? $channel),
                    'slack_message_ts' => (string) ($response['ts'] ?? $failure->slack_message_ts),
                ]);
            }
        }

        return $result;
    }

    private function message(MiriamDevelopmentJob $job, ?MiriamDevelopmentFailure $failure, string $reason): array
    {
        $appName = $job->managedApp?->name ?: 'Miriam';
        $autoFixAttempts = $failure ? $failure->fixAttempts->count() : 0;
        $recommended = $failure
            ? ($autoFixAttempts >= 3
                ? 'Review the blocker in Miriam, then approve continuation or stop the job.'
                : 'Review the safety gate in Miriam and approve or reject it.')
            : ($job->status === 'waiting_for_approval'
                ? 'Review the job in Development Manager before continuing.'
                : 'Resolve the manual gate, then resume or stop the job.');

        $lines = [
            'Codex needs your attention - please review',
            'Development: '.$this->developmentName($job, $failure),
            "Reason: {$reason}",
            "Required decision: {$recommended}",
        ];

        if ($failure) {
            $lines[] = 'Failure: #'.$failure->id.' / '.$failure->failure_type.' / '.$failure->status;
            $lines[] = 'Auto-fix attempts: '.$autoFixAttempts.'/3';
        }

        $text = implode("\n", $lines);

        return [
            'text' => $text,
            'blocks' => [],
        ];
    }

    private function blocks(MiriamDevelopmentJob $job, ?MiriamDevelopmentFailure $failure, string $reason, string $recommended): array
    {
        $appName = $job->managedApp?->name ?: 'Miriam';
        $elements = [];

        if ($failure) {
            $elements[] = [
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => 'Show Error'],
                'action_id' => "dev_show_error:{$failure->id}",
                'value' => "dev_show_error:{$failure->id}",
            ];
            $elements[] = [
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => 'Approve / Complete'],
                'style' => 'primary',
                'action_id' => "dev_approve_job:{$job->id}",
                'value' => "dev_approve_job:{$job->id}",
            ];
        } elseif ($job->status === 'waiting_for_approval') {
            $elements[] = [
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => 'Approve / Complete'],
                'style' => 'primary',
                'action_id' => "dev_approve_job:{$job->id}",
                'value' => "dev_approve_job:{$job->id}",
            ];
        }

        $elements[] = [
            'type' => 'button',
            'text' => ['type' => 'plain_text', 'text' => 'Stop Job'],
            'style' => 'danger',
            'action_id' => "dev_stop_job:{$job->id}",
            'value' => "dev_stop_job:{$job->id}",
            'confirm' => [
                'title' => ['type' => 'plain_text', 'text' => 'Stop job?'],
                'text' => ['type' => 'mrkdwn', 'text' => 'This cancels the cloud-side job safely. It does not deploy or run shell commands.'],
                'confirm' => ['type' => 'plain_text', 'text' => 'Stop job'],
                'deny' => ['type' => 'plain_text', 'text' => 'Cancel'],
            ],
        ];

        return [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*Approval needed for {$appName}*\nJob #{$job->id} is `{$job->status}`".($failure ? " with failure #{$failure->id}." : '.'),
                ],
            ],
            [
                'type' => 'section',
                'fields' => [
                    ['type' => 'mrkdwn', 'text' => "*Reason*\n".str($reason)->limit(320)],
                    ['type' => 'mrkdwn', 'text' => "*Next action*\n".str($recommended)->limit(320)],
                ],
            ],
            [
                'type' => 'actions',
                'elements' => $elements,
            ],
        ];
    }

    private function reasonForJob(MiriamDevelopmentJob $job): string
    {
        return match ($job->status) {
            'waiting_for_approval' => $job->error_message ?: 'The runner completed a gated step and Miriam needs manual approval before continuing.',
            'waiting_for_manual_fix' => $job->error_message ?: 'The runner hit a manual-fix gate and needs user action before continuing.',
            default => $job->error_message ?: 'Development Manager needs attention.',
        };
    }

    private function reasonForFailure(MiriamDevelopmentFailure $failure): string
    {
        if ($failure->status === 'fixing') {
            return 'Manual validation was requested. The runner should validate this failure and stop.';
        }

        if ($failure->status === 'manual_attention_required') {
            return 'Manual attention is required at the local system before Miriam can continue.';
        }

        return $failure->summary ?: $failure->title;
    }

    private function dedupeKey(MiriamDevelopmentJob $job, ?MiriamDevelopmentFailure $failure, string $reasonHash): string
    {
        return 'miriam_dev_approval_notice:'.sha1(implode(':', [
            'job',
            $job->id,
            'failure',
            $failure?->id ?: 'none',
            $reasonHash,
        ]));
    }

    private function attentionNoticeAlreadySent(MiriamDevelopmentJob $job, ?MiriamDevelopmentFailure $failure, string $reasonHash): bool
    {
        if (! Schema::hasTable('miriam_development_job_events')) {
            return false;
        }

        return MiriamDevelopmentJobEvent::query()
            ->where('development_job_id', $job->id)
            ->where('event_type', 'codex_attention_notice_sent')
            ->where('meta_json', 'like', '%"reason_hash":"'.$reasonHash.'"%')
            ->when($failure, fn ($query) => $query->where('phase_run_id', $failure->phase_run_id))
            ->exists();
    }

    private function recordAttentionNoticeSent(MiriamDevelopmentJob $job, ?MiriamDevelopmentFailure $failure, string $reason, string $reasonHash): void
    {
        if (! Schema::hasTable('miriam_development_job_events')) {
            return;
        }

        MiriamDevelopmentJobEvent::create([
            'development_job_id' => $job->id,
            'phase_run_id' => $failure?->phase_run_id,
            'runner_agent_id' => $failure?->runner_agent_id ?: $job->runner_agent_id,
            'event_type' => 'codex_attention_notice_sent',
            'title' => 'Codex attention notice sent',
            'body' => $reason,
            'meta_json' => json_encode([
                'failure_id' => $failure?->id,
                'reason_hash' => $reasonHash,
                'policy' => 'strict_codex_development_signal_only',
            ], JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function developmentName(MiriamDevelopmentJob $job, ?MiriamDevelopmentFailure $failure): string
    {
        $candidate = $job->title
            ?: $failure?->phaseRun?->phase?->title
            ?: $job->currentPhase?->title
            ?: $job->managedApp?->name
            ?: 'Miriam development';
        $words = preg_split('/\s+/', trim((string) str($candidate)->replaceMatches('/[^\pL\pN\s-]+/u', ' ')->squish())) ?: [];
        $name = implode(' ', array_slice(array_filter($words), 0, 6));

        return $name !== '' ? $name : 'Miriam development';
    }

    private function shouldSuppressNormalAutoFixFailure(MiriamDevelopmentFailure $failure): bool
    {
        if (! $failure->can_auto_fix || $failure->needs_user_at_system || $failure->status === 'manual_attention_required') {
            return false;
        }

        if (in_array($failure->failure_type, ['safety_risk', 'manual_browser_required'], true) || $failure->severity === 'critical') {
            return false;
        }

        return $failure->fixAttempts->count() < 3;
    }

    private function isSafetyGate(MiriamDevelopmentJob $job, ?MiriamDevelopmentFailure $failure, string $reason): bool
    {
        if ($failure?->needs_user_at_system || $failure?->severity === 'critical') {
            return true;
        }

        if ($failure && $failure->fixAttempts->count() >= 3 && in_array($failure->status, ['failed', 'blocked', 'manual_attention_required'], true)) {
            return true;
        }

        if ($failure && in_array($failure->failure_type, ['safety_risk', 'manual_browser_required', 'manual_credentials_needed'], true)) {
            return true;
        }

        return $this->textContainsAny($reason.' '.$job->error_message, [
            'destructive db',
            'destructive database',
            'production deploy',
            'deploy to production',
            '.env',
            'secret',
            'token',
            'credential',
            'delete files',
            'delete data',
            'external message',
            'email/client action',
            'payment',
            'billing',
            'manual credentials',
            'human business decision',
            'hard safety',
            'safety gate',
        ]);
    }

    private function shouldSuppressJob(MiriamDevelopmentJob $job): bool
    {
        if (in_array($job->status, ['cancelled', 'completed', 'archived'], true)) {
            return true;
        }

        return $this->textContainsAny(implode(' ', [
            $job->title,
            $job->run_mode,
            $job->error_message,
            $job->options_json,
            $job->runnerAgent?->name,
            $job->runnerAgent?->slug,
        ]), [
            'verification',
            'test-only',
            'test only',
            'slack callback test',
            'fake diagnostic',
            'diagnostic',
            'disabled',
            'temp runner',
            'verification runner',
        ]);
    }

    private function isPreQuietModeGate(MiriamDevelopmentJob $job): bool
    {
        if (! $job->created_at) {
            return false;
        }

        return $job->created_at->lessThan(app(MiriamDevelopmentLedgerService::class)->quietModeEnabledAt());
    }

    private function textContainsAny(string $text, array $needles): bool
    {
        $haystack = strtolower($text);

        foreach ($needles as $needle) {
            if (str_contains($haystack, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
