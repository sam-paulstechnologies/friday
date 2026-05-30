<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Ai\AiBrainService;
use Illuminate\Console\Command;

class AiAsk extends Command
{
    protected $signature = 'taskflow:ai-ask {prompt} {--scope=} {--image} {--planner} {--user_id=}';

    protected $description = 'Ask Friday AI Brain a task/workload question from the CLI.';

    public function handle(AiBrainService $aiBrain): int
    {
        $prompt = (string) $this->argument('prompt');

        if ($this->option('scope')) {
            $prompt .= ' '.(string) $this->option('scope');
        }

        $user = $this->option('user_id') ? User::find((int) $this->option('user_id')) : User::query()->oldest('id')->first();

        $answer = $aiBrain->answer($prompt, $user, [
            'source' => 'manual',
            'image' => (bool) $this->option('image'),
            'planner' => (bool) $this->option('planner'),
        ]);

        $this->line($answer['text']);

        if (! empty($answer['image_path'])) {
            $this->newLine();
            $this->line('Image: '.$answer['image_path']);
        }

        return self::SUCCESS;
    }
}
