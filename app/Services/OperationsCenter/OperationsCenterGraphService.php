<?php

namespace App\Services\OperationsCenter;

use App\Models\AgentOutput;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\CalendarConnection;
use App\Models\Decision;
use App\Models\MedicationDoseSchedule;
use App\Models\MiriamDevelopmentFailure;
use App\Models\MiriamDevelopmentJob;
use App\Models\MiriamManagedApp;
use App\Models\MiriamPromptPhase;
use App\Models\MiriamPromptProgram;
use App\Models\MiriamReminder;
use App\Models\MiriamRunnerAgent;
use App\Models\MiriamSavedPrompt;
use App\Models\Note;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WaitingItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;

class OperationsCenterGraphService
{
    public const COMPONENT_KEY = 'operations-graph-v1';

    private const CAPABILITIES = [
        'node_dragging' => true,
        'view_mode' => true,
        'edit_mode' => true,
        'node_resizing' => true,
        'explicit_layout_save' => true,
        'unsaved_change_bar' => true,
        'branch_expansion' => true,
        'branch_focus' => true,
        'visual_connect_disconnect' => true,
        'hide_without_deleting_records' => true,
        'zoom' => true,
        'pan' => true,
        'fit' => true,
        'reset' => true,
        'true_fullscreen' => true,
        'escape_to_exit_fullscreen' => true,
        'preserve_selection_in_fullscreen' => true,
        'preserve_zoom_and_pan' => true,
        'minimap' => true,
        'search' => true,
        'filters' => true,
        'selected_node_details' => true,
        'independent_details_scroll' => true,
        'expand_details' => true,
        'mobile_drawer' => true,
        'browser_local_layout_persistence' => true,
        'personal_layout_persistence' => true,
        'workspace_layout_persistence' => false,
        'progressive_loading' => true,
        'backend_layout_persistence' => false,
    ];

    public function graph(User $user, string $view, array $expanded = []): array
    {
        return match ($view) {
            'mind-map' => $this->mindMap($user),
            'technical-map' => $this->technicalMap($user),
            'agent-orchestrator' => $this->agentOrchestrator($user),
            default => $this->journeyFlow($user, $expanded),
        };
    }

