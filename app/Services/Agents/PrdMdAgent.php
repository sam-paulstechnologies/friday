<?php

namespace App\Services\Agents;

use Illuminate\Support\Str;

class PrdMdAgent
{
    public function handle(array $context): array
    {
        $idea = trim($context['idea']);
        $contextLabel = $context['context_label'] ?? 'Miriam/Friday';
        $productName = $this->productName($idea, $contextLabel);

        $markdown = <<<MD
# {$productName}

## Product Name
{$productName}

## Problem
The current workflow is manual, inconsistent, and hard to track from first signal to completed outcome.

## Target Users
- Primary operator or business owner
- Internal admin/reviewer
- End customer or lead when applicable

## MVP Scope
- Capture one clear input
- Produce a structured plan or action queue
- Track status and review decisions
- Show approvals before any external action

## User Roles
- Owner: reviews and approves outputs
- Operator: executes approved tasks
- Viewer: monitors status

## Core Modules
- Idea intake
- Pipeline outputs
- Review/approval queue
- Status/log timeline
- Manual execution checklist

## User Journey
1. User enters the idea or workflow.
2. Miriam generates reviewable outputs.
3. User approves, rejects, or sends outputs to Today.
4. Approved work becomes implementation-ready.

## Data Model Sketch
- agent_runs
- agent_outputs
- review decisions
- optional linked tasks after approval

## Routes / Pages Needed
- Intake page
- Output detail page
- Review queue
- Today integration

## Out Of Scope
- Automatic code changes
- Automatic deployment
- Paid API calls
- Sending marketing messages without approval

## Build Phases
1. Rule-based MVP
2. Review workflow
3. Manual pilot
4. Optional provider adapters

## Acceptance Criteria
- User can run the workflow from one idea.
- Outputs are readable, structured, and copyable.
- Every external or code action requires approval.
- Tests cover routes, persistence, and review status.

## Open Questions
- Who is the first buyer or internal user?
- What is the first measurable success event?
- Which output should become a task after approval?
MD;

        return [
            'agent_key' => 'prd-md',
            'agent_name' => 'PRD / MD Agent',
            'title' => 'PRD draft: '.$productName,
            'category' => 'prd_md',
            'priority' => 'high',
            'due_label' => 'no_due_date',
            'generated_task_title' => 'Review PRD for '.$productName,
            'suggested_next_action' => 'Review the PRD, answer open questions, and approve only if scope is tight enough.',
            'payload' => [
                'product_name' => $productName,
                'problem' => 'Manual, inconsistent workflow from input to completed outcome.',
                'target_users' => ['Owner', 'Operator', 'Viewer'],
                'mvp_scope' => ['Intake', 'Structured outputs', 'Review decisions', 'Status/log timeline'],
                'user_roles' => ['Owner', 'Operator', 'Viewer'],
                'core_modules' => ['Idea intake', 'Pipeline outputs', 'Review queue', 'Today integration'],
                'user_journey' => ['Enter idea', 'Generate outputs', 'Review decisions', 'Execute approved work'],
                'data_model_sketch' => ['agent_runs', 'agent_outputs', 'review decisions', 'linked tasks'],
                'routes_pages_needed' => ['Intake page', 'Output detail page', 'Review queue', 'Today integration'],
                'out_of_scope' => ['Auto code changes', 'Auto deploys', 'Paid API calls', 'Unapproved external messages'],
                'build_phases' => ['Rule-based MVP', 'Review workflow', 'Manual pilot', 'Optional provider adapters'],
                'acceptance_criteria' => ['Workflow runs from one idea', 'Outputs are readable', 'Approval is required', 'Tests pass'],
                'open_questions' => ['Who is the first buyer?', 'What proves success?', 'Which output becomes a task?'],
                'markdown' => $markdown,
            ],
        ];
    }

    private function productName(string $idea, string $contextLabel): string
    {
        $text = Str::lower($idea);

        if (str_contains($text, 'whatsapp') && str_contains($text, 'garage')) {
            return 'Garage WhatsApp Sales Agent';
        }

        if (str_contains($text, 'church')) {
            return 'ChurchForce Workflow MVP';
        }

        if (str_contains($text, 'sayara')) {
            return 'SayaraForce Workflow MVP';
        }

        return $contextLabel.' MVP';
    }
}
