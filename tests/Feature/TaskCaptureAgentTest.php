<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TaskCaptureAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_page_loads(): void
    {
        $user = $this->userWithWorkspace();

        $this->actingAs($user)
            ->get(route('agents.task-capture.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Agents/TaskCapture/Index')
                ->where('agent.name', 'Task Capture Agent')
                ->where('agent.slug', 'task-capture')
                ->where('selectedRun', null)
                ->has('recentRuns')
            );

        $this->assertDatabaseHas('agents', [
            'slug' => 'task-capture',
            'name' => 'Task Capture Agent',
        ]);
    }

    public function test_running_agent_with_sayaraforce_text_creates_coding_and_client_followup_result(): void
    {
        $user = $this->userWithWorkspace();

        $this->actingAs($user)
            ->post(route('agents.task-capture.run'), [
                'input' => 'Need to finish SayaraForce manager UI and follow up with Mecline tomorrow',
            ])
            ->assertRedirect();

        $run = AgentRun::with(['agent', 'outputs', 'logs'])->firstOrFail();

        $this->assertSame('Task Capture Agent', $run->agent->name);
        $this->assertSame('completed', $run->status);
        $this->assertContains('coding', $run->result['categories']);
        $this->assertContains('client_followup', $run->result['categories']);
        $this->assertSame(['SayaraForce', 'Mecline'], $run->result['detected_projects']);
        $this->assertSame('tomorrow', $run->result['due_label']);
        $this->assertContains('coding', $run->outputs->pluck('category')->all());
        $this->assertContains('client_followup', $run->outputs->pluck('category')->all());
        $this->assertGreaterThanOrEqual(4, $run->logs->count());
    }

    public function test_urgent_today_text_becomes_high_priority(): void
    {
        $user = $this->userWithWorkspace();

        $this->actingAs($user)
            ->post(route('agents.task-capture.run'), [
                'input' => 'Urgent issue in ChurchForce production today',
            ])
            ->assertRedirect();

        $run = AgentRun::with('outputs')->firstOrFail();

        $this->assertSame('high', $run->result['priority']);
        $this->assertSame('today', $run->result['due_label']);
        $this->assertContains('coding', $run->result['categories']);
        $this->assertSame(['ChurchForce'], $run->result['detected_projects']);
        $this->assertSame('high', $run->outputs->first()->priority);
    }

    public function test_normal_vague_text_becomes_general_task(): void
    {
        $user = $this->userWithWorkspace();

        $this->actingAs($user)
            ->post(route('agents.task-capture.run'), [
                'input' => 'Need to sort this out later',
            ])
            ->assertRedirect();

        $run = AgentRun::with('outputs')->firstOrFail();

        $this->assertSame(['general_task'], $run->result['categories']);
        $this->assertSame('low', $run->result['priority']);
        $this->assertSame('no_due_date', $run->result['due_label']);
        $this->assertSame('general_task', $run->outputs->first()->category);
    }

    public function test_agent_run_detail_props_include_input_result_and_logs(): void
    {
        $user = $this->userWithWorkspace();

        $this->actingAs($user)
            ->post(route('agents.task-capture.run'), [
                'input' => 'Need to check swimming class for Judah',
            ]);

        $run = AgentRun::firstOrFail();

        $this->actingAs($user)
            ->get(route('agents.task-capture.index', ['run' => $run->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedRun.id', $run->id)
                ->where('selectedRun.original_input', 'Need to check swimming class for Judah')
                ->where('selectedRun.result.categories.0', 'family')
                ->where('selectedRun.result.detected_projects.0', 'Judah')
                ->has('selectedRun.logs')
                ->has('selectedRun.outputs')
            );
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