    public function journeyFlow(User $user, array $expanded = []): array
    {
        $counts = $this->counts($user);
        $expanded = collect($expanded)->filter()->map(fn ($value) => (string) $value)->values()->all();
        $expandAll = in_array('*', $expanded, true);
        $isExpanded = fn (string $id): bool => $expandAll || in_array($id, $expanded, true);

        $nodes = [
            $this->node('capture', 'Capture', 'Dictation-first intake', 'capture', 700, 80, 'rounded', 'purple', 'Implemented', [
                'primary' => true,
                'trunk' => true,
                'display_size' => 'standard',
                'route_name' => 'agents.task-capture.index',
                'route' => $this->routeIfExists('agents.task-capture.index'),
                'description' => 'Capture accepts messy natural language before category decisions are required.',
                'summary' => 'Capture is the heart of Miriam: get the thought out first, then let Miriam structure it.',
                'owner' => 'You',
                'entry_criteria' => 'A rough idea, dictation, note, instruction, task, reminder, or decision exists.',
                'exit_criteria' => 'The capture exists safely without forcing categorisation first.',
                'next_step' => 'Understand',
                'input' => 'Voice dictation or typed text.',
                'output' => 'Captured item awaiting understanding and classification.',
            ]),
            $this->node('understand', 'Understand', 'Interpret and extract', 'process', 700, 300, 'rounded', 'blue', 'Implemented', [
                'trunk' => true,
                'child_count' => 1,
                'children_label' => 'clarification path',
                'route_name' => 'assistant.index',
                'route' => $this->routeIfExists('assistant.index'),
                'description' => 'Assistant and rule-based agents interpret raw input into safer structured intent.',
                'entry_criteria' => 'A captured item is available.',
                'exit_criteria' => 'Miriam has enough signal to classify it or ask for clarification.',
                'next_step' => 'Classify, or clarify when required.',
            ]),
            $this->node('clarify', 'Clarify when required', 'Ask for missing context', 'review', 360, 300, 'rounded', 'amber', 'Not Connected', [
                'parent_id' => 'understand',
                'branch_direction' => 'left',
                'description' => 'Clarification models exist for Slack context, but no verified web clarification queue route is currently registered.',
                'entry_criteria' => 'The capture cannot be understood safely.',
                'exit_criteria' => 'The missing context is supplied.',
                'next_step' => 'Return to Understand.',
            ]),
            $this->node('classify', 'Classify', 'What is this?', 'process', 700, 520, 'rounded', 'purple', 'Implemented', [
                'trunk' => true,
                'child_count' => 9,
                'children_label' => '9 types',
                'route_name' => 'agents.task-capture.index',
                'route' => $this->routeIfExists('agents.task-capture.index'),
                'description' => 'Task Capture Agent classifies messy input without calling external AI APIs.',
                'entry_criteria' => 'The captured item is understood well enough to route.',
                'exit_criteria' => 'The item has a record type or a safe review queue.',
                'next_step' => 'Create or update an appropriate record.',
            ]),
            $this->node('records', 'Create or Update Record', 'Real Miriam entities', 'record', 700, 740, 'rounded', 'purple', 'Implemented', [
                'trunk' => true,
                'child_count' => 8,
                'children_label' => $counts['records'].' records',
                'description' => 'Implemented record targets include tasks, projects, decisions, waiting items, approvals, notes, reminders, and agent outputs.',
                'count' => $counts['records'],
                'entry_criteria' => 'The classified item has a safe target entity.',
                'exit_criteria' => 'A Miriam record or review proposal exists.',
                'next_step' => 'Organise.',
            ]),
            $this->node('organise', 'Organise', 'Projects and work areas', 'process', 700, 960, 'rounded', 'blue', 'Implemented', [
                'trunk' => true,
                'child_count' => 4,
                'children_label' => 'work areas',
                'route_name' => 'projects.index',
                'route' => $this->routeIfExists('projects.index'),
                'count' => $counts['projects'],
                'entry_criteria' => 'A record exists and may need a work area.',
                'exit_criteria' => 'The record has a usable place in Miriam.',
                'next_step' => 'Prioritise.',
            ]),
            $this->node('prioritise', 'Prioritise', 'Today and review queues', 'decision', 700, 1180, 'rounded', 'amber', 'Implemented', [
                'trunk' => true,
                'child_count' => 4,
                'children_label' => $counts['today_attention'].' need attention',
                'route_name' => 'today.index',
                'route' => $this->routeIfExists('today.index'),
                'count' => $counts['today_attention'],
                'entry_criteria' => 'The item is organised enough to compare with other work.',
                'exit_criteria' => 'Miriam knows whether it belongs now, later, waiting, or blocked.',
                'next_step' => 'Schedule, delegate, or queue.',
            ]),
            $this->node('schedule-delegate-queue', 'Schedule, Delegate or Queue', 'Calendar, waiting, approvals', 'action', 700, 1400, 'rounded', 'cyan', 'Implemented', [
                'trunk' => true,
                'child_count' => 5,
                'children_label' => 'routing choices',
                'route_name' => 'planner.index',
                'route' => $this->routeIfExists('planner.index'),
                'count' => $counts['waiting'] + $counts['approvals'],
                'entry_criteria' => 'The item has a priority and needs a next container.',
                'exit_criteria' => 'The item is scheduled, delegated, queued, or awaiting approval.',
                'next_step' => 'Execute.',
            ]),
            $this->node('execute', 'Execute', 'Do the work', 'process', 700, 1620, 'rounded', 'blue', 'Implemented', [
                'trunk' => true,
                'child_count' => 3,
                'children_label' => $counts['active_tasks'].' active',
                'route_name' => 'today.index',
                'route' => $this->routeIfExists('today.index'),
                'count' => $counts['active_tasks'],
                'entry_criteria' => 'The item is ready to be acted on.',
                'exit_criteria' => 'There is an outcome to validate.',
                'next_step' => 'Review.',
            ]),
            $this->node('review', 'Review', 'Validate outcome', 'review', 700, 1840, 'rounded', 'yellow', 'Implemented', [
                'trunk' => true,
                'child_count' => 4,
                'children_label' => 'review paths',
                'route_name' => 'task-review.index',
                'route' => $this->routeIfExists('task-review.index'),
                'count' => $counts['agent_outputs_waiting'],
                'entry_criteria' => 'Work has a result or proposal.',
                'exit_criteria' => 'The outcome is approved, corrected, retried, or moved to follow-up.',
                'next_step' => 'Follow up when required.',
            ]),
            $this->node('follow-up', 'Follow Up when required', 'Waiting and reminders', 'action', 700, 2060, 'rounded', 'cyan', 'Implemented', [
                'trunk' => true,
                'child_count' => 3,
                'children_label' => $counts['waiting'] + $counts['reminders'].' open',
                'route_name' => 'waiting.index',
                'route' => $this->routeIfExists('waiting.index'),
                'description' => 'Miriam has Waiting and Reminder records. A separate Follow-up model is not verified.',
                'count' => $counts['waiting'] + $counts['reminders'],
                'entry_criteria' => 'The outcome needs another person, time, or response loop.',
                'exit_criteria' => 'The follow-up is resolved or archived.',
                'next_step' => 'Complete or archive.',
            ]),
            $this->node('complete', 'Complete or Archive', 'Done state', 'end', 700, 2280, 'rounded', 'green', 'Implemented', [
                'trunk' => true,
                'route_name' => 'tasks.index',
                'route' => $this->routeIfExists('tasks.index'),
                'count' => $counts['completed_tasks'],
                'entry_criteria' => 'The work is reviewed and no active follow-up remains.',
                'exit_criteria' => 'The record is completed or archived without deleting history.',
                'next_step' => 'Capture the next thing.',
            ]),
        ];

        $edges = [
            $this->edge('capture', 'understand'),
            $this->edge('understand', 'clarify', 'Needs context', 'amber'),
            $this->edge('understand', 'classify', 'Clear enough'),
            $this->edge('classify', 'records'),
            $this->edge('records', 'organise'),
            $this->edge('organise', 'prioritise'),
            $this->edge('prioritise', 'schedule-delegate-queue'),
            $this->edge('schedule-delegate-queue', 'execute'),
            $this->edge('execute', 'review'),
            $this->edge('review', 'follow-up', 'Needs follow-up', 'cyan'),
            $this->edge('follow-up', 'complete', 'Resolved'),
        ];

        [$nodes, $edges] = $this->appendJourneyBranches($nodes, $edges, $counts, $isExpanded);

        return $this->graphEnvelope($user, 'journey-flow', 'Miriam Capture Journey Flow', 'Capture-first operating model for Miriam.', 'capture', $nodes, $edges, [
            'localStorageKey' => 'miriam.operations.journey',
            'layout' => [
                'orientation' => 'top-to-bottom',
                'primary_axis' => 'vertical',
                'primary_trunk' => ['capture', 'understand', 'classify', 'records', 'organise', 'prioritise', 'schedule-delegate-queue', 'execute', 'review', 'follow-up', 'complete'],
                'initial_node_count' => 12,
                'expanded' => $expanded,
            ],
            'canvas' => ['width' => 1700, 'height' => 2700],
            'summary' => $this->summary($counts),
        ]);
    }

