<?php

namespace App\Console\Commands;

use App\Services\System\SystemHealthService;
use Illuminate\Console\Command;

class HealthCheck extends Command
{
    protected $signature = 'miriam:health-check {--json : Output safe JSON for automation}';

    protected $description = 'Run safe Miriam production readiness checks without printing secrets.';

    public function handle(SystemHealthService $healthService): int
    {
        $summary = $healthService->summary();

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $summary['overall'] === 'failed' ? self::FAILURE : self::SUCCESS;
        }

        $this->info('Miriam health check');
        $this->line('Overall: '.$summary['overall']);
        $this->newLine();

        foreach ($summary['checks'] as $check) {
            $line = sprintf('[%s] %s - %s', strtoupper($check['status']), $check['name'], $check['message']);

            match ($check['status']) {
                'passed' => $this->line('<info>'.$line.'</info>'),
                'warning' => $this->line('<comment>'.$line.'</comment>'),
                default => $this->line('<error>'.$line.'</error>'),
            };
        }

        return $summary['overall'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
