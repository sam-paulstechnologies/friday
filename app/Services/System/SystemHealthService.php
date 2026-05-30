<?php

namespace App\Services\System;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SystemHealthService
{
    /**
     * @return array{overall:string, checks:array<int, array{name:string, status:string, message:string, context?:array<string, mixed>}>}
     */
    public function summary(): array
    {
        app(ConsoleKernel::class)->bootstrap();

        $checks = [
            $this->checkAppBoot(),
            $this->checkEnvironment(),
            $this->checkDatabase(),
            $this->checkRequiredTables(),
            $this->checkMigrations(),
            $this->checkWritablePath('Storage path writable', storage_path('app')),
            $this->checkWritablePath('Cache path writable', storage_path('framework/cache')),
            $this->checkQueue(),
            $this->checkSchedulerCommands(),
            $this->checkGoogleCalendarConfig(),
            $this->checkAiConfig(),
            $this->checkPublicBuild(),
            $this->checkLogging(),
        ];

        return [
            'overall' => $this->overall($checks),
            'checks' => $checks,
        ];
    }

    private function checkAppBoot(): array
    {
        return $this->pass('App boot', 'Application container booted successfully.');
    }

    private function checkEnvironment(): array
    {
        $env = (string) config('app.env');
        $debug = (bool) config('app.debug');

        if ($env === 'production' && $debug) {
            return $this->fail('Environment safety', 'APP_DEBUG should be false in production.');
        }

        if ($env !== 'production') {
            return $this->warning('Environment safety', 'Non-production environment detected. Confirm production env and debug settings before deploy.', [
                'environment' => $env,
                'debug' => $debug,
            ]);
        }

        return $this->pass('Environment safety', 'Production environment debug setting looks safe.', [
            'environment' => $env,
            'debug' => $debug,
        ]);
    }

    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();

