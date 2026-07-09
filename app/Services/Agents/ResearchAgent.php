<?php

namespace App\Services\Agents;

use Illuminate\Support\Str;

class ResearchAgent
{
    public function handle(array $context): array
    {
        $idea = trim($context['idea']);
        $contextLabel = $context['context_label'] ?? 'Miriam/Friday';
        $summary = $this->sentence($idea);
        $targetMarket = $this->targetMarket($idea, $contextLabel);
        $pain = $this->pain($idea);
        $alternatives = $this->alternatives($idea, $contextLabel);
        $pricing = $this->pricingHints($idea);
        $questions = [
            'Who owns the pain and budget?',
            'What manual process is being replaced first?',
            'What proof would make this worth building this month?',
            'Which channel can validate demand fastest?',
        ];

        $markdown = <<<MD
## Cleaned Idea Summary
{$summary}

## Target Market
{$targetMarket}

## Customer Pain
{$pain}

## Likely Alternatives / Competitors
- {$alternatives[0]}
- {$alternatives[1]}
- {$alternatives[2]}

## Assumptions To Validate
- The buyer has this problem frequently enough to pay.
- The workflow can be proven with a small manual MVP before automation.
- The first channel can reach qualified prospects without heavy setup.

## Pricing Hints
{$pricing}

## Research Confidence
Medium. This is rule-based first-pass research.

## Recommended Validation Questions
- {$questions[0]}
- {$questions[1]}
- {$questions[2]}
- {$questions[3]}

## Source
rule_based

Live web/API research not configured.
MD;

        return [
            'agent_key' => 'research',
            'agent_name' => 'Research Agent',
            'title' => 'Research brief: '.$this->shortTitle($idea),
            'category' => 'research',
            'priority' => 'medium',
            'due_label' => 'no_due_date',
            'generated_task_title' => 'Review research assumptions for '.$this->shortTitle($idea),
            'suggested_next_action' => 'Review the assumptions, answer the validation questions, and decide whether to continue.',
            'payload' => [
                'cleaned_idea_summary' => $summary,
                'target_market' => $targetMarket,
                'customer_pain' => $pain,
                'alternatives_competitors' => $alternatives,
                'assumptions_to_validate' => [
                    'Buyer has urgent enough pain.',
                    'Manual MVP can validate before automation.',
                    'First sales channel is reachable.',
                ],
                'pricing_hints' => $pricing,
                'research_confidence' => 'medium',
                'recommended_validation_questions' => $questions,
                'source' => 'rule_based',
                'note' => 'Live web/API research not configured.',
                'markdown' => $markdown,
            ],
        ];
    }

    private function targetMarket(string $idea, string $contextLabel): string
    {
        $text = Str::lower($idea.' '.$contextLabel);

        return match (true) {
            str_contains($text, 'garage') => 'Independent garages, service centers, and automotive repair operators that need more booked jobs.',
            str_contains($text, 'church') => 'Church administrators, ministry teams, and operations staff.',
            str_contains($text, 'academy') || str_contains($text, 'cffa') => 'Students, parents, coaches, and academy operators.',
            str_contains($text, 'health') => 'The user and personal health routines that need accountability.',
            str_contains($text, 'finance') => 'Personal finance and life-admin workflows.',
            default => $contextLabel.' users, operators, and decision makers.',
        };
    }

    private function pain(string $idea): string
    {
        $text = Str::lower($idea);

        if (str_contains($text, 'whatsapp') || str_contains($text, 'follow')) {
            return 'Prospecting and follow-up are inconsistent, manual, and easy to abandon before a demo is booked.';
        }

        if (str_contains($text, 'dashboard') || str_contains($text, 'command')) {
            return 'Important work is scattered, which makes urgency and next action unclear.';
        }

        return 'The workflow likely has friction, unclear ownership, or repeated manual steps.';
    }

    private function alternatives(string $idea, string $contextLabel): array
    {
        $text = Str::lower($idea.' '.$contextLabel);

        if (str_contains($text, 'whatsapp') || str_contains($text, 'sales')) {
            return ['Manual WhatsApp outreach', 'CRM reminders and spreadsheets', 'Generic lead-generation agencies or tools'];
        }

        return ['Manual tracking in notes/spreadsheets', 'Generic task managers', 'Custom internal admin screens'];
    }

    private function pricingHints(string $idea): string
    {
        $text = Str::lower($idea);

        if (str_contains($text, 'sales') || str_contains($text, 'demo') || str_contains($text, 'garage')) {
            return 'Start with a setup fee plus monthly retainer, or a pay-per-qualified-demo package if fulfillment is manual first.';
        }

        return 'Use a small paid pilot before subscription pricing. Charge for a clear operational outcome, not the automation itself.';
    }

    private function sentence(string $idea): string
    {
        return rtrim(Str::of($idea)->squish()->ucfirst()->toString(), '.').'.';
    }

    private function shortTitle(string $idea): string
    {
        return Str::of($idea)->squish()->limit(64, '')->toString();
    }
}