    public function mindMap(User $user): array
    {
        $counts = $this->counts($user);
        $nodes = [
            $this->node('miriam', 'Miriam', 'Command Center OS', 'root', 760, 260, 'circle', 'purple', 'Implemented', ['primary' => true]),
            $this->node('capture', 'Capture', 'Heart of Miriam', 'capture', 500, 260, 'circle', 'purple', 'Implemented', [
                'primary' => true,
                'route_name' => 'agents.task-capture.index',
                'route' => $this->routeIfExists('agents.task-capture.index'),
                'description' => 'Capture stays visually primary and does not require mandatory categorisation before input.',
            ]),
            $this->node('assistant', 'Assistant', 'Conversational help', 'process', 260, 160, 'rounded', 'blue', 'Implemented', ['route_name' => 'assistant.index', 'route' => $this->routeIfExists('assistant.index')]),
            $this->node('inbox', 'Inbox', 'Notifications inbox', 'review', 260, 360, 'rounded', 'yellow', 'Implemented', ['route_name' => 'notifications.index', 'route' => $this->routeIfExists('notifications.index')]),
            $this->node('today', 'Today', 'Urgency center', 'review', 760, 50, 'rounded', 'amber', 'Implemented', ['route_name' => 'today.index', 'route' => $this->routeIfExists('today.index'), 'count' => $counts['today_attention']]),
            $this->node('tasks', 'Tasks', 'Execution records', 'record', 1030, 90, 'rounded', 'purple', 'Implemented', ['route_name' => 'tasks.index', 'route' => $this->routeIfExists('tasks.index'), 'count' => $counts['active_tasks']]),
            $this->node('projects', 'Projects', 'Work containers', 'record', 1200, 260, 'rounded', 'purple', 'Implemented', ['route_name' => 'projects.index', 'route' => $this->routeIfExists('projects.index'), 'count' => $counts['projects']]),
            $this->node('decisions', 'Decisions', 'Decision records', 'record', 1030, 430, 'rounded', 'purple', 'Implemented', ['route_name' => 'decisions.index', 'route' => $this->routeIfExists('decisions.index'), 'count' => $counts['decisions']]),
            $this->node('waiting', 'Waiting', 'Follow-up queue', 'review', 760, 560, 'rounded', 'yellow', 'Implemented', ['route_name' => 'waiting.index', 'route' => $this->routeIfExists('waiting.index'), 'count' => $counts['waiting']]),
            $this->node('notes', 'Notes', 'Reference capture', 'record', 500, 560, 'rounded', 'purple', 'Implemented', ['route_name' => 'notes.index', 'route' => $this->routeIfExists('notes.index'), 'count' => $counts['notes']]),
            $this->node('calendar', 'Calendar', 'Schedule view', 'action', 1290, 450, 'rounded', 'cyan', 'Implemented', ['route_name' => 'calendar.index', 'route' => $this->routeIfExists('calendar.index'), 'count' => $counts['calendar_connections']]),
            $this->node('approvals', 'Approvals', 'Approval queue', 'review', 1290, 70, 'rounded', 'yellow', 'Implemented', ['route_name' => 'approvals.index', 'route' => $this->routeIfExists('approvals.index'), 'count' => $counts['approvals']]),
            $this->node('agents', 'Agents', 'Review-only agents', 'process', 1480, 250, 'rounded', 'blue', 'Implemented', ['route_name' => 'agents.index', 'route' => $this->routeIfExists('agents.index'), 'count' => $counts['agent_outputs_waiting']]),
            $this->node('agent-orchestrator', 'Agent Orchestrator', 'Developer pipeline', 'process', 1660, 160, 'rounded', 'blue', 'Implemented', ['route_name' => 'agents.orchestrator.index', 'route' => $this->routeIfExists('agents.orchestrator.index')]),
            $this->node('prompt-os', 'Prompt OS', 'Prompt programs and phases', 'process', 1660, 340, 'rounded', 'slate', 'Not Connected', ['count' => $counts['prompt_programs'] + $counts['prompt_phases'] + $counts['saved_prompts']]),
            $this->node('development-manager', 'Development Manager', 'Runner jobs and failures', 'process', 1850, 250, 'rounded', 'slate', 'Not Connected', ['count' => $counts['development_jobs'] + $counts['development_failures']]),
        ];

        $edges = [
            $this->edge('miriam', 'capture'),
            $this->edge('capture', 'assistant'),
            $this->edge('capture', 'inbox'),
            $this->edge('miriam', 'today'),
            $this->edge('miriam', 'tasks'),
            $this->edge('miriam', 'projects'),
            $this->edge('miriam', 'decisions'),
            $this->edge('miriam', 'waiting'),
            $this->edge('miriam', 'notes'),
            $this->edge('miriam', 'calendar'),
            $this->edge('miriam', 'approvals'),
            $this->edge('miriam', 'agents'),
            $this->edge('agents', 'agent-orchestrator'),
            $this->edge('agents', 'prompt-os'),
            $this->edge('prompt-os', 'development-manager'),
            $this->edge('agent-orchestrator', 'development-manager'),
        ];

        return $this->graphEnvelope($user, 'mind-map', 'Miriam Mind Map', 'Implemented Miriam entities arranged around capture-first work.', 'capture', $nodes, $edges, [
            'localStorageKey' => 'miriam.operations.mind',
            'layout' => [
                'orientation' => 'radial',
                'primary_axis' => 'capture-first',
            ],
            'canvas' => ['width' => 2100, 'height' => 900],
            'summary' => $this->summary($counts),
        ]);
    }

