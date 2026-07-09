<?php

namespace App\Console\Commands;

use App\Models\MiriamRunnerAgent;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateMiriamRunnerAgent extends Command
{
    protected $signature = 'miriam:runner-agent:create
        {name : Human-readable runner name}
        {--user= : Optional owner user ID}
        {--path= : Optional local project path}
        {--machine= : Optional machine name}';

    protected $description = 'Create a Miriam local runner agent and show its one-time token.';

    public function handle(): int
    {
        $name = (string) $this->argument('name');
        $slug = Str::slug($name) ?: 'runner-agent';
        $baseSlug = $slug;
        $counter = 2;

        while (MiriamRunnerAgent::where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter++;
        }

        $token = 'mra_'.Str::random(80);

        $runner = MiriamRunnerAgent::create([
            'name' => $name,
            'slug' => $slug,
            'owner_user_id' => $this->option('user') ? (int) $this->option('user') : null,
            'token_hash' => MiriamRunnerAgent::hashToken($token),
            'machine_name' => $this->option('machine'),
            'local_project_path' => $this->option('path'),
            'status' => 'inactive',
        ]);

        $this->info('Miriam runner agent created.');
        $this->line('Runner ID: '.$runner->id);
        $this->line('Runner slug: '.$runner->slug);
        $this->newLine();
        $this->warn('Copy this token now. It is shown once and only the hash is stored.');
        $this->line($token);
        $this->newLine();
        $this->line('Future runner setup:');
        $this->line('MIRIAM_RUNNER_TOKEN='.$token);
        $this->line('MIRIAM_RUNNER_API_URL='.url('/api/runner'));

        return self::SUCCESS;
    }
}