            return $this->pass('Database connection', 'Database connection is reachable.', [
                'connection' => (string) config('database.default'),
            ]);
        } catch (Throwable $exception) {
            return $this->fail('Database connection', 'Database connection failed. Check deployment credentials and network access.');
        }
    }

    private function checkRequiredTables(): array
    {
        $tables = [
            'users',
            'workspaces',
            'workspace_users',
            'projects',
            'tasks',
            'task_comments',
            'task_activities',
            'task_attachments',
            'notifications',
            'audit_logs',
            'automation_rules',
            'calendar_connections',
            'calendar_sync_logs',
            'calendar_event_mappings',
            'ai_conversations',
            'ai_messages',
            'ai_actions',
            'daily_reviews',
        ];

        $missing = collect($tables)->reject(fn (string $table) => Schema::hasTable($table))->values()->all();

        if ($missing !== []) {
            return $this->fail('Required tables', 'Some required tables are missing.', ['missing' => $missing]);
        }

        return $this->pass('Required tables', 'Core Miriam tables are present.', ['checked' => count($tables)]);
    }

    private function checkMigrations(): array
    {
        if (! Schema::hasTable('migrations')) {
            return $this->fail('Migrations', 'The migrations table is missing.');
        }

        $files = collect(File::files(database_path('migrations')))
            ->map(fn ($file) => pathinfo($file->getFilename(), PATHINFO_FILENAME))
            ->values();

        $ran = DB::table('migrations')->pluck('migration');
        $pending = $files->diff($ran)->values();

        if ($pending->isNotEmpty()) {
            return $this->warning('Migrations', 'Pending migrations found. Run normal php artisan migrate before UAT/deploy.', [
                'pending_count' => $pending->count(),
            ]);
        }

        return $this->pass('Migrations', 'All migration files are marked as run.');
    }

    private function checkWritablePath(string $name, string $path): array
    {
        if (! File::exists($path)) {
            return $this->fail($name, 'Path does not exist.', ['path' => $path]);
        }

        if (! is_writable($path)) {
            return $this->fail($name, 'Path is not writable by the PHP process.', ['path' => $path]);
        }

        return $this->pass($name, 'Path is writable.', ['path' => $path]);
    }

    private function checkQueue(): array
    {
        $connection = (string) config('queue.default');

        if ($connection === 'sync') {
            return $this->warning('Queue', 'QUEUE_CONNECTION is sync. This is acceptable locally; production should run a durable worker.', [
                'connection' => $connection,
            ]);
        }

        return $this->pass('Queue', 'Queue connection is configured for worker-backed processing.', [
            'connection' => $connection,
        ]);
    }

    private function checkSchedulerCommands(): array
    {
        $commands = array_keys(Artisan::all());
        $required = [
            'taskflow:send-task-reminders',
            'taskflow:send-daily-briefing',
            'taskflow:send-evening-checkin',
            'miriam:run-automations',
            'miriam:sync-google-calendar',
            'miriam:health-check',
        ];

        $missing = collect($required)->reject(fn (string $command) => in_array($command, $commands, true))->values()->all();

        if ($missing !== []) {
            return $this->fail('Scheduler commands', 'One or more scheduler/ops commands are unavailable.', ['missing' => $missing]);
        }

        return $this->pass('Scheduler commands', 'Scheduler and operations commands are registered.', ['checked' => $required]);
    }

    private function checkGoogleCalendarConfig(): array
    {
        $enabled = (bool) config('services.google_calendar.enabled', false);
        $configured = $enabled
            && filled(config('services.google_calendar.client_id'))
            && filled(config('services.google_calendar.client_secret'))
            && filled(config('services.google_calendar.redirect_uri'));

        if ($enabled && ! $configured) {
            return $this->warning('Google Calendar', 'Google Calendar is enabled but OAuth configuration is incomplete.');
        }

        return $this->pass('Google Calendar', $configured ? 'Google Calendar is enabled and configured.' : 'Google Calendar is disabled or not configured.', [
            'enabled' => $enabled,
            'configured' => $configured,
        ]);
    }

    private function checkAiConfig(): array
    {
        $enabled = (bool) config('services.ai_assistant.enabled', false);
        $provider = (string) config('services.ai_assistant.provider', 'mock');

        if ($enabled && $provider !== 'mock' && blank(config('services.ai_assistant.api_key')) && blank(config('services.ai_assistant.local_endpoint'))) {
            return $this->warning('AI Assistant', 'AI is enabled with a non-mock provider but no API key or local endpoint is configured.', [
                'enabled' => $enabled,
                'provider' => $provider,
            ]);
        }

        return $this->pass('AI Assistant', $enabled ? 'AI assistant is enabled.' : 'AI assistant is disabled by default.', [
            'enabled' => $enabled,
            'provider' => $provider,
        ]);
    }

    private function checkPublicBuild(): array
    {
        $manifest = public_path('build/manifest.json');

        if (! File::exists($manifest)) {
            return $this->warning('Build assets', 'public/build/manifest.json was not found. Run npm run build before deploy.');
        }

        return $this->pass('Build assets', 'Frontend build manifest exists.');
    }

    private function checkLogging(): array
    {
        return $this->pass('Logging', 'Logging channel is configured.', [
            'channel' => (string) config('logging.default'),
            'level' => (string) config('logging.level', 'debug'),
        ]);
    }

    private function overall(array $checks): string
    {
        if (collect($checks)->contains(fn (array $check) => $check['status'] === 'failed')) {
            return 'failed';
        }

        if (collect($checks)->contains(fn (array $check) => $check['status'] === 'warning')) {
            return 'warning';
        }

        return 'passed';
    }

    private function pass(string $name, string $message, array $context = []): array
    {
        return $this->result($name, 'passed', $message, $context);
    }

    private function warning(string $name, string $message, array $context = []): array
    {
        return $this->result($name, 'warning', $message, $context);
    }

    private function fail(string $name, string $message, array $context = []): array
    {
        return $this->result($name, 'failed', $message, $context);
    }

    private function result(string $name, string $status, string $message, array $context = []): array
    {
        return array_filter([
            'name' => $name,
            'status' => $status,
            'message' => $message,
            'context' => $context,
        ], fn ($value) => $value !== []);
    }
}