    public function technicalMap(User $user): array
    {
        abort_unless($this->canViewTechnicalMap($user), 403);

        $nodes = [
            $this->node('page-operations', 'Operations Center page', 'OperationsCenter/Index', 'page', 80, 80, 'rounded', 'purple', 'Implemented'),
            $this->node('route-operations', 'operations-center.index', 'GET /operations-center', 'route', 340, 80, 'rounded', 'cyan', 'Implemented'),
            $this->node('controller-operations', 'OperationsCenterController', 'Graph page and JSON endpoints', 'controller', 620, 80, 'rounded', 'blue', 'Implemented'),
            $this->node('service-operations', 'OperationsCenterGraphService', 'Sanitized graph summaries', 'service', 920, 80, 'rounded', 'blue', 'Implemented'),
            $this->node('page-orchestrator', 'Agent Orchestrator page', 'Agents/Orchestrator/Index', 'page', 80, 250, 'rounded', 'purple', 'Implemented'),
            $this->node('route-orchestrator', 'agents.orchestrator.index', 'GET /agents/orchestrator', 'route', 340, 250, 'rounded', 'cyan', 'Implemented'),
            $this->node('controller-orchestrator', 'AgentOrchestratorController', 'Run selection and review actions', 'controller', 620, 250, 'rounded', 'blue', 'Implemented'),
            $this->node('service-orchestrator', 'AgentOrchestratorService', 'Rule-based pipeline execution', 'service', 920, 250, 'rounded', 'blue', 'Implemented'),
            $this->node('page-task-capture', 'Task Capture page', 'Agents/TaskCapture/Index', 'page', 80, 420, 'rounded', 'purple', 'Implemented'),
            $this->node('route-task-capture', 'agents.task-capture.index', 'GET /agents/task-capture', 'route', 340, 420, 'rounded', 'cyan', 'Implemented'),
            $this->node('controller-task-capture', 'TaskCaptureAgentController', 'Capture form and run action', 'controller', 620, 420, 'rounded', 'blue', 'Implemented'),
            $this->node('service-task-capture', 'TaskCaptureAgent', 'Rule-based classification', 'service', 920, 420, 'rounded', 'blue', 'Implemented'),
            $this->node('page-today', 'Today page', 'Today/Index', 'page', 80, 590, 'rounded', 'purple', 'Implemented'),
            $this->node('route-today', 'today.index', 'GET /today', 'route', 340, 590, 'rounded', 'cyan', 'Implemented'),
            $this->node('controller-today', 'TodayController', 'Today command data', 'controller', 620, 590, 'rounded', 'blue', 'Implemented'),
            $this->node('service-today', 'TodayCommandCenterService', 'Urgency scoring and grouping', 'service', 920, 590, 'rounded', 'blue', 'Implemented'),
            $this->node('model-agent-run', 'AgentRun', 'Model', 'model', 1210, 250, 'rounded', 'yellow', 'Implemented'),
            $this->node('model-agent-output', 'AgentOutput', 'Model', 'model', 1210, 340, 'rounded', 'yellow', 'Implemented'),
            $this->node('model-task', 'Task', 'Model', 'model', 1210, 530, 'rounded', 'yellow', 'Implemented'),
            $this->node('model-approval', 'Approval', 'Model', 'model', 1210, 620, 'rounded', 'yellow', 'Implemented'),
            $this->node('table-agent-runs', 'agent_runs', 'Table', 'table', 1480, 250, 'rounded', 'green', 'Implemented'),
            $this->node('table-agent-outputs', 'agent_outputs', 'Table', 'table', 1480, 340, 'rounded', 'green', 'Implemented'),
            $this->node('table-tasks', 'tasks', 'Table', 'table', 1480, 530, 'rounded', 'green', 'Implemented'),
            $this->node('table-approvals', 'approvals', 'Table', 'table', 1480, 620, 'rounded', 'green', 'Implemented'),
        ];

        $edges = [
            $this->edge('page-operations', 'route-operations'),
            $this->edge('route-operations', 'controller-operations'),
            $this->edge('controller-operations', 'service-operations'),
            $this->edge('page-orchestrator', 'route-orchestrator'),
            $this->edge('route-orchestrator', 'controller-orchestrator'),
            $this->edge('controller-orchestrator', 'service-orchestrator'),
            $this->edge('service-orchestrator', 'model-agent-run'),
            $this->edge('service-orchestrator', 'model-agent-output'),
            $this->edge('model-agent-run', 'table-agent-runs'),
            $this->edge('model-agent-output', 'table-agent-outputs'),
            $this->edge('page-task-capture', 'route-task-capture'),
            $this->edge('route-task-capture', 'controller-task-capture'),
            $this->edge('controller-task-capture', 'service-task-capture'),
            $this->edge('service-task-capture', 'model-agent-run'),
            $this->edge('service-task-capture', 'model-agent-output'),
            $this->edge('page-today', 'route-today'),
            $this->edge('route-today', 'controller-today'),
            $this->edge('controller-today', 'service-today'),
            $this->edge('service-today', 'model-task'),
            $this->edge('service-today', 'model-approval'),
            $this->edge('service-today', 'model-agent-output'),
            $this->edge('model-task', 'table-tasks'),
            $this->edge('model-approval', 'table-approvals'),
        ];

        return $this->graphEnvelope($user, 'technical-map', 'Miriam Technical Map', 'Verified page to route to controller to service to model to table traces.', 'page-operations', $nodes, $edges, [
            'localStorageKey' => 'miriam.operations.technical',
            'layout' => [
                'orientation' => 'left-to-right',
                'layers' => ['Page', 'Route', 'Controller', 'Service', 'Model', 'Table'],
                'read_only_trace' => true,
            ],
            'canvas' => ['width' => 1800, 'height' => 900],
            'summary' => [
                ['label' => 'Verified traces', 'value' => 3, 'tone' => 'green'],
                ['label' => 'Source paths', 'value' => 'Hidden', 'tone' => 'slate'],
                ['label' => 'Access', 'value' => 'Owner/Admin', 'tone' => 'amber'],
            ],
        ]);
    }

