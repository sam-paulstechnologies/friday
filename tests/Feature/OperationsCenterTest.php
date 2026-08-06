<?php

namespace Tests\Feature;

use App\Models\AgentRun;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OperationsCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_operations_center_journey_flow_renders(): void
    {
        $user = $this->userWithWorkspace();

        $this->actingAs($user)
            ->get(route('operations-center.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('OperationsCenter/Index')
                ->where('initialGraph.component_key', 'operations-graph-v1')
                ->where('initialGraph.view', 'journey-flow')
                ->where('initialGraph.rootId', 'capture')
                ->where('initialGraph.layout.orientation', 'top-to-bottom')
                ->where('initialGraph.capabilities.true_fullscreen', true)
                ->where('initialGraph.capabilities.browser_local_layout_persistence', true)
                ->where('initialGraph.progressive.details_loaded_on_demand', true)
            );
    }

    public function test_journey_flow_initial_load_is_primary_top_to_bottom_trunk_only(): void
    {
        $user = $this->userWithWorkspace();

        $response = $this->actingAs($user)
            ->getJson(route('operations-center.graph', ['view' => 'journey-flow']))
            ->assertOk();

        $nodes = collect($response->json('nodes'));
        $edges = collect($response->json('edges'));

        $this->assertSame('top-to-bottom', $response->json('layout.orientation'));
        $this->assertCount(12, $nodes);
        $this->assertSame(
            ['capture', 'understand', 'classify', 'records', 'organise', 'prioritise', 'schedule-delegate-queue', 'execute', 'review', 'follow-up', 'complete'],
            $response->json('layout.primary_trunk'),
        );
        $this->assertNotNull($nodes->firstWhere('id', 'clarify'));
        $this->assertNull($nodes->firstWhere('id', 'classify-task'));
        $this->assertNotNull($edges->firstWhere('id', 'understand-classify'));
    }

    public function test_branch_expansion_loads_children_progressively_and_collapse_hides_descendants(): void
    {
        $user = $this->userWithWorkspace();

        $expanded = $this->actingAs($user)
            ->getJson(route('operations-center.graph', ['view' => 'journey-flow']).'?expanded=classify')
            ->assertOk();

        $expandedNodes = collect($expanded->json('nodes'));

        $this->assertCount(21, $expandedNodes);
        $this->assertNotNull($expandedNodes->firstWhere('id', 'classify-task'));
        $this->assertNotNull($expandedNodes->firstWhere('id', 'classify-health'));
        $this->assertContains('classify', $expanded->json('layout.expanded'));

        $collapsedNodes = collect($this->actingAs($user)
            ->getJson(route('operations-center.graph', ['view' => 'journey-flow']))
            ->assertOk()
            ->json('nodes'));

        $this->assertNull($collapsedNodes->firstWhere('id', 'classify-task'));
    }

    public function test_mind_map_loads_with_miriam_root_and_capture_primary(): void
    {
        $user = $this->userWithWorkspace();

        $response = $this->actingAs($user)
            ->getJson(route('operations-center.graph', ['view' => 'mind-map']))
            ->assertOk();

        $nodes = collect($response->json('nodes'));

        $this->assertSame('Miriam', $nodes->firstWhere('id', 'miriam')['title']);
        $this->assertTrue((bool) $nodes->firstWhere('id', 'capture')['primary']);
        $this->assertSame('capture', $response->json('rootId'));
    }

    public function test_technical_map_loads_verified_traces_for_workspace_manager(): void
    {
        $user = $this->userWithWorkspace(role: 'owner');

        $response = $this->actingAs($user)
            ->getJson(route('operations-center.graph', ['view' => 'technical-map']))
            ->assertOk();

        $nodes = collect($response->json('nodes'));

        $this->assertNotNull($nodes->firstWhere('id', 'page-today'));
        $this->assertNotNull($nodes->firstWhere('id', 'route-orchestrator'));
        $this->assertNotNull($nodes->firstWhere('id', 'controller-task-capture'));
        $this->assertNotNull($nodes->firstWhere('id', 'table-agent-outputs'));
    }

    public function test_technical_map_is_denied_to_non_manager_workspace_member(): void
    {
        [$user] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->getJson(route('operations-center.graph', ['view' => 'technical-map']))
            ->assertForbidden();
    }

    public function test_node_details_are_progressively_loaded_and_sanitized(): void
    {
        $user = $this->userWithWorkspace();

        $response = $this->actingAs($user)
            ->getJson(route('operations-center.nodes.show', ['view' => 'journey-flow', 'node' => 'capture']))
            ->assertOk();

        $this->assertSame('Capture', $response->json('title'));
        $this->assertStringContainsString('sanitized', $response->json('privacy_note'));
        $this->assertStringNotContainsString('password', strtolower(json_encode($response->json())));
    }

    public function test_graph_search_filter_fullscreen_drag_and_layout_capabilities_are_declared(): void
    {
        $user = $this->userWithWorkspace();

        $capabilities = $this->actingAs($user)
            ->getJson(route('operations-center.graph', ['view' => 'journey-flow']))
            ->assertOk()
            ->json('capabilities');

        $this->assertTrue($capabilities['search']);
        $this->assertTrue($capabilities['filters']);
        $this->assertTrue($capabilities['view_mode']);
        $this->assertTrue($capabilities['edit_mode']);
        $this->assertTrue($capabilities['node_dragging']);
        $this->assertTrue($capabilities['node_resizing']);
        $this->assertTrue($capabilities['zoom']);
        $this->assertTrue($capabilities['pan']);
        $this->assertTrue($capabilities['fit']);
        $this->assertTrue($capabilities['true_fullscreen']);
        $this->assertTrue($capabilities['preserve_selection_in_fullscreen']);
        $this->assertTrue($capabilities['preserve_zoom_and_pan']);
        $this->assertTrue($capabilities['browser_local_layout_persistence']);
        $this->assertTrue($capabilities['explicit_layout_save']);
        $this->assertTrue($capabilities['branch_expansion']);
        $this->assertTrue($capabilities['visual_connect_disconnect']);
        $this->assertTrue($capabilities['hide_without_deleting_records']);
        $this->assertFalse($capabilities['backend_layout_persistence']);
        $this->assertFalse($capabilities['workspace_layout_persistence']);
    }

    public function test_renderer_supports_view_edit_modes_local_layout_changes_and_delete_safety(): void
    {
        $renderer = file_get_contents(resource_path('js/Components/OperationsCenter/OperationsGraph.jsx'));

        $this->assertStringContainsString("const [mode, setMode] = useState('view')", $renderer);
        $this->assertStringContainsString("mode === 'edit'", $renderer);
        $this->assertStringContainsString('Unsaved canvas changes', $renderer);
        $this->assertStringContainsString('Save changes', $renderer);
        $this->assertStringContainsString('Undo last change', $renderer);
        $this->assertStringContainsString('Discard changes', $renderer);
        $this->assertStringContainsString('Restore my saved layout', $renderer);
        $this->assertStringContainsString('Restore default layout', $renderer);
        $this->assertStringContainsString('beforeunload', $renderer);
        $this->assertStringContainsString('window.localStorage', $renderer);
        $this->assertStringContainsString('Resize', $renderer);
        $this->assertStringContainsString('data-testid="operations-connection-modal"', $renderer);
        $this->assertStringContainsString('Disconnect this visual relationship? Underlying Miriam records are unchanged.', $renderer);
        $this->assertStringContainsString('Delete map node is unavailable for real Miriam entities. Use Hide from map.', $renderer);
        $this->assertStringNotContainsString('fetch(node.route', $renderer);
    }

    public function test_personal_layout_permissions_are_available_but_shared_editing_is_protected(): void
    {
        $owner = $this->userWithWorkspace(role: 'owner');
        [$member] = $this->memberWithWorkspace();

        $ownerPermissions = $this->actingAs($owner)
            ->getJson(route('operations-center.graph', ['view' => 'journey-flow']))
            ->assertOk()
            ->json('permissions');

        $memberPermissions = $this->actingAs($member)
            ->getJson(route('operations-center.graph', ['view' => 'journey-flow']))
            ->assertOk()
            ->json('permissions');

        $this->assertTrue($ownerPermissions['personal_layout']);
        $this->assertTrue($ownerPermissions['shared_catalogue_editing']);
        $this->assertFalse($ownerPermissions['workspace_layout_publish']);
        $this->assertTrue($memberPermissions['personal_layout']);
        $this->assertFalse($memberPermissions['shared_catalogue_editing']);
        $this->assertFalse($memberPermissions['workspace_layout_publish']);
    }

    public function test_fullscreen_selection_layout_persistence_and_progressive_fetch_are_in_shared_renderer(): void
    {
        $renderer = file_get_contents(resource_path('js/Components/OperationsCenter/OperationsGraph.jsx'));

        $this->assertStringContainsString('requestFullscreen', $renderer);
        $this->assertStringContainsString('document.fullscreenElement', $renderer);
        $this->assertStringContainsString('selectedId', $renderer);
        $this->assertStringContainsString('withQuery(endpoint(graphEndpoint', $renderer);
        $this->assertStringContainsString("params.set('expanded'", $renderer);
        $this->assertStringContainsString('Expand branch', $renderer);
        $this->assertStringContainsString('Collapse branch', $renderer);
        $this->assertStringContainsString('Focus on this branch', $renderer);
        $this->assertStringContainsString('Return to full journey', $renderer);
        $this->assertStringContainsString('Search nodes...', $renderer);
    }

    public function test_agent_orchestrator_uses_shared_renderer_and_keeps_progressive_logs(): void
    {
        $user = $this->userWithWorkspace();

        $this->actingAs($user)
            ->post(route('agents.orchestrator.run'), [
                'idea' => 'Build a WhatsApp sales agent for garages that books demos today.',
                'context_label' => 'SayaraForce',
                'mode' => 'full_pipeline',
                'selected_agent' => null,
                'force_continue' => false,
            ])
            ->assertRedirect();

        $run = AgentRun::query()->whereNull('parent_run_id')->firstOrFail();

        $this->actingAs($user)
            ->get(route('agents.orchestrator.index', ['run' => $run->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Agents/Orchestrator/Index')
                ->where('graph.component_key', 'operations-graph-v1')
                ->where('graph.view', 'agent-orchestrator')
                ->where('selectedRun.logs_loaded', false)
                ->where('selectedRun.logs_endpoint', route('agents.orchestrator.runs.logs', $run, false))
                ->has('selectedRun.outputs', 8)
            );
    }

    public function test_agent_run_logs_are_loaded_on_demand_for_owner_only(): void
    {
        $user = $this->userWithWorkspace();
        $other = $this->userWithWorkspace(email: 'other@example.com');

        $this->actingAs($user)
            ->post(route('agents.orchestrator.run-agent'), [
                'idea' => 'Research SayaraForce follow up.',
                'context_label' => 'SayaraForce',
                'mode' => 'selected_agent',
                'selected_agent' => 'research',
                'force_continue' => false,
            ])
            ->assertRedirect();

        $run = AgentRun::query()->where('user_id', $user->id)->whereNull('parent_run_id')->firstOrFail();

        $this->actingAs($user)
            ->getJson(route('agents.orchestrator.runs.logs', $run))
            ->assertOk()
            ->assertJsonStructure(['run_id', 'logs']);

        $this->actingAs($other)
            ->getJson(route('agents.orchestrator.runs.logs', $run))
            ->assertForbidden();
    }

    public function test_capture_agent_accepts_capture_without_mandatory_categorisation(): void
    {
        $user = $this->userWithWorkspace();

        $this->actingAs($user)
            ->post(route('agents.task-capture.run'), [
                'input' => 'Need to check one vague thing later',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('agent_outputs', [
            'category' => 'general_task',
        ]);
    }

    public function test_no_fabricated_operations_center_routes_or_actions_are_exposed(): void
    {
        $user = $this->userWithWorkspace();

        $graph = $this->actingAs($user)
            ->getJson(route('operations-center.graph', ['view' => 'mind-map']))
            ->assertOk()
            ->json();

        $serialized = strtolower(json_encode($graph));

        $this->assertStringNotContainsString('git changes', $serialized);
        $this->assertStringNotContainsString('test reports', $serialized);
        $this->assertStringNotContainsString('backup pages', $serialized);
        $this->assertStringNotContainsString('gmail', $serialized);
        $this->assertStringNotContainsString('/capture', $serialized);
    }

    public function test_shared_renderer_is_imported_by_operations_center_and_orchestrator_pages(): void
    {
        $operationsPage = file_get_contents(resource_path('js/Pages/OperationsCenter/Index.jsx'));
        $orchestratorPage = file_get_contents(resource_path('js/Pages/Agents/Orchestrator/Index.jsx'));
        $renderer = file_get_contents(resource_path('js/Components/OperationsCenter/OperationsGraph.jsx'));

        $this->assertStringContainsString('@/Components/OperationsCenter/OperationsGraph', $operationsPage);
        $this->assertStringContainsString('@/Components/OperationsCenter/OperationsGraph', $orchestratorPage);
        $this->assertStringContainsString('data-testid="operations-graph-renderer"', $renderer);
        $this->assertStringContainsString('window.localStorage', $renderer);
        $this->assertStringContainsString('requestFullscreen', $renderer);
    }

    private function userWithWorkspace(string $role = 'owner', string $email = 'paul@example.com'): User
    {
        $user = User::factory()->create(['email' => $email]);
        $workspace = Workspace::create([
            'name' => 'Miriam Workspace',
            'slug' => 'miriam-workspace-'.str()->random(6),
            'created_by' => $role === 'owner' ? $user->id : null,
        ]);

        $workspace->users()->attach($user->id, [
            'role' => $role,
            'joined_at' => now(),
        ]);

        Task::create([
            'workspace_id' => $workspace->id,
            'title' => 'Capture first task',
            'status' => 'todo',
            'priority' => 'high',
            'assignee_id' => $user->id,
            'reporter_id' => $user->id,
            'due_date' => now()->toDateString(),
        ]);

        return $user;
    }

    private function memberWithWorkspace(): array
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $member = User::factory()->create(['email' => 'member@example.com']);
        $workspace = Workspace::create([
            'name' => 'Shared Workspace',
            'slug' => 'shared-workspace-'.str()->random(6),
            'created_by' => $owner->id,
        ]);

        $workspace->users()->attach($owner->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);
        $workspace->users()->attach($member->id, [
            'role' => 'member',
            'joined_at' => now(),
        ]);

        return [$member, $workspace];
    }
}
