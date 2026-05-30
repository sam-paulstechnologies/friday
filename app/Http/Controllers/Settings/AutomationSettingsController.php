<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\AutomationRule;
use App\Models\Workspace;
use App\Services\Automation\AutomationRuleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AutomationSettingsController extends Controller
{
    public function index(Request $request, AutomationRuleService $automationRuleService): Response
    {
        $workspace = $this->selectedWorkspace($request);

        Gate::authorize('create', [AutomationRule::class, $workspace]);
        $automationRuleService->ensurePresets($workspace, $request->user()->id);

        return Inertia::render('Settings/Automations/Index', [
            'workspace' => $workspace->only(['id', 'name']),
            'rules' => $workspace->automationRules()
                ->withCount('runs')
                ->whereNull('archived_at')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get()
                ->map(fn (AutomationRule $rule) => $this->ruleResource($rule))
                ->values(),
            'activity' => $workspace->auditLogs()
                ->with('actor:id,name')
                ->whereIn('action', ['automation_rule_created', 'automation_rule_updated', 'automation_rule_toggled', 'automation_executed', 'automation_notification_created'])
                ->latest()
                ->limit(20)
                ->get()
                ->map(fn (AuditLog $log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'created_at' => $log->created_at?->toDateTimeString(),
                    'actor' => $log->actor?->only(['id', 'name']),
                    'metadata' => $log->metadata ?? [],
                ]),
            'triggerTypes' => AutomationRule::TRIGGERS,
            'actionTypes' => AutomationRule::ACTIONS,
        ]);
    }

    public function store(Request $request)
    {
        $workspace = $this->selectedWorkspace($request);

        Gate::authorize('create', [AutomationRule::class, $workspace]);

        $data = $this->validatedRuleData($request);
        $data['workspace_id'] = $workspace->id;
        $data['created_by'] = $request->user()->id;

        $rule = AutomationRule::create($data);
        AuditLog::record($workspace->id, $request->user()->id, 'automation_rule_created', $rule, [
            'automation_rule_id' => $rule->id,
            'automation_rule_name' => $rule->name,
        ]);

        return back()->with('success', 'Automation rule created.');
    }

    public function update(Request $request, AutomationRule $automationRule)
    {
        Gate::authorize('update', $automationRule);

        $data = $this->validatedRuleData($request);
        $automationRule->update($data);

        AuditLog::record($automationRule->workspace_id, $request->user()->id, 'automation_rule_updated', $automationRule, [
            'automation_rule_id' => $automationRule->id,
            'automation_rule_name' => $automationRule->name,
        ]);

        return back()->with('success', 'Automation rule updated.');
    }

    public function toggle(Request $request, AutomationRule $automationRule)
    {
        Gate::authorize('update', $automationRule);

        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $automationRule->update(['is_active' => $data['is_active']]);

        AuditLog::record($automationRule->workspace_id, $request->user()->id, 'automation_rule_toggled', $automationRule, [
            'automation_rule_id' => $automationRule->id,
            'automation_rule_name' => $automationRule->name,
            'is_active' => $automationRule->is_active,
        ]);

        return back()->with('success', 'Automation rule updated.');
    }

    public function archive(Request $request, AutomationRule $automationRule)
    {
        Gate::authorize('delete', $automationRule);

        $automationRule->update([
            'is_active' => false,
            'archived_at' => now(),
        ]);

        AuditLog::record($automationRule->workspace_id, $request->user()->id, 'automation_rule_archived', $automationRule, [
            'automation_rule_id' => $automationRule->id,
            'automation_rule_name' => $automationRule->name,
        ]);

        return back()->with('success', 'Automation rule archived.');
    }

    private function selectedWorkspace(Request $request): Workspace
    {
        $workspaceIds = $request->user()->accessibleWorkspaceIds();
        abort_if($workspaceIds === [], 403);

        return Workspace::query()
            ->whereIn('id', $workspaceIds)
            ->when($request->integer('workspace_id'), fn ($query, int $workspaceId) => $query->whereKey($workspaceId))
            ->orderBy('name')
            ->firstOrFail();
    }

    private function validatedRuleData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'trigger_type' => ['required', Rule::in(AutomationRule::TRIGGERS)],
            'action_type' => ['required', Rule::in(AutomationRule::ACTIONS)],
            'conditions' => ['nullable', 'array'],
            'action_payload' => ['nullable', 'array'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    private function ruleResource(AutomationRule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'description' => $rule->description,
            'trigger_type' => $rule->trigger_type,
            'action_type' => $rule->action_type,
            'conditions' => $rule->conditions ?? [],
            'action_payload' => $rule->action_payload ?? [],
            'is_active' => $rule->is_active,
            'last_run_at' => $rule->last_run_at?->toDateTimeString(),
            'runs_count' => $rule->runs_count,
        ];
    }
}