    public function agentOrchestrator(User $user): array
    {
        $latestRun = AgentRun::query()
            ->with('agent')
            ->where('user_id', $user->id)
            ->whereNull('parent_run_id')
            ->latest()
            ->first();

        $nodes = [
            $this->node('agent-orchestrator', 'Agent Orchestrator', 'Coordinates review-only runs', 'process', 220, 210, 'circle', 'purple', $latestRun?->status ?? 'idle', [
                'primary' => true,
                'route_name' => 'agents.orchestrator.index',
                'route' => $this->routeIfExists('agents.orchestrator.index'),
            ]),
            $this->node('research', 'Research Agent', 'Rule-based research brief', 'process', 520, 90, 'rounded', 'blue', 'Implemented', ['agent_key' => 'research']),
            $this->node('idea-validation', 'Idea Validation Agent', 'Build/defer verdict', 'decision', 760, 90, 'diamond', 'amber', 'Implemented', ['agent_key' => 'idea-validation']),
            $this->node('prd-md', 'PRD / MD Agent', 'Markdown product spec', 'record', 1000, 90, 'rounded', 'purple', 'Implemented', ['agent_key' => 'prd-md']),
            $this->node('resource-manager', 'Resource Manager Agent', 'Tool and effort routing', 'process', 520, 330, 'rounded', 'blue', 'Implemented', ['agent_key' => 'resource-manager']),
            $this->node('codex-claude-prompt', 'Codex / Claude Prompt Agent', 'Execution prompts', 'record', 760, 330, 'rounded', 'purple', 'Implemented', ['agent_key' => 'codex-claude-prompt']),
            $this->node('test-plan', 'Test Plan Agent', 'Validation plan', 'review', 1000, 330, 'rounded', 'yellow', 'Implemented', ['agent_key' => 'test-plan']),
            $this->node('ui-ux-marketing', 'UI/UX + Marketing Agent', 'UX plan and offer', 'record', 1240, 210, 'rounded', 'cyan', 'Implemented', ['agent_key' => 'ui-ux-marketing']),
            $this->node('today-review', 'Today review queue', 'Waiting on me', 'review', 1500, 210, 'rounded', 'yellow', 'Implemented', ['route_name' => 'today.index', 'route' => $this->routeIfExists('today.index')]),
        ];

        $edges = [
            $this->edge('agent-orchestrator', 'research'),
            $this->edge('research', 'idea-validation'),
            $this->edge('idea-validation', 'prd-md', 'If promising'),
            $this->edge('agent-orchestrator', 'resource-manager'),
            $this->edge('resource-manager', 'codex-claude-prompt'),
            $this->edge('codex-claude-prompt', 'test-plan'),
            $this->edge('test-plan', 'ui-ux-marketing'),
            $this->edge('ui-ux-marketing', 'today-review', 'Needs review'),
            $this->edge('prd-md', 'today-review', 'Needs review'),
        ];

        return $this->graphEnvelope($user, 'agent-orchestrator', 'Agent Orchestrator Developer Flow', 'Review-only Agent OS pipeline for controlled build planning.', 'agent-orchestrator', $nodes, $edges, [
            'localStorageKey' => 'miriam.operations.agent-orchestrator',
            'layout' => [
                'orientation' => 'left-to-right',
                'primary_axis' => 'pipeline',
            ],
            'canvas' => ['width' => 1850, 'height' => 800],
            'summary' => [
                ['label' => 'Pipeline agents', 'value' => 8, 'tone' => 'blue'],
                ['label' => 'Latest run', 'value' => $latestRun?->status ?? 'None', 'tone' => $latestRun?->status === 'failed' ? 'red' : 'slate'],
                ['label' => 'Review mode', 'value' => 'Proposals only', 'tone' => 'green'],
            ],
        ]);
    }

