<?php

namespace App\Services\Slack;

class SlackCommandParser
{
    public function parse(string $text): array
    {
        $text = trim($text);

        if ($text === '' || strtolower($text) === 'help') {
            return ['action' => 'help', 'numbers' => [], 'text' => null, 'date' => null];
        }

        if (preg_match('/^done\s+([0-9,\s]+)$/i', $text, $matches)) {
            return ['action' => 'done', 'numbers' => $this->numbers($matches[1]), 'text' => null, 'date' => null];
        }

        if (preg_match('/^move\s+(\d+)\s+(.+)$/i', $text, $matches)) {
            return ['action' => 'move', 'numbers' => [(int) $matches[1]], 'text' => trim($matches[2]), 'date' => trim($matches[2])];
        }

        if (preg_match('/^block\s+(\d+)\s+(.+)$/i', $text, $matches)) {
            return ['action' => 'block', 'numbers' => [(int) $matches[1]], 'text' => trim($matches[2]), 'date' => null];
        }

        if (preg_match('/^waiting\s+(\d+)\s+(.+)$/i', $text, $matches)) {
            return ['action' => 'waiting', 'numbers' => [(int) $matches[1]], 'text' => trim($matches[2]), 'date' => null];
        }

        if (preg_match('/^note\s+(\d+)\s+(.+)$/i', $text, $matches)) {
            return ['action' => 'note', 'numbers' => [(int) $matches[1]], 'text' => trim($matches[2]), 'date' => null];
        }

        if (preg_match('/^skip\s+(\d+)$/i', $text, $matches)) {
            return ['action' => 'skip', 'numbers' => [(int) $matches[1]], 'text' => null, 'date' => null];
        }

        return ['action' => 'unknown', 'numbers' => [], 'text' => $text, 'date' => null];
    }

