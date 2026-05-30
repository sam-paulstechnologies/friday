<?php

namespace Tests\Feature;

use App\Models\AiAction;
use App\Models\AiConversation;
use App\Models\CalendarConnection;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AiAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-05-30 09:00:00');
    }

    public function test_assistant_route_requires_authentication(): void
    {
        $this->get(route('assistant.index'))->assertRedirect(route('login'));
    }

    public function test_assistant_page_loads_for_authenticated_user(): void
    {
        [$user] = $this->context('assistant-page', 'owner');

        $this->actingAs($user)
            ->get(route('assistant.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Assistant/Index')
                ->where('assistant.enabled', false)
                ->where('assistant.provider', 'mock')
                ->where('assistant.api_key_configured', false)
            );
    }

    public function test_disabled_ai_config_returns_safe_message(): void
    {
        [$user] = $this->context('disabled', 'owner');
        config(['services.ai_assistant.enabled' => false]);

        $this->actingAs($user)
            ->postJson(route('assistant.message'), ['message' => 'What should I focus on today?'])
            ->assertOk()
            ->assertJsonPath('provider', 'disabled')
            ->assertJsonFragment(['message' => 'Miriam Assistant is disabled. Enable AI_ASSISTANT_ENABLED and keep AI_PROVIDER=mock for local, no-cost assistant responses.']);
    }

    public function test_mock_provider_returns_daily_focus_from_accessible_workspace_only(): void
    {
        [$user, $workspace, $project] = $this->context('focus', 'owner');
        [$otherOwner, $otherWorkspace, $otherProject] = $this->context('other-focus', 'owner');
        $this->enableAssistant();
        $this->task($workspace, $project, $user, ['title' => 'Owned due today', 'due_date' => '2026-05-30']);
        $this->task($otherWorkspace, $otherProject, $otherOwner, ['title' => 'Other workspace secret task', 'due_date' => '2026-05-30']);

        $this->actingAs($user)
            ->postJson(route('assistant.message'), ['message' => 'What should I focus on today?'])
            ->assertOk()
            ->assertJsonFragment(['provider' => 'mock'])
            ->assertSee('Owned due today')
            ->assertDontSee('Other workspace secret task');
    }

    public function test_assistant_does_not_expose_calendar_tokens_or_secrets(): void
    {
        [$user, $workspace] = $this->context('secrets', 'owner');
        $this->enableAssistant();
        CalendarConnection::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'provider' => 'google',
            'provider_account_email' => 'safe@example.com',
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => now()->addHour(),
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->postJson(route('assistant.message'), ['message' => 'Give me a workspace snapshot'])
            ->assertOk()
            ->assertDontSee('safe@example.com');
    }

    public function test_viewer_cannot_create_task_via_assistant(): void
    {
        [$viewer, $workspace] = $this->context('viewer', 'viewer');
        $this->enableAssistant();

        $this->actingAs($viewer)
            ->postJson(route('assistant.actions.create-task'), [
                'workspace_id' => $workspace->id,
                'title' => 'Viewer blocked task',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('tasks', ['title' => 'Viewer blocked task']);
    }

    public function test_member_can_create_workspace_scoped_task_via_assistant_and_action_is_logged(): void
    {
        [$member, $workspace] = $this->context('member', 'member');
        $this->enableAssistant();

        $this->actingAs($member)
            ->postJson(route('assistant.actions.create-task'), [
                'workspace_id' => $workspace->id,
                'title' => 'Call John',
                'due_date' => '2026-05-31',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Task created.');

        $task = Task::where('title', 'Call John')->firstOrFail();
        $this->assertSame($workspace->id, $task->workspace_id);
        $this->assertSame($member->id, $task->reporter_id);
        $this->assertDatabaseHas('ai_actions', [
            'user_id' => $member->id,
            'workspace_id' => $workspace->id,
            'status' => AiAction::STATUS_EXECUTED,
            'target_id' => $task->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'action' => 'ai_task_created',
        ]);
    }

    public function test_assistant_can_summarize_accessible_project(): void
    {
        [$user, $workspace, $project] = $this->context('summary', 'owner');
        $this->enableAssistant();
        $this->task($workspace, $project, $user, ['title' => 'Summary task', 'due_date' => '2026-05-29']);

        $this->actingAs($user)
            ->postJson(route('assistant.message'), ['message' => 'Summarize project '.$project->name])
            ->assertOk()
            ->assertSee($project->name)
            ->assertSee('Open tasks');
    }

    public function test_assistant_cannot_summarize_inaccessible_project(): void
    {
        [$user] = $this->context('summary-owner', 'owner');
        [, , $otherProject] = $this->context('summary-other', 'owner');
        $this->enableAssistant();

        $this->actingAs($user)
            ->postJson(route('assistant.message'), ['message' => 'Summarize project '.$otherProject->name])
            ->assertOk()
            ->assertSee('could not find an accessible project')
            ->assertDontSee('Open tasks');
    }

    public function test_conversation_and_messages_are_logged_safely(): void
    {
        [$user] = $this->context('logs', 'owner');
        $this->enableAssistant();

        $this->actingAs($user)
            ->postJson(route('assistant.message'), ['message' => 'What should I focus on today?'])
            ->assertOk()
            ->assertSee('Today focus');

        $this->assertSame(1, AiConversation::count());
        $this->assertSame(2, AiConversation::first()->messages()->count());
    }

    private function enableAssistant(): void
    {
        config([
            'services.ai_assistant.enabled' => true,
            'services.ai_assistant.provider' => 'mock',
            'services.ai_assistant.model' => null,
            'services.ai_assistant.api_key' => null,
        ]);
    }

    private function context(string $slug, string $role): array
    {
        $owner = User::factory()->create(['email' => "{$slug}-owner@example.com", 'name' => ucfirst($slug).' Owner']);
        $user = $role === 'owner' ? $owner : User::factory()->create(['email' => "{$slug}-{$role}@example.com", 'name' => ucfirst($slug).' '.ucfirst($role)]);
        $workspace = Workspace::create(['name' => ucfirst($slug).' Workspace', 'slug' => "{$slug}-workspace", 'created_by' => $owner->id]);
        $team = Team::create(['workspace_id' => $workspace->id, 'name' => ucfirst($slug).' Team', 'slug' => "{$slug}-team"]);
        $project = Project::create([
            'workspace_id' => $workspace->id,
            'team_id' => $team->id,
            'owner_id' => $owner->id,
            'name' => ucfirst($slug).' Project',
            'slug' => "{$slug}-project",
            'status' => 'active',
            'visibility' => 'workspace',
        ]);

        foreach (collect([$owner, $user])->unique('id') as $member) {
            $workspace->users()->attach($member->id, ['role' => $member->is($owner) ? 'owner' : $role, 'joined_at' => now()]);
            $team->users()->attach($member->id, ['role' => $member->is($owner) ? 'lead' : 'member', 'joined_at' => now()]);
        }

        return [$user, $workspace, $project];
    }

    private function task(Workspace $workspace, Project $project, User $user, array $overrides = []): Task
    {
        return Task::create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Assistant task',
            'status' => 'todo',
            'priority' => 'medium',
            'assignee_id' => $user->id,
            'reporter_id' => $user->id,
            ...$overrides,
        ]);
    }
}
