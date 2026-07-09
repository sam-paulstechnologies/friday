<?php

namespace Tests\Feature;

use App\Models\AgentOutput;
use App\Models\AgentRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AgentOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_agents_index_loads(): void
    {
        $user = $this->userWithWorkspace();

        $this->actingAs($user)
            ->get(route('agents.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Agents/Index')
                ->has('agents')
                ->where('agents.0.name', 'Task Capture Agent')
            );

        $this->assertDatabaseHas('agents', [
            'slug' => 'agent-orchestrator',
            'name' => 'Agent Orchestrator',
        ]);
    }

    public function test_orchestrator_page_loads(): void
    {
        $user = $this->userWithWorkspace();

        $this->actingAs($user)
            ->get(route('agents.orchestrator.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Agents/Orchestrator/Index')
                ->has('contexts')
                ->has('pipelineAgents', 8)
                ->where('selectedRun', null)
            );
    }

    public function test_full_pipeline_creates_runs_and_outputs(): void
    {
        $user = $this->userWithWorkspace();

        $this->actingAs($user)
            ->post(route('agents.orchestrator.run'), $this->pipelinePayload())
            ->assertRedirect();

        $parent = AgentRun::query()
            ->whereNull('parent_run_id')
            ->with(['outputs', 'childRuns.outputs'])
            ->firstOrFail();

        $this->assertSame('needs_review', $parent->status);
        $this->assertCount(7, $parent->childRuns);
        $this->assertSame(8, AgentRun::count());
        $this->assertSame(8, AgentOutput::count());
        $this->assertSame(8, $parent->outputs->count() + $parent->childRuns->flatMap(fn (AgentRun $run) => $run->outputs)->count());
        $this->assertDatabaseHas('agent_outputs', [
            'agent_key' => 'ui-ux-marketing',
            'status' => 'needs_review',
        ]);
    }

    public function test_research_agent_falls_back_to_rule_based_without_api_keys(): void
    {
        $user = $this->userWithWorkspace();

        $this->actingAs($user)
            ->post(route('agents.orchestrator.run-agent'), $this->pipelinePayload([
                'mode' => 'selected_agent',
                'selected_agent' => 'research',
            ]))
            ->assertRedirect();

        $output = AgentOutput::firstOrFail();

        $this->assertSame('research', $output->agent_key);
        $this->assertSame('rule_based', $output->payload['source']);
        $this->assertSame('Live web/API research not configured.', $output->payload['note']);
    }

    public function test_rejected_idea_stops_prd_unless_force_mode_is_enabled(): void
    {
        $user = $this->userWithWorkspace();

        $this->actingAs($user)
            ->post(route('agents.orchestrator.run'), $this->pipelinePayload([
                'idea' => 'Random free wallpaper app with no buyer no revenue and maybe impossible to sell',
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('agent_outputs', [
            'agent_key' => 'idea-validation',
        ]);
        $this->assertDatabaseMissing('agent_outputs', [
            'agent_key' => 'prd-md',
        ]);

        $this->actingAs($user)
            ->post(route('agents.orchestrator.run'), $this->pipelinePayload([
                'idea' => 'Random free wallpaper app with no buyer no revenue and maybe impossible to sell',
                'force_continue' => true,
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('agent_outputs', [
            'agent_key' => 'prd-md',
        ]);
    }

    public function test_promising_or_strong_idea_generates_prd(): void
    {
        $user = $this->userWithWorkspace();

        $this->actingAs($user)
            ->post(route('agents.orchestrator.run'), $this->pipelinePayload())
            ->assertRedirect();

        $output = AgentOutput::query()->where('agent_key', 'prd-md')->firstOrFail();

        $this->assertStringContainsString('product name', strtolower($output->payload['markdown']));
        $this->assertStringContainsString('Garage WhatsApp Sales Agent', $output->payload['markdown']);
    }

    public function test_codex_claude_prompt_includes_forbidden_and_validation_commands(): void
    {
        $user = $this->userWithWorkspace();

        $this->actingAs($user)
            ->post(route('agents.orchestrator.run-agent'), $this->pipelinePayload([
                'mode' => 'selected_agent',
                'selected_agent' => 'codex-claude-prompt',
            ]))
            ->assertRedirect();

        $payload = AgentOutput::firstOrFail()->payload;

        $this->assertContains('do not edit .env', $payload['forbidden_commands']);
        $this->assertContains('do not run destructive DB commands', $payload['forbidden_commands']);
        $this->assertContains('php artisan route:list', $payload['validation_commands']);
        $this->assertContains('npm run build', $payload['validation_commands']);
    }

    public function test_test_plan_includes_route_test_and_build_checks(): void
    {
        $user = $this->userWithWorkspace();

        $this->actingAs($user)
            ->post(route('agents.orchestrator.run-agent'), $this->pipelinePayload([
                'mode' => 'selected_agent',
                'selected_agent' => 'test-plan',
            ]))
            ->assertRedirect();

        $markdown = AgentOutput::firstOrFail()->payload['markdown'];

        $this->assertStringContainsString('php artisan route:list', $markdown);
        $this->assertStringContainsString('php artisan test --filter=<relevant>', $markdown);
        $this->assertStringContainsString('npm run build', $markdown);
    }

    public function test_ui_ux_marketing_output_includes_ux_and_offer_sections(): void
    {
        $user = $this->userWithWorkspace();

        $this->actingAs($user)
            ->post(route('agents.orchestrator.run-agent'), $this->pipelinePayload([
                'mode' => 'selected_agent',
                'selected_agent' => 'ui-ux-marketing',
            ]))
            ->assertRedirect();

        $payload = AgentOutput::firstOrFail()->payload;

        $this->assertArrayHasKey('ui_ux_review_plan', $payload);
        $this->assertArrayHasKey('marketing_offer', $payload);
        $this->assertStringContainsString('UI/UX Review Plan', $payload['markdown']);
        $this->assertStringContainsString('Marketing Offer', $payload['markdown']);
    }

    public function test_output_approval_and_rejection_update_status(): void
    {
        $user = $this->userWithWorkspace();

        $this->actingAs($user)
            ->post(route('agents.orchestrator.run'), $this->pipelinePayload())
            ->assertRedirect();

        [$approveOutput, $rejectOutput] = AgentOutput::query()->limit(2)->get();

        $this->actingAs($user)
            ->post(route('agents.outputs.approve', $approveOutput))
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('agents.outputs.reject', $rejectOutput))
            ->assertRedirect();

        $this->assertDatabaseHas('agent_outputs', [
            'id' => $approveOutput->id,
            'status' => 'approved',
            'reviewed_by' => $user->id,
        ]);
        $this->assertDatabaseHas('agent_outputs', [
            'id' => $rejectOutput->id,
            'status' => 'rejected',
            'reviewed_by' => $user->id,
        ]);
    }

    public function test_send_to_today_makes_output_visible_under_waiting_on_me(): void
    {
        $user = $this->userWithWorkspace();

        $this->actingAs($user)
            ->post(route('agents.orchestrator.run-agent'), $this->pipelinePayload([
                'mode' => 'selected_agent',
                'selected_agent' => 'research',
            ]))
            ->assertRedirect();

        $output = AgentOutput::firstOrFail();
        $output->update(['sent_to_today_at' => null]);

        $this->actingAs($user)
            ->post(route('agents.outputs.send-to-today', $output))
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('commandCenter.waiting_on_me.0.kind', 'agent_output')
                ->where('commandCenter.waiting_on_me.0.model_id', $output->id)
                ->where('commandCenter.waiting_on_me.0.primary_action', 'Approve')
            );
    }

    private function pipelinePayload(array $overrides = []): array
    {
        return [
            'idea' => 'Build a WhatsApp sales agent for garages that finds garage numbers, sends templates, follows up, and books demos.',
            'context_label' => 'SayaraForce',
            'mode' => 'full_pipeline',
            'selected_agent' => null,
            'force_continue' => false,
            ...$overrides,
        ];
    }

    private function userWithWorkspace(): User
    {
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'Miriam Workspace',
            'slug' => 'miriam-workspace-'.str()->random(6),
            'created_by' => $user->id,
        ]);

        $workspace->users()->attach($user->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        return $user;
    }
}
