<?php

namespace App\Http\Controllers\Agents;

use App\Http\Controllers\Controller;
use App\Models\AgentOutput;
use App\Models\AgentRun;
use App\Models\AgentRunLog;
use App\Services\Agents\AgentOrchestratorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AgentOrchestratorController extends Controller
{
    public function index(Request $request, AgentOrchestratorService $orchestrator): Response
    {
        $orchestrator->ensureAgents();
        $runId = $request->integer('run');

        $selectedRun = $runId
            ? $this->baseRunQuery($request)->find($runId)
            : $this->baseRunQuery($request)->latest()->first();

        $recentRuns = $this->baseRunQuery($request)
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (AgentRun $run) => $this->runSummary($run))
            ->values();

        return Inertia::render('Agents/Orchestrator/Index', [
            'contexts' => $orchestrator->contexts(),
            'pipelineAgents' => $orchestrator->agentOptions(),
            'selectedRun' => $selectedRun ? $this->runResource($selectedRun) : null,
            'recentRuns' => $recentRuns,
            'prefillAgent' => $request->string('agent')->toString(),
        ]);
    }

    public function run(Request $request, AgentOrchestratorService $orchestrator): RedirectResponse
    {
        $data = $this->validatedRunData($request, $orchestrator, false);
        $run = $orchestrator->run($request->user(), $data);

        return redirect()
            ->route('agents.orchestrator.index', ['run' => $run->id])
            ->with($run->status === AgentRun::STATUS_FAILED ? 'error' : 'success', $run->status === AgentRun::STATUS_FAILED
                ? 'Agent OS run failed. Review logs.'
                : 'Agent OS run completed with reviewable outputs.');
    }

    public function runAgent(Request $request, AgentOrchestratorService $orchestrator): RedirectResponse
    {
        $data = $this->validatedRunData($request, $orchestrator, true);
        $run = $orchestrator->runSelectedAgent($request->user(), $data);

        return redirect()
            ->route('agents.orchestrator.index', ['run' => $run->id])
            ->with($run->status === AgentRun::STATUS_FAILED ? 'error' : 'success', $run->status === AgentRun::STATUS_FAILED
                ? 'Selected agent failed. Review logs.'
                : 'Selected agent completed with a reviewable output.');
    }

    private function validatedRunData(Request $request, AgentOrchestratorService $orchestrator, bool $selectedOnly): array
    {
        $agentKeys = collect($orchestrator->agentOptions())->pluck('key')->all();

        return $request->validate([
            'idea' => ['required', 'string', 'max:15000'],
            'context_label' => ['required', 'string', Rule::in($orchestrator->contexts())],
            'mode' => ['nullable', 'string', Rule::in(['full_pipeline', 'selected_agent'])],
            'selected_agent' => [$selectedOnly ? 'required' : 'nullable', 'string', Rule::in($agentKeys)],
            'force_continue' => ['sometimes', 'boolean'],
        ]);
    }

    private function baseRunQuery(Request $request)
    {
        return AgentRun::query()
            ->with(['agent', 'outputs', 'logs', 'childRuns.agent', 'childRuns.outputs', 'childRuns.logs'])
            ->where('user_id', $request->user()->id)
            ->whereNull('parent_run_id')
            ->whereHas('agent', fn ($query) => $query->where('slug', AgentOrchestratorService::ORCHESTRATOR));
    }

    private function runResource(AgentRun $run): array
    {
        $run->loadMissing(['agent', 'outputs', 'logs', 'childRuns.agent', 'childRuns.outputs', 'childRuns.logs']);
        $childRuns = $run->childRuns->sortBy('created_at')->values();
        $outputs = $run->outputs
            ->merge($childRuns->flatMap(fn (AgentRun $child) => $child->outputs))
            ->sortBy('created_at')
            ->values();
        $logs = $run->logs
            ->merge($childRuns->flatMap(fn (AgentRun $child) => $child->logs))
            ->sortBy('occurred_at')
            ->values();

        return [
            ...$this->runSummary($run),
            'original_input' => $run->original_input,
            'context_label' => $run->context_label,
            'mode' => $run->mode,
            'selected_agent' => $run->selected_agent,
            'result' => $run->result ?? [],
            'error_message' => $run->error_message,
            'outputs' => $outputs->map(fn (AgentOutput $output) => $this->outputResource($output))->values(),
            'childRuns' => $childRuns->map(fn (AgentRun $child) => $this->runSummary($child))->values(),
            'logs' => $logs->map(fn (AgentRunLog $log) => [
                'id' => $log->id,
                'agent' => $log->run?->agent?->name,
                'level' => $log->level,
                'message' => $log->message,
                'context' => $log->context ?? [],
                'occurred_at' => $log->occurred_at?->toDateTimeString(),
            ])->values(),
        ];
    }

    private function runSummary(AgentRun $run): array
    {
        return [
            'id' => $run->id,
            'agent_name' => $run->agent?->name,
            'status' => $run->status,
            'context_label' => $run->context_label,
            'mode' => $run->mode,
            'selected_agent' => $run->selected_agent,
            'created_at' => $run->created_at?->toDateTimeString(),
            'completed_at' => $run->completed_at?->toDateTimeString(),
            'summary' => $run->result['summary'] ?? $run->result['verdict'] ?? null,
        ];
    }

    private function outputResource(AgentOutput $output): array
    {
        $payload = $output->payload ?? [];

        return [
            'id' => $output->id,
            'agent_key' => $output->agent_key,
            'agent_name' => $output->agent_name,
            'context_label' => $output->context_label,
            'category' => $output->category,
            'title' => $output->title,
            'status' => $output->status,
            'priority' => $output->priority,
            'due_label' => $output->due_label,
            'detected_projects' => $output->detected_projects ?? [],
            'generated_task_title' => $output->generated_task_title,
            'suggested_next_action' => $output->suggested_next_action,
            'payload' => $payload,
            'markdown' => $payload['markdown'] ?? '',
            'sent_to_today_at' => $output->sent_to_today_at?->toDateTimeString(),
            'reviewed_at' => $output->reviewed_at?->toDateTimeString(),
        ];
    }
}