    public function nodeDetails(User $user, string $view, string $nodeId): array
    {
        if ($view === 'technical-map') {
            abort_unless($this->canViewTechnicalMap($user), 403);
        }

        $graph = $this->graph($user, $view, ['*']);
        $node = collect($graph['nodes'])->firstWhere('id', $nodeId);
        abort_unless($node, 404);

        return [
            'id' => $node['id'],
            'title' => $node['title'],
            'subtitle' => $node['subtitle'] ?? '',
            'status' => $node['status'] ?? 'Not Verified',
            'description' => $node['description'] ?? $node['summary'] ?? 'No detailed record data is loaded in the initial payload.',
            'details' => [
                ['label' => 'View', 'value' => $graph['title']],
                ['label' => 'Category', 'value' => $node['category'] ?? 'flow'],
                ['label' => 'Connection', 'value' => $node['route'] ? 'Connected route' : ($node['status'] ?? 'Not Connected')],
            ],
            'privacy_note' => 'Details are sanitized. Raw capture text, private decisions, health data, credentials, logs, file paths, and source excerpts are not included here.',
            'loaded_at' => now()->toDateTimeString(),
        ];
    }

    public function canViewTechnicalMap(User $user): bool
    {
        return collect($user->accessibleWorkspaceIds())
            ->contains(fn (int $workspaceId) => $user->canManageWorkspace($workspaceId));
    }

    private function appendJourneyBranches(array $nodes, array $edges, array $counts, callable $isExpanded): array
    {
        $branchGroups = [
            'classify' => [
                'direction' => 'left',
                'nodes' => [
                    ['classify-task', 'Task', 'Execution work', 'record', 'tasks.index', $counts['active_tasks']],
                    ['classify-project', 'Project', 'Work container', 'record', 'projects.index', $counts['projects']],
                    ['classify-decision', 'Decision', 'Choice to make', 'decision', 'decisions.index', $counts['decisions']],
                    ['classify-reminder', 'Reminder', 'Time-based nudge', 'review', null, $counts['reminders']],
                    ['classify-waiting', 'Waiting', 'External dependency', 'review', 'waiting.index', $counts['waiting']],
                    ['classify-note', 'Note', 'Reference item', 'record', 'notes.index', $counts['notes']],
                    ['classify-approval', 'Approval', 'Needs a yes/no', 'review', 'approvals.index', $counts['approvals']],
                    ['classify-agent', 'Dev Instruction', 'Agent or prompt run', 'process', 'agents.orchestrator.index', $counts['agent_outputs_waiting']],
                    ['classify-health', 'Personal / Health', 'Health routine item', 'action', 'health.index', $counts['medication_schedules']],
                ],
            ],
            'records' => [
                'direction' => 'right',
                'nodes' => [
                    ['record-task', 'Task record', 'tasks table', 'record', 'tasks.index', $counts['active_tasks']],
                    ['record-project', 'Project record', 'projects table', 'record', 'projects.index', $counts['projects']],
                    ['record-decision', 'Decision record', 'decisions table', 'decision', 'decisions.index', $counts['decisions']],
                    ['record-waiting', 'Waiting item', 'waiting queue', 'review', 'waiting.index', $counts['waiting']],
                    ['record-approval', 'Approval item', 'approval queue', 'review', 'approvals.index', $counts['approvals']],
                    ['record-note', 'Note record', 'notes table', 'record', 'notes.index', $counts['notes']],
                    ['record-reminder', 'Reminder record', 'Miriam reminders', 'review', null, $counts['reminders']],
                    ['record-agent-output', 'Agent output', 'reviewable proposal', 'process', 'agents.orchestrator.index', $counts['agent_outputs_waiting']],
                ],
            ],
            'organise' => [
                'direction' => 'left',
                'nodes' => [
                    ['organise-projects', 'Projects', 'project containers', 'record', 'projects.index', $counts['projects']],
                    ['organise-portfolios', 'Products / Portfolios', 'product groupings', 'record', 'portfolios.index', $counts['managed_apps']],
                    ['organise-areas', 'Areas', 'life/work areas', 'record', 'areas.index', 0],
                    ['organise-notes', 'Notes', 'reference material', 'record', 'notes.index', $counts['notes']],
                ],
            ],
            'prioritise' => [
                'direction' => 'right',
                'nodes' => [
                    ['priority-today', 'Today', 'urgent work', 'review', 'today.index', $counts['today_attention']],
                    ['priority-task-review', 'Task Review', 'triage changes', 'review', 'task-review.index', 0],
                    ['priority-inbox', 'Inbox', 'triage captures', 'decision', 'inbox.index', 0],
                    ['priority-blockers', 'Blockers', 'blocked items', 'review', 'blockers.index', 0],
                ],
            ],
            'schedule-delegate-queue' => [
                'direction' => 'left',
                'nodes' => [
                    ['route-calendar', 'Schedule', 'calendar / planner', 'action', 'calendar.index', $counts['calendar_connections']],
                    ['route-waiting', 'Delegate / Waiting', 'external dependency', 'review', 'waiting.index', $counts['waiting']],
                    ['route-approval', 'Queue Approval', 'needs decision', 'review', 'approvals.index', $counts['approvals']],
                    ['route-agent', 'Create Agent Run', 'review-only agent work', 'process', 'agents.orchestrator.index', $counts['agent_outputs_waiting']],
                    ['route-later', 'Later Queue', 'safe backlog', 'review', 'tasks.index', $counts['active_tasks']],
                ],
            ],
            'execute' => [
                'direction' => 'right',
                'nodes' => [
                    ['execute-today', 'Today execution', 'do now', 'process', 'today.index', $counts['today_attention']],
                    ['execute-agent', 'Agent run', 'proposal pipeline', 'process', 'agents.orchestrator.index', $counts['agent_outputs_waiting']],
                    ['execute-assistant', 'Assistant', 'guided action', 'process', 'assistant.index', 0],
                ],
            ],
            'review' => [
                'direction' => 'left',
                'nodes' => [
                    ['review-outcome', 'Outcome review', 'validate work', 'review', 'task-review.index', 0],
                    ['review-approval', 'Approval review', 'approve or reject', 'review', 'approvals.index', $counts['approvals']],
                    ['review-agent-output', 'Agent output review', 'needs review', 'review', 'agents.orchestrator.index', $counts['agent_outputs_waiting']],
                    ['review-retry', 'Retry / unblock', 'fix or reschedule', 'action', 'blockers.index', 0],
                ],
            ],
            'follow-up' => [
                'direction' => 'right',
                'nodes' => [
                    ['follow-waiting', 'Waiting follow-up', 'someone else owes input', 'review', 'waiting.index', $counts['waiting']],
                    ['follow-reminder', 'Reminder', 'time-based nudge', 'review', null, $counts['reminders']],
                    ['follow-calendar', 'Calendar follow-up', 'scheduled callback', 'action', 'calendar.index', $counts['calendar_connections']],
                ],
            ],
        ];

        foreach ($branchGroups as $parentId => $group) {
            if (! $isExpanded($parentId)) {
                continue;
            }

            $parent = collect($nodes)->firstWhere('id', $parentId);
            if (! $parent) {
                continue;
            }

            $direction = $group['direction'];
            $x = $direction === 'left' ? 300 : 1110;
            $startY = max(40, (int) $parent['position']['y'] - ((count($group['nodes']) - 1) * 58));

            foreach ($group['nodes'] as $index => [$id, $title, $subtitle, $category, $routeName, $count]) {
                $nodes[] = $this->node($id, $title, $subtitle, $category, $x, $startY + ($index * 116), 'rounded', $this->toneForCategory($category), $routeName ? 'Implemented' : 'Not Connected', [
                    'parent_id' => $parentId,
                    'branch_direction' => $direction,
                    'count' => $count,
                    'route_name' => $routeName,
                    'route' => $routeName ? $this->routeIfExists($routeName) : null,
                    'description' => $routeName
                        ? $title.' is connected to a verified Miriam route.'
                        : $title.' is represented in Miriam data, but no verified page route is registered yet.',
                    'entry_criteria' => 'The parent stage sends work into this branch.',
                    'exit_criteria' => 'The branch produces a safe next state without changing records from the canvas.',
                    'next_step' => 'Return to the primary journey trunk.',
                ]);

                $edges[] = $this->edge($parentId, $id, $index === 0 ? 'branch' : null, $direction === 'left' ? 'amber' : 'cyan');
            }
        }

        return [$nodes, $edges];
    }