    public function parseMiriamPromptCommand(string $text): ?array
    {
        $text = $this->normalizeText($text);
        $text = preg_replace('/^\/?miriam\s+/i', '', $text, 1, $matched);

        if (! $matched) {
            return null;
        }

        $text = $this->normalizeText($text);

        if (preg_match('/^next\s+codex$/i', $text)) {
            return ['action' => 'next_codex'];
        }

        if (preg_match('/^dev\s+status$/i', $text)) {
            return ['action' => 'dev_status'];
        }

        if (preg_match('/^dev\s+go$/i', $text)) {
            return ['action' => 'dev_go'];
        }

        if (preg_match('/^dev\s+go\s+multi$/i', $text)) {
            return ['action' => 'dev_go_multi'];
        }

        if (preg_match('/^dev\s+go\s+app\s+([a-z0-9_\-]+)$/i', $text, $matches)) {
            return ['action' => 'dev_go_app', 'app_slug' => trim($matches[1])];
        }

        if (preg_match('/^dev\s+stop$/i', $text)) {
            return ['action' => 'dev_stop'];
        }

        if (preg_match('/^dev\s+pause$/i', $text)) {
            return ['action' => 'dev_pause'];
        }

        if (preg_match('/^dev\s+resume$/i', $text)) {
            return ['action' => 'dev_resume'];
        }

        if (preg_match('/^dev\s+approve\s+(?:job\s+)?(\d+)$/i', $text, $matches) || preg_match('/^dev_approve_job:(\d+)$/i', $text, $matches)) {
            return ['action' => 'dev_approve_job', 'job_id' => (int) $matches[1]];
        }

        if (preg_match('/^dev\s+failures$/i', $text)) {
            return ['action' => 'dev_failures'];
        }

        if (preg_match('/^dev\s+monitor$/i', $text)) {
            return ['action' => 'dev_monitor'];
        }

        if (preg_match('/^dev\s+summary$/i', $text)) {
            return ['action' => 'dev_summary'];
        }

        if (preg_match('/^blockers$/i', $text)) {
            return ['action' => 'dev_blockers'];
        }

        if (preg_match('/^next$/i', $text)) {
            return ['action' => 'dev_next'];
        }

        if (preg_match('/^completed\s+today$/i', $text)) {
            return ['action' => 'dev_completed_today'];
        }

        if (preg_match('/^runner\s+status$/i', $text)) {
            return ['action' => 'runner_status'];
        }

        if (preg_match('/^runner\s+alerts$/i', $text)) {
            return ['action' => 'runner_alerts'];
        }

        if (preg_match('/^dev\s+status\s+app\s+([a-z0-9_\-]+)$/i', $text, $matches)) {
            return ['action' => 'dev_status_app', 'app_slug' => trim($matches[1])];
        }

        if (preg_match('/^apps$/i', $text)) {
            return ['action' => 'apps'];
        }

        if (preg_match('/^app\s+status\s+([a-z0-9_\-]+)$/i', $text, $matches)) {
            return ['action' => 'app_status', 'app_slug' => trim($matches[1])];
        }

        if (preg_match('/^app\s+open\s+([a-z0-9_\-]+)$/i', $text, $matches)) {
            return ['action' => 'app_open', 'app_slug' => trim($matches[1])];
        }

        if (preg_match('/^app\s+health$/i', $text)) {
            return ['action' => 'app_health'];
        }

        if (preg_match('/^app\s+health\s+([a-z0-9_\-]+)$/i', $text, $matches)) {
            return ['action' => 'app_health_one', 'app_slug' => trim($matches[1])];
        }

        if (preg_match('/^app\s+dry-run\s+([a-z0-9_\-]+)$/i', $text, $matches)) {
            return ['action' => 'app_dry_run', 'app_slug' => trim($matches[1])];
        }

        if (preg_match('/^app\s+validate\s+([a-z0-9_\-]+)$/i', $text, $matches)) {
            return ['action' => 'app_validate', 'app_slug' => trim($matches[1])];
        }

        if (preg_match('/^releases$/i', $text)) {
            return ['action' => 'releases'];
        }

        if (preg_match('/^sprint\s+plan$/i', $text) || preg_match('/^30[- ]day\s+sprint$/i', $text)) {
            return ['action' => 'sprint_plan'];
        }

        if (preg_match('/^release\s+status\s+(\d+)$/i', $text, $matches)) {
            return ['action' => 'release_status', 'release_package_id' => (int) $matches[1]];
        }

        if (preg_match('/^release\s+create\s+job\s+(\d+)$/i', $text, $matches)) {
            return ['action' => 'release_create_job', 'job_id' => (int) $matches[1]];
        }

        if (preg_match('/^release\s+approve\s+(\d+)$/i', $text, $matches)) {
            return ['action' => 'release_approve', 'release_package_id' => (int) $matches[1]];
        }

        if (preg_match('/^release\s+reject\s+(\d+)$/i', $text, $matches)) {
            return ['action' => 'release_reject', 'release_package_id' => (int) $matches[1]];
        }

        if (preg_match('/^dev\s+apply\s+fix\s+(\d+)$/i', $text, $matches) || preg_match('/^dev_apply_fix:(\d+)$/i', $text, $matches)) {
            return ['action' => 'dev_apply_fix', 'failure_id' => (int) $matches[1]];
        }

        if (preg_match('/^dev\s+show\s+error\s+(\d+)$/i', $text, $matches) || preg_match('/^dev_show_error:(\d+)$/i', $text, $matches)) {
            return ['action' => 'dev_show_error', 'failure_id' => (int) $matches[1]];
        }

        if (preg_match('/^dev\s+manual\s+fix\s+(\d+)$/i', $text, $matches) || preg_match('/^dev_manual_fix:(\d+)$/i', $text, $matches)) {
            return ['action' => 'dev_manual_fix', 'failure_id' => (int) $matches[1]];
        }

        if (preg_match('/^dev\s+resume\s+after\s+manual\s+fix\s+(\d+)$/i', $text, $matches) || preg_match('/^dev_resume_after_manual_fix:(\d+)$/i', $text, $matches)) {
            return ['action' => 'dev_resume_after_manual_fix', 'failure_id' => (int) $matches[1]];
        }

        if (preg_match('/^dev\s+need\s+user\s+at\s+system\s+(\d+)$/i', $text, $matches) || preg_match('/^dev_need_user_at_system:(\d+)$/i', $text, $matches)) {
            return ['action' => 'dev_need_user_at_system', 'failure_id' => (int) $matches[1]];
        }

        if (preg_match('/^dev\s+rollback\s+phase\s+(\d+)$/i', $text, $matches) || preg_match('/^dev_rollback_phase:(\d+)$/i', $text, $matches)) {
            return ['action' => 'dev_rollback_phase', 'failure_id' => (int) $matches[1]];
        }

        if (preg_match('/^dev\s+stop\s+job\s+(\d+)$/i', $text, $matches) || preg_match('/^dev_stop_job:(\d+)$/i', $text, $matches)) {
            return ['action' => 'dev_stop_job', 'job_id' => (int) $matches[1]];
        }

        if (preg_match('/^current\s+phase$/i', $text)) {
            return ['action' => 'current_phase'];
        }

        if (preg_match('/^codex\s+next\s+after\s+result\s+([\s\S]+)$/i', $text, $matches)) {
            return ['action' => 'codex_next_after_result', 'output' => trim($matches[1])];
        }

        if (preg_match('/^codex\s+result\s+([\s\S]+)$/i', $text, $matches)) {
            return ['action' => 'codex_result', 'output' => trim($matches[1])];
        }

        if (preg_match('/^prompts$/i', $text)) {
            return ['action' => 'prompts'];
        }

        if (preg_match('/^prompt\s+([a-z0-9_\-]+)$/i', $text, $matches)) {
            return ['action' => 'prompt', 'phase_key' => trim($matches[1])];
        }

        return ['action' => 'prompt_help'];
    }

