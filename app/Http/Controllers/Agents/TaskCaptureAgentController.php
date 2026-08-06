<?php

namespace App\Http\Controllers\Agents;

use App\Http\Controllers\Controller;
use App\Models\AgentOutput;
use App\Models\AgentRun;
use App\Models\AgentRunLog;
use App\Services\Agents\TaskCaptureAgent;
use App\Services\Inbox\CaptureFailedException;
use App\Services\Inbox\WebCaptureService;
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

    /**
     * Send a parsed proposal into the shared capture pipeline.
     *
     * This replaces the old "Review as task" link, which opened an empty task
     * form and threw away everything the agent had just parsed. The proposal
     * becomes an Inbox capture that carries the original wording, so the
     * operator reviews and corrects it in the same place as every other
     * capture rather than retyping it.
     */
    public function capture(Request $request, AgentOutput $output, WebCaptureService $capture): RedirectResponse
    {
        $run = $output->run;

        // The proposal belongs to a run; the run belongs to a user.
        abort_unless($run && $run->user_id === $request->user()->id, 403);

        $originalText = trim((string) ($run->original_input ?: $output->generated_task_title));

        if ($originalText === '') {
            return back()->with('error', 'That proposal has no original text to capture.');
        }

        try {
            $result = $capture->capture(
                $request->user(),
                $originalText,
                WebCaptureService::DESTINATION_INBOX,
                // Stable per-output token: converting the same proposal twice
                // resolves to the same capture instead of a second one.
                clientToken: 'agent-output-'.$output->id,
            );
        } catch (CaptureFailedException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('inbox.show', ['task', $result['task']->id])
            ->with('success', $result['created']
                ? 'Sent to your Inbox with the original wording and the agent’s proposal.'
                : 'That proposal is already in your Inbox.');
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