    private function toneForCategory(string $category): string
    {
        return match ($category) {
            'decision' => 'amber',
            'review' => 'yellow',
            'action' => 'cyan',
            'end' => 'green',
            'record' => 'purple',
            default => 'blue',
        };
    }

    private function graphEnvelope(User $user, string $view, string $title, string $subtitle, string $rootId, array $nodes, array $edges, array $overrides = []): array
    {
        $canManageWorkspace = $this->canViewTechnicalMap($user);

        return [
            'component_key' => self::COMPONENT_KEY,
            'view' => $view,
            'title' => $title,
            'subtitle' => $subtitle,
            'rootId' => $rootId,
            'selectedId' => $rootId,
            'nodes' => $nodes,
            'edges' => $edges,
            'legend' => $this->legend(),
            'filters' => $this->filters(),
            'capabilities' => self::CAPABILITIES,
            'progressive' => [
                'initial_payload' => 'root, primary branches, and summary statuses',
                'details_loaded_on_demand' => true,
                'children_loaded_on_demand' => true,
                'technical_traces_loaded_on_demand' => $view !== 'technical-map',
                'logs_loaded_on_demand' => true,
            ],
            'endpoints' => [
                'graph' => $this->routeIfExists('operations-center.graph', ['view' => $view]),
                'details' => $this->routeIfExists('operations-center.nodes.show', ['view' => $view, 'node' => '__NODE__']),
            ],
            'permissions' => [
                'technical_map' => $this->canViewTechnicalMap($user),
                'personal_layout' => true,
                'workspace_layout_publish' => false,
                'shared_catalogue_editing' => $canManageWorkspace,
                'technical_map_editing' => $view === 'technical-map' ? $canManageWorkspace : true,
                'custom_node_delete' => $canManageWorkspace,
            ],
            ...$overrides,
        ];
    }

