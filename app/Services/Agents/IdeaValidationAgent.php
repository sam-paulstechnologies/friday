<?php

namespace App\Services\Agents;

use Illuminate\Support\Str;

class IdeaValidationAgent
{
    public function handle(array $context): array
    {
        $idea = trim($context['idea']);
        $contextLabel = $context['context_label'] ?? 'Miriam/Friday';
        $verdict = $this->verdict($idea);
        $customer = $this->customer($idea, $contextLabel);
        $painIntensity = in_array($verdict, ['strong', 'promising'], true) ? 'high' : ($verdict === 'reject' ? 'low' : 'medium');
        $revenuePotential = in_array($verdict, ['strong', 'promising'], true) ? 'medium-high' : ($verdict === 'reject' ? 'low' : 'unclear');
        $buildComplexity = Str::contains(Str::lower($idea), ['automation', 'agent', 'scrape', 'integration']) ? 'medium' : 'small';
        $salesComplexity = Str::contains(Str::lower($idea), ['b2b', 'client', 'garage', 'church', 'academy']) ? 'medium' : 'low';
        $competitionRisk = Str::contains(Str::lower($idea), ['crm', 'sales', 'ai']) ? 'medium' : 'low';
        $fastestMvp = $this->fastestMvp($idea);
        $recommendation = $this->recommendation($verdict);

        $markdown = <<<MD
## Verdict
{$verdict}

## Target Customer
{$customer}

## Pain Intensity
{$painIntensity}

## Revenue Potential
{$revenuePotential}

## Build Complexity
{$buildComplexity}

## Sales Complexity
{$salesComplexity}

## Competition Risk
{$competitionRisk}

## Fastest MVP
{$fastestMvp}

## Reason To Build Now
The idea is worth action now if one buyer can confirm the pain and accept a manual-first pilot.

## Reason To Defer
Defer if the buyer, budget, or first measurable outcome is still vague.

## Recommendation
{$recommendation}
MD;

        return [
            'agent_key' => 'idea-validation',
            'agent_name' => 'Idea Validation Agent',
            'title' => 'Validation verdict: '.$verdict,
            'category' => 'idea_validation',
            'priority' => in_array($verdict, ['strong', 'promising'], true) ? 'high' : 'medium',
            'due_label' => 'no_due_date',
            'generated_task_title' => 'Review idea validation verdict',
            'suggested_next_action' => $recommendation,
            'payload' => [
                'verdict' => $verdict,
                'target_customer' => $customer,
                'pain_intensity' => $painIntensity,
                'revenue_potential' => $revenuePotential,
                'build_complexity' => $buildComplexity,
                'sales_complexity' => $salesComplexity,
                'competition_risk' => $competitionRisk,
                'fastest_mvp' => $fastestMvp,
                'reason_to_build_now' => 'A small pilot can validate demand before a larger build.',
                'reason_to_defer' => 'Defer if buyer, budget, and first measurable outcome remain unclear.',
                'recommendation' => $recommendation,
                'markdown' => $markdown,
            ],
        ];
    }

    private function verdict(string $idea): string
    {
        $text = Str::lower($idea);

        if (Str::contains($text, ['no buyer', 'no revenue', 'impossible', 'free wallpaper'])) {
            return 'reject';
        }

        if (Str::contains($text, ['need research', 'not sure', 'unclear market'])) {
            return 'needs_research';
        }

        $strongSignals = collect(['client', 'payment', 'deadline', 'sales', 'demo', 'garage', 'whatsapp', 'follow up', 'revenue'])
            ->filter(fn (string $signal) => str_contains($text, $signal))
            ->count();

        if ($strongSignals >= 4) {
            return 'strong';
        }

        if ($strongSignals >= 2 || Str::contains($text, ['build', 'mvp', 'operator'])) {
            return 'promising';
        }

        return 'weak';
    }

    private function customer(string $idea, string $contextLabel): string
    {
        $text = Str::lower($idea.' '.$contextLabel);

        return match (true) {
            str_contains($text, 'garage') => 'Garage owners and service managers',
            str_contains($text, 'church') => 'Church operations teams',
            str_contains($text, 'academy') || str_contains($text, 'cffa') => 'Academy operators and parents',
            str_contains($text, 'health') => 'Personal health operator',
            default => $contextLabel.' decision maker',
        };
    }

    private function fastestMvp(string $idea): string
    {
        return Str::contains(Str::lower($idea), ['whatsapp', 'sales'])
            ? 'Run a manual lead list, send controlled WhatsApp templates, track replies, and book demos before automation.'
            : 'Create a single workflow prototype, run it manually for one user, and measure whether it removes real friction.';
    }

    private function recommendation(string $verdict): string
    {
        return match ($verdict) {
            'strong' => 'Build a narrow MVP after one buyer confirmation.',
            'promising' => 'Run a validation sprint, then write the PRD.',
            'needs_research' => 'Pause for targeted research and buyer proof before PRD.',
            'reject' => 'Do not build now; capture the lesson and move on.',
            default => 'Keep as a backlog idea unless a buyer or deadline appears.',
        };
    }
}
