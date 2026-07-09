<?php

namespace App\Services\Agents;

use Illuminate\Support\Str;

class UiUxMarketingAgent
{
    public function handle(array $context): array
    {
        $idea = trim($context['idea']);
        $contextLabel = $context['context_label'] ?? 'Miriam/Friday';
        $offerName = $this->offerName($idea, $contextLabel);

        $markdown = <<<MD
## A. UI/UX Review Plan

### Main Screens Needed
- Intake / run screen
- Output review cards
- Logs/status panel
- Today waiting approval item

### Layout Sections
- Input panel
- Pipeline status
- Agent output cards
- Review actions

### Empty States
- No runs yet
- No outputs needing review
- Agent failed safely

### Urgency / Clarity Checklist
- Show next action above backlog
- Badge waiting/approved/rejected clearly
- Keep approval buttons visible

### Mobile Checklist
- Single-column cards
- Large tap targets
- Copy buttons remain reachable

### Dark Mode Checklist
- Preserve contrast on status badges
- Avoid low-contrast muted copy

### Bad UX Risks
- Too many outputs without grouping
- Approval action hidden below long markdown
- Logs overpowering the result

### Demo Readiness Checklist
- Seed one realistic run
- Show readable markdown
- Approve/reject/send to Today works

## B. Marketing Offer

### Offer Name
{$offerName}

### Target Customer
{$this->targetCustomer($idea, $contextLabel)}

### Headline
Turn one rough idea into an approved build plan without losing control.

### Pain / Promise
Stop bouncing between notes, chats, and tools. Miriam turns the idea into reviewable research, PRD, prompts, tests, UX, and offer copy.

### Package / Pricing Suggestion
Start with a paid pilot or setup package before subscription pricing.

### Landing Page Sections
- Problem
- How the agent pipeline works
- Outputs produced
- Safety and approval controls
- Pilot offer

### WhatsApp Outreach Template
Hi, I am testing a small agent workflow that turns a rough business idea into a reviewable build and sales plan. Want me to run one idea for you?

### Meta Ad Hooks
- Your idea is not the bottleneck. Your next clear action is.
- Turn messy business ideas into approved build plans.
- Research, PRD, prompts, tests, and offer copy in one controlled flow.

### Follow-Up Sequence
1. Ask for one idea.
2. Share the generated plan.
3. Ask what feels useful or wrong.
4. Offer a paid pilot.

### First Validation Experiment
Run the pipeline for three real prospects and ask whether they would pay for the resulting plan.
MD;

        return [
            'agent_key' => 'ui-ux-marketing',
            'agent_name' => 'UI/UX + Marketing Offer Agent',
            'title' => 'UX review and first offer',
            'category' => 'ui_ux_marketing',
            'priority' => 'medium',
            'due_label' => 'no_due_date',
            'generated_task_title' => 'Review UX plan and marketing offer',
            'suggested_next_action' => 'Use the UX checklist to tighten the demo, then test the offer with one real prospect.',
            'payload' => [
                'ui_ux_review_plan' => [
                    'main_screens_needed' => ['Intake/run screen', 'Output cards', 'Logs/status panel', 'Today approval item'],
                    'layout_sections' => ['Input panel', 'Pipeline status', 'Agent outputs', 'Review actions'],
                    'empty_states' => ['No runs yet', 'No outputs needing review', 'Agent failed safely'],
                    'urgency_clarity_checklist' => ['next action visible', 'badges clear', 'approval buttons visible'],
                    'mobile_checklist' => ['single-column cards', 'large tap targets', 'reachable copy buttons'],
                    'dark_mode_checklist' => ['contrast badges', 'readable muted copy'],
                    'bad_ux_risks' => ['too many outputs', 'hidden approval actions', 'overpowering logs'],
                    'demo_readiness_checklist' => ['realistic run', 'readable markdown', 'review actions work'],
                ],
                'marketing_offer' => [
                    'offer_name' => $offerName,
                    'target_customer' => $this->targetCustomer($idea, $contextLabel),
                    'headline' => 'Turn one rough idea into an approved build plan without losing control.',
                    'pain_promise' => 'Miriam converts messy ideas into reviewable research, PRD, prompts, tests, UX, and offer copy.',
                    'package_pricing_suggestion' => 'Paid pilot or setup package before subscription pricing.',
                    'landing_page_sections' => ['Problem', 'Pipeline', 'Outputs', 'Safety controls', 'Pilot offer'],
                    'whatsapp_outreach_template' => 'Hi, I am testing a small agent workflow that turns a rough business idea into a reviewable build and sales plan. Want me to run one idea for you?',
                    'meta_ad_hooks' => ['Your idea is not the bottleneck. Your next clear action is.', 'Turn messy business ideas into approved build plans.'],
                    'follow_up_sequence' => ['Ask for one idea', 'Share the plan', 'Ask what feels useful or wrong', 'Offer a paid pilot'],
                    'first_validation_experiment' => 'Run the pipeline for three real prospects and ask whether they would pay for the resulting plan.',
                ],
                'markdown' => $markdown,
            ],
        ];
    }

    private function offerName(string $idea, string $contextLabel): string
    {
        if (Str::contains(Str::lower($idea), ['garage', 'whatsapp'])) {
            return 'Garage Demo Booking Sprint';
        }

        return $contextLabel.' Agent Sprint';
    }

    private function targetCustomer(string $idea, string $contextLabel): string
    {
        return Str::contains(Str::lower($idea), 'garage')
            ? 'Garage owners who need more qualified bookings'
            : $contextLabel.' operators who need clarity before building';
    }
}
