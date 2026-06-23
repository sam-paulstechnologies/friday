<?php

namespace App\Services;

use App\Models\MiriamDevelopmentPhaseRun;
use Illuminate\Support\Str;

class DevelopmentFailureClassifierService
{
    public function classify(MiriamDevelopmentPhaseRun $phaseRun): array
    {
        $validation = $phaseRun->validation();
        $parsed = $phaseRun->parsedResult();
        $text = implode("\n", array_filter([
            $phaseRun->codex_stdout,
            $phaseRun->codex_stderr,
            $phaseRun->error_message,
            json_encode($validation),
            json_encode($parsed),
        ]));
        $lower = strtolower($text);

        if (($parsed['safety_scanner'] ?? null) === 'blocked' || $this->containsAny($lower, ['drop table', 'truncate', 'delete from', 'migrate:fresh', 'db:wipe', 'rm -rf'])) {
            return $this->result('safety_risk', 'high', 'Safety risk detected', false, false, $text);
        }

        if ($this->containsAny($lower, ['sqlstate[hy000] [2002]', 'connection refused', 'could not connect', 'database file not found'])) {
            return $this->result('local_environment', 'high', 'Local environment problem', false, true, $text);
        }

        if ($this->containsAny($lower, ['laragon', 'port is already in use', 'permission denied', 'access is denied', 'file is locked', 'being used by another process'])) {
            return $this->result('local_environment', 'high', 'Local machine attention required', false, true, $text);
        }

        if ($this->containsAny($lower, ['npm_run_build', 'vite', 'next build', 'build failed', 'typescript', 'tsc', 'eslint'])) {
            return $this->result('build_failed', 'medium', 'Build validation failed', true, false, $text, 'npm run build');
        }

        if ($this->containsAny($lower, ['php_artisan_test', 'failed asserting', 'phpunit', 'tests:', 'test_failed'])) {
            return $this->result('test_failed', 'medium', 'Test validation failed', true, false, $text, 'php artisan test');
        }

        if ($this->containsAny($lower, ['migration failed', 'sql syntax', 'foreign key constraint'])) {
            return $this->result('migration_failed', 'high', 'Migration failed', false, true, $text, 'php artisan migrate');
        }

        if (($parsed['_trusted_status'] ?? true) === false || ($parsed['status'] ?? null) === 'review_required') {
            return $this->result('parser_unclear', 'medium', 'Codex result needs manual review', false, false, $text);
        }

        if ($phaseRun->status === 'failed') {
            return $this->result('validation_failed', 'medium', 'Validation failed', true, false, $text);
        }

        return $this->result('unknown', 'medium', 'Development failure requires review', false, false, $text);
    }

    private function result(string $type, string $severity, string $title, bool $canAutoFix, bool $needsUser, string $text, ?string $command = null): array
    {
        return [
            'failure_type' => $type,
            'severity' => $severity,
            'title' => $title,
            'summary' => $this->summaryFor($type, $canAutoFix, $needsUser),
            'command' => $command,
            'error_excerpt' => Str::limit($this->sanitize($text), 1800),
            'can_auto_fix' => $canAutoFix,
            'needs_user_at_system' => $needsUser,
        ];
    }

    private function summaryFor(string $type, bool $canAutoFix, bool $needsUser): string
    {
        if ($needsUser) {
            return 'This appears to require attention on the local machine before Miriam can continue.';
        }

        if ($canAutoFix) {
            return 'This looks suitable for a focused one-failure fix attempt after approval.';
        }

        return match ($type) {
            'safety_risk' => 'A safety risk was detected. Automatic fixing is blocked until manual review.',
            'parser_unclear' => 'The Codex result was unclear or missing trusted structure.',
            default => 'Manual review is required before continuing.',
        };
    }

    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function sanitize(string $text): string
    {
        return preg_replace([
            '/xox[baprs]-[A-Za-z0-9-]+/',
            '/sk-[A-Za-z0-9]{20,}/',
            '/Bearer\s+[A-Za-z0-9_\.\-]+/',
        ], ['[slack-token-redacted]', '[api-key-redacted]', 'Bearer [redacted]'], $text) ?: '';
    }
}