    private function node(string $id, string $title, string $subtitle, string $category, int $x, int $y, string $shape, string $tone, string $status, array $extra = []): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'subtitle' => $subtitle,
            'category' => $category,
            'shape' => $shape,
            'tone' => $tone,
            'status' => $status,
            'position' => ['x' => $x, 'y' => $y],
            'width' => $shape === 'circle' ? 170 : 190,
            'height' => $shape === 'circle' ? 170 : ($shape === 'diamond' ? 150 : 96),
            ...$extra,
        ];
    }

    private function edge(string $source, string $target, ?string $label = null, string $tone = 'slate'): array
    {
        return [
            'id' => $source.'-'.$target,
            'source' => $source,
            'target' => $target,
            'label' => $label,
            'tone' => $tone,
        ];
    }

    private function counts(User $user): array
    {
        $workspaceIds = $user->accessibleWorkspaceIds();

        $taskBase = Task::query()
            ->where(function (Builder $query) use ($user): void {
                $query->where('assignee_id', $user->id)
                    ->orWhere('reporter_id', $user->id);
            });

        return [
            'active_tasks' => (clone $taskBase)->whereNotIn('status', ['completed', 'archived'])->count(),
            'completed_tasks' => (clone $taskBase)->where('status', 'completed')->count(),
            'today_attention' => (clone $taskBase)
                ->whereNotIn('status', ['completed', 'archived'])
                ->where(function (Builder $query): void {
                    $query->whereDate('due_date', '<=', now()->toDateString())
                        ->orWhereIn('priority', ['urgent', 'high'])
                        ->orWhere('status', 'blocked');
                })
                ->count(),
            'projects' => Project::query()->whereIn('workspace_id', $workspaceIds)->count(),
            'decisions' => Decision::query()->where('user_id', $user->id)->where('status', 'open')->count(),
            'waiting' => WaitingItem::query()->where('user_id', $user->id)->where('status', 'open')->count(),
            'approvals' => Approval::query()->where('user_id', $user->id)->where('status', 'pending')->count(),
            'notes' => Note::query()->where('user_id', $user->id)->count(),
            'reminders' => MiriamReminder::query()->where('user_id', $user->id)->whereIn('status', ['pending', 'sent'])->count(),
            'calendar_connections' => CalendarConnection::query()->where('user_id', $user->id)->count(),
            'medication_schedules' => MedicationDoseSchedule::query()->where('user_id', $user->id)->where('active', true)->count(),
            'agent_outputs_waiting' => AgentOutput::query()
                ->whereHas('run', fn (Builder $query) => $query->where('user_id', $user->id))
                ->where('status', AgentOutput::STATUS_NEEDS_REVIEW)
                ->count(),
            'development_jobs' => MiriamDevelopmentJob::query()->count(),
            'development_failures' => MiriamDevelopmentFailure::query()->whereNull('resolved_at')->count(),
            'managed_apps' => MiriamManagedApp::query()->count(),
            'runner_agents' => MiriamRunnerAgent::query()->count(),
            'prompt_programs' => MiriamPromptProgram::query()->count(),
            'prompt_phases' => MiriamPromptPhase::query()->count(),
            'saved_prompts' => MiriamSavedPrompt::query()->count(),
            'records' => (clone $taskBase)->count()
                + Project::query()->whereIn('workspace_id', $workspaceIds)->count()
                + Decision::query()->where('user_id', $user->id)->count()
                + WaitingItem::query()->where('user_id', $user->id)->count()
                + Approval::query()->where('user_id', $user->id)->count()
                + Note::query()->where('user_id', $user->id)->count(),
        ];
    }

    private function summary(array $counts): array
    {
        return [
            ['label' => 'Capture first', 'value' => 'Central', 'tone' => 'purple'],
            ['label' => 'Today attention', 'value' => $counts['today_attention'], 'tone' => $counts['today_attention'] > 0 ? 'amber' : 'green'],
            ['label' => 'Active tasks', 'value' => $counts['active_tasks'], 'tone' => 'blue'],
            ['label' => 'Waiting', 'value' => $counts['waiting'], 'tone' => 'yellow'],
            ['label' => 'Approvals', 'value' => $counts['approvals'], 'tone' => 'amber'],
            ['label' => 'Agent outputs', 'value' => $counts['agent_outputs_waiting'], 'tone' => 'cyan'],
        ];
    }

    private function legend(): array
    {
        return [
            ['label' => 'Capture / primary', 'tone' => 'purple'],
            ['label' => 'Process', 'tone' => 'blue'],
            ['label' => 'Decision', 'tone' => 'amber'],
            ['label' => 'Record created', 'tone' => 'purple'],
            ['label' => 'Review / queue', 'tone' => 'yellow'],
            ['label' => 'External / action', 'tone' => 'cyan'],
            ['label' => 'End state', 'tone' => 'green'],
            ['label' => 'Not connected', 'tone' => 'slate'],
        ];
    }

    private function filters(): array
    {
        return [
            ['key' => 'implemented', 'label' => 'Implemented', 'field' => 'status', 'values' => ['Implemented', 'active', 'needs_review', 'completed', 'idle'], 'default' => true],
            ['key' => 'planned', 'label' => 'Planned', 'field' => 'status', 'values' => ['Planned'], 'default' => true],
            ['key' => 'not_connected', 'label' => 'Not Connected', 'field' => 'status', 'values' => ['Not Connected'], 'default' => true],
            ['key' => 'not_verified', 'label' => 'Not Verified', 'field' => 'status', 'values' => ['Not Verified'], 'default' => true],
        ];
    }

    private function routeIfExists(string $name, array $parameters = []): ?string
    {
        if (! Route::has($name)) {
            return null;
        }

        return route($name, $parameters, false);
    }
}
