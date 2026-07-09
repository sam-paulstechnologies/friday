<?php

namespace App\Http\Controllers\Agents;

use App\Http\Controllers\Controller;
use App\Models\AgentRun;
use App\Models\AgentRunLog;
use App\Models\AgentOutput;
use App\Services\Agents\TaskCaptureAgent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaskCaptureAgentController extends Controller
{
    public function index(Request $request, TaskCaptureAgent $taskCaptureAgent): Response
    {
        $agent = $taskCaptureAgent->ensureRegistered();
        $runId = $request->integer('run');

        $selectedRun = $runId
            ? AgentRun::query()
                ->with(['outputs', 'logs'])
                ->where('agent_id', $agent->id)
                ->where('user_id', $request->user()->id)
                ->find($runId)
            : AgentRun::query()
                ->with(['outputs', 'logs'])
                ->where('agent_id', $agent->id)
                ->where('user_id', $request->user()->id)
                ->latest()
                ->first();

        $recentRuns = AgentRun::query()
            ->where('agent_id', $agent->id)
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (AgentRun $run) => $this->runSummary($run))
            ->values();

        return Inertia::render('Agents/TaskCapture/Index', [
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->name,
                'slug' => $agent->slug,
                'description' => $agent->description,
                'status' => $agent->status,
            ],
            'selectedRun' => $selectedRun ? $this->runResource($selectedRun) : null,
            'recentRuns' => $recentRuns,
        ]);
    }

    public function run(Request $request, TaskCaptureAgent $taskCaptureAgent): RedirectResponse
    {
        $data = $request->validate([
            'input' => ['required', 'string', 'max:10000'],
        ]);

        $run = $taskCaptureAgent->run($request->user(), $data['input']);

        return redirect()
            ->route('agents.task-capture.index', ['run' => $run->id])
            ->with($run->status === AgentRun::STATUS_COMPLETED ? 'success' : 'error', $run->status === AgentRun::STATUS_COMPLETED
                ? 'Task Capture Agent completed.'
                : 'Task Capture Agent failed. Review the run logs.');
    }

    private function runResource(AgentRun $run): array
    {
        $run->loadMissing(['outputs', 'logs']);

        return [
            ...$this->runSummary($run),
            'original_input' => $run->original_input,
            'result' => $run->result ?? [],
            'error_message' => $run->error_message,
            'outputs' => $run->outputs
                ->map(fn (AgentOutput $output) => [
                    'id' => $output->id,
                    'category' => $output->category,
                    'detected_projects' => $output->detected_projects ?? [],
                    'priority' => $output->priority,
                    'due_label' => $output->due_label,
                    'generated_task_title' => $output->generated_task_title,
                    'suggested_next_action' => $output->suggested_next_action,
                    'payload' => $output->payload ?? [],
                ])
                ->values(),
            'logs' => $run->logs
                ->sortBy('occurred_at')
                ->map(fn (AgentRunLog $log) => [
                    'id' => $log->id,
                    'level' => $log->level,
                    'message' => $log->message,
                    'context' => $log->context ?? [],
                    'occurred_at' => $log->occurred_at?->toDateTimeString(),
                ])
                ->values(),
        ];
    }

    private function runSummary(AgentRun $run): array
    {
        $result = $run->result ?? [];

        return [
            'id' => $run->id,
            'status' => $run->status,
            'priority' => $result['priority'] ?? null,
            'due_label' => $result['due_label'] ?? null,
            'categories' => $result['categories'] ?? [],
            'detected_projects' => $result['detected_projects'] ?? [],
            'generated_task_title' => $result['generated_task_title'] ?? null,
            'suggested_next_action' => $result['suggested_next_action'] ?? null,
            'created_at' => $run->created_at?->toDateTimeString(),
            'completed_at' => $run->completed_at?->toDateTimeString(),
        ];
    }
}
