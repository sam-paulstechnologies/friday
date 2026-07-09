<?php

namespace App\Http\Controllers\Agents;

use App\Http\Controllers\Controller;
use App\Services\Agents\AgentOrchestratorService;
use App\Services\Agents\TaskCaptureAgent;
use Inertia\Inertia;
use Inertia\Response;

class AgentIndexController extends Controller
{
    public function __invoke(AgentOrchestratorService $orchestrator, TaskCaptureAgent $taskCaptureAgent): Response
    {
        $taskCaptureAgent->ensureRegistered();

        return Inertia::render('Agents/Index', [
            'agents' => collect($orchestrator->agentOptions())
                ->prepend([
                    'key' => 'task-capture',
                    'name' => 'Task Capture Agent',
                    'description' => 'Turns messy text into structured task proposals and logs.',
                    'category' => 'capture',
                    'href' => route('agents.task-capture.index'),
                ])
                ->map(function (array $agent): array {
                    return [
                        ...$agent,
                        'href' => $agent['href'] ?? route('agents.orchestrator.index', ['agent' => $agent['key']]),
                    ];
                })
                ->values(),
        ]);
    }
}
