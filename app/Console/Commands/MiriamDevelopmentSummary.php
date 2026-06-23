<?php

namespace App\Console\Commands;

use App\Models\MiriamDevelopmentLedger;
use App\Models\MiriamManagedApp;
use App\Services\MiriamDevelopmentLedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MiriamDevelopmentSummary extends Command
{
    protected $signature = 'miriam:dev:summary {--app= : Optional managed app slug}';

    protected $description = 'Print a schema-safe Miriam Development Manager summary.';

    public function handle(MiriamDevelopmentLedgerService $ledger): int
    {
        $this->info('Miriam Development Manager summary');

        $requiredTables = [
            'miriam_development_ledgers',
            'miriam_development_jobs',
            'miriam_development_phase_runs',
            'miriam_development_failures',
            'miriam_development_fix_attempts',
            'miriam_managed_apps',
        ];

        $missing = collect($requiredTables)
            ->reject(fn (string $table): bool => Schema::hasTable($table))
            ->values()
            ->all();

        if ($missing !== []) {
            $this->warn('Missing Development Manager table(s): '.implode(', ', $missing));
            $this->line('Run production migrations before using the full Development Manager queue.');
        }

        if (! Schema::hasTable('miriam_development_ledgers')) {
            $this->line('Ledger: not installed.');

            return self::SUCCESS;
        }

        $appSlug = $this->option('app');
        $app = null;

        if ($appSlug && Schema::hasTable('miriam_managed_apps')) {
            $app = MiriamManagedApp::query()->where('slug', $appSlug)->first();

            if (! $app) {
                $this->warn("Managed app not found: {$appSlug}");
            }
        }

        $query = MiriamDevelopmentLedger::query()
            ->when($app, fn ($query) => $query->where('app_id', $app->id));
        $latest = (clone $query)->latest()->first();

        $this->line('Ledger records: '.$query->count());
        $this->line('Latest summary: '.($latest?->summary ?: 'none recorded'));
        $this->line('Next action: '.($latest?->next_action ?: 'none recorded'));

        if (Schema::hasTable('miriam_development_jobs')) {
            $this->line('Active jobs: '.DB::table('miriam_development_jobs')
                ->whereIn('status', ['queued', 'waiting_for_runner', 'preparing', 'running', 'waiting_for_approval', 'waiting_for_manual_fix', 'paused', 'blocked'])
                ->count());
        } else {
            $this->line('Active jobs: unavailable until miriam_development_jobs exists.');
        }

        if (Schema::hasTable('miriam_development_failures')) {
            $this->line('Active failures: '.DB::table('miriam_development_failures')
                ->whereNotIn('status', ['fixed', 'manually_fixed', 'rolled_back', 'stopped'])
                ->count());
        } else {
            $this->line('Active failures: unavailable until miriam_development_failures exists.');
        }

        $this->line('');
        $this->line($ledger->developmentSummaryText(is_string($appSlug) && $appSlug !== '' ? $appSlug : null));

        return self::SUCCESS;
    }
}