    public function parseMiriamNaturalLanguage(string $text): ?array
    {
        $normalized = strtolower($this->normalizeText($text));
        $addressedToMiriam = (bool) preg_match('/^miriam[,:]?\s*/', $normalized) || str_contains($normalized, 'miriam');
        $text = preg_replace('/^miriam[,:]?\s*/', '', $normalized) ?? $normalized;

        if ($text === '') {
            return null;
        }

        $mentionsMiriamDomain = $addressedToMiriam
            || str_contains($text, 'codex')
            || str_contains($text, 'churchforce')
            || str_contains($text, 'catererhq')
            || str_contains($text, 'blocked')
            || str_contains($text, 'release')
            || str_contains($text, 'demo')
            || str_contains($text, 'pause')
            || str_contains($text, 'resume')
            || str_contains($text, 'start next safe');

        if (! $mentionsMiriamDomain) {
            return null;
        }

        if (str_contains($text, 'what is blocked') || str_contains($text, 'what\'s blocked') || preg_match('/\bblocked\??$/', $text)) {
            return [
                'intent' => 'blocker_query',
                'action' => 'blocker_query',
                'confidence' => 0.95,
                'read_only' => true,
            ];
        }

        if (str_contains($text, 'what is codex doing') || str_contains($text, 'what\'s codex doing') || str_contains($text, 'codex doing')) {
            return [
                'intent' => 'status_query',
                'action' => 'status_query',
                'confidence' => 0.95,
                'read_only' => true,
            ];
        }

        if (str_contains($text, 'what should i run next') || str_contains($text, 'run next')) {
            return [
                'intent' => 'next_action_query',
                'action' => 'next_action_query',
                'confidence' => 0.9,
                'read_only' => true,
            ];
        }

        if (preg_match('/\b(status of churchforce|churchforce status|status for churchforce|how is churchforce|how\'s churchforce)\b/i', $text)) {
            return [
                'intent' => 'app_status_query',
                'action' => 'app_status_query',
                'app_slug' => 'churchforce',
                'confidence' => 0.92,
                'read_only' => true,
            ];
        }

        if (preg_match('/\b(status of catererhq|catererhq status|status for catererhq|how is catererhq|how\'s catererhq)\b/i', $text)) {
            return [
                'intent' => 'app_status_query',
                'action' => 'app_status_query',
                'app_slug' => 'catererhq',
                'confidence' => 0.92,
                'read_only' => true,
            ];
        }

        if (str_contains($text, 'pause everything') || str_contains($text, 'pause all jobs') || str_contains($text, 'pause all')) {
            return [
                'intent' => 'pause_request',
                'action' => 'pause_all',
                'confidence' => 0.94,
                'requires_confirmation' => true,
                'reason' => 'Pausing active Miriam development jobs changes queue state.',
            ];
        }

        if (str_contains($text, 'start next safe churchforce task')) {
            return [
                'intent' => 'start_task_request',
                'action' => 'start_next_safe_task',
                'app_slug' => 'churchforce',
                'confidence' => 0.96,
                'requires_confirmation' => true,
                'reason' => 'Starting app work creates a runner job.',
            ];
        }

        if (str_contains($text, 'start next safe catererhq task')) {
            return [
                'intent' => 'start_task_request',
                'action' => 'start_next_safe_task',
                'app_slug' => 'catererhq',
                'confidence' => 0.96,
                'requires_confirmation' => true,
                'reason' => 'Starting app work creates a runner job.',
            ];
        }

        if (str_contains($text, 'resume churchforce') || str_contains($text, 'resume catererhq') || preg_match('/^resume\b/', $text)) {
            return [
                'intent' => 'resume_request',
                'action' => 'resume_app',
                'app_slug' => str_contains($text, 'catererhq') ? 'catererhq' : (str_contains($text, 'churchforce') ? 'churchforce' : null),
                'confidence' => 0.86,
                'requires_confirmation' => true,
                'reason' => 'Resuming Miriam development jobs changes queue state.',
            ];
        }

        if (str_contains($text, 'show releases') || str_contains($text, 'release packages') || preg_match('/^releases\??$/', $text)) {
            return [
                'intent' => 'release_query',
                'action' => 'release_query',
                'confidence' => 0.92,
                'read_only' => true,
            ];
        }

        if (str_contains($text, 'ready for demo') || str_contains($text, 'demo ready') || str_contains($text, 'demo readiness')) {
            return [
                'intent' => 'demo_readiness_query',
                'action' => 'demo_readiness_query',
                'confidence' => 0.9,
                'read_only' => true,
            ];
        }

        if (str_contains($text, 'sprint plan') || str_contains($text, '30 day')) {
            return [
                'intent' => 'next_action_query',
                'action' => 'sprint_plan_query',
                'confidence' => 0.88,
                'read_only' => true,
            ];
        }

        return $addressedToMiriam
            ? [
                'intent' => 'unknown',
                'action' => 'unknown',
                'confidence' => 0.2,
                'requires_clarification' => true,
            ]
            : null;
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace("\xc2\xa0", ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function numbers(string $value): array
    {
        return collect(preg_split('/[,\s]+/', $value))
            ->filter()
            ->map(fn (string $number) => (int) $number)
            ->filter(fn (int $number) => $number > 0)
            ->values()
            ->all();
    }
}
