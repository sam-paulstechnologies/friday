<?php

namespace App\Console\Commands;

use App\Models\MiriamDevelopmentLedger;
use App\Models\MiriamManagedApp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MiriamSyncAppVisions extends Command
{
    protected $signature = 'miriam:sync-app-visions {--dry-run : Report what would be synced without writing ledger rows}';

    protected $description = 'Sync managed app master vision references into the Development Manager ledger.';

    public function handle(): int
    {
        if (! Schema::hasTable('miriam_managed_apps')) {
            $this->warn('miriam_managed_apps table is missing. Run migrations before syncing app visions.');

            return self::SUCCESS;
        }

        if (! Schema::hasTable('miriam_development_ledgers')) {
            $this->warn('miriam_development_ledgers table is missing. Run migrations before syncing app visions.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $synced = 0;

        MiriamManagedApp::query()
            ->orderBy('name')
            ->each(function (MiriamManagedApp $app) use ($dryRun, &$synced): void {
                $vision = $this->visionFor($app);
                $summary = "App vision synced for {$app->name}.";

                $synced++;
                $this->line(($dryRun ? 'Would sync' : 'Syncing').": {$app->slug} - ".($vision ?: 'no vision reference recorded'));

                if ($dryRun) {
                    return;
                }

                $existing = MiriamDevelopmentLedger::query()
                    ->where('app_id', $app->id)
                    ->where('status', 'planned')
                    ->where('summary', $summary)
                    ->first();

                ($existing ?: new MiriamDevelopmentLedger())->forceFill([
                    'app_id' => $app->id,
                    'app_name' => $app->name,
                    'master_vision_reference' => $vision,
                    'status' => 'planned',
                    'summary' => $summary,
                    'next_action' => $app->config()['development_focus']['next_action'] ?? 'Review the app roadmap in Miriam.',
                ])->save();
            });

        $this->info(($dryRun ? 'Would sync' : 'Synced')." {$synced} app vision record(s).");

        return self::SUCCESS;
    }

    private function visionFor(MiriamManagedApp $app): ?string
    {
        $config = $app->config();

        return $config['master_vision_reference']
            ?? data_get($config, 'development_focus.master_vision')
            ?? $this->promptProgramSlug($app)
            ?? $app->notes;
    }

    private function promptProgramSlug(MiriamManagedApp $app): ?string
    {
        if (! $app->prompt_program_id || ! Schema::hasTable('miriam_prompt_programs')) {
            return null;
        }

        return DB::table('miriam_prompt_programs')
            ->where('id', $app->prompt_program_id)
            ->value('slug');
    }
}
