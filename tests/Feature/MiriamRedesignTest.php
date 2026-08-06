<?php

namespace Tests\Feature;

use App\Models\AgentOutput;
use App\Models\AgentRun;
use App\Models\MiriamReminder;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Agents\TaskCaptureAgent;
use App\Services\Inbox\WebCaptureService;
use App\Services\Miriam\MiriamSlackThoughtCaptureService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\TestCase;

/**
 * The redesigned web workflow, without Slack.
 *
 * Quick Capture -> Inbox -> review -> task -> Today -> complete -> Completed,
 * plus the canonical workflow-state rules and the authorization boundaries the
 * new drawer, pagination and search must respect.
 */
class MiriamRedesignTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'http://taskflow.test',
            'app.operational_timezone' => 'Asia/Dubai',
            'services.slack.signing_secret' => 'test-signing-secret',
            'services.slack.bot_token' => 'xoxb-test',
            'services.slack.allowed_user_id' => 'U123',
            'services.slack.miriam_channel_id' => 'CMIRIAM',
            'services.slack.default_channel' => null,
            'services.slack.daily_user_id' => null,
        ]);

        $moment = CarbonImmutable::parse('2026-06-23 12:00:00', 'Asia/Dubai');
        CarbonImmutable::setTestNow($moment);
        Carbon::setTestNow(Carbon::parse($moment->utc()->toDateTimeString(), 'UTC'));

        [$this->user, $this->workspace] = $this->userWithWorkspace();

        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------- quick capture

    public function test_web_quick_capture_creates_one_inbox_item_with_the_exact_original_text(): void
    {
        $text = 'Follow up with Julian about CF Football Academy tomorrow morning';

        $this->actingAs($this->user)
            ->post(route('capture.store'), ['text' => $text, 'client_token' => 'tok-1'])
            ->assertRedirect();

        $this->assertDatabaseCount('tasks', 1);

        $task = Task::firstOrFail();

        $this->assertSame(Task::WORKFLOW_INBOX, $task->workflow_state);
        $this->assertSame(WebCaptureService::SOURCE, $task->source);
        // Byte-for-byte, not a normalised or re-titled version.
        $this->assertSame($text, $task->source_metadata['original_text']);
        $this->assertSame($text, $task->description);
        $this->assertSame($this->user->id, $task->assignee_id);
        // A capture is not a scheduled reminder.
        $this->assertDatabaseCount('miriam_reminders', 0);
    }

    public function test_double_submitting_quick_capture_does_not_create_a_duplicate(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->actingAs($this->user)
                ->post(route('capture.store'), ['text' => 'Same thought', 'client_token' => 'tok-dup'])
                ->assertRedirect();
        }

        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_a_failed_classification_still_keeps_the_capture_and_flags_it_for_review(): void
    {
        // The parser blows up; the operator's words must survive anyway.
        $this->app->bind(MiriamSlackThoughtCaptureService::class, fn () => new class(app(TaskCaptureAgent::class)) extends MiriamSlackThoughtCaptureService
        {
            public function parseCapture(string $text, ?User $user = null, ?Workspace $workspace = null, ?CarbonImmutable $now = null): array
            {
                throw new RuntimeException('classifier exploded');
            }
        });

        $this->actingAs($this->user)
            ->post(route('capture.store'), ['text' => 'Something Miriam cannot read', 'client_token' => 'tok-fail'])
            ->assertRedirect();

        $task = Task::firstOrFail();

        $this->assertSame('Something Miriam cannot read', $task->source_metadata['original_text']);
        $this->assertTrue($task->source_metadata['needs_review']);
        $this->assertFalse($task->source_metadata['classified']);

        $items = app(\App\Services\Inbox\InboxService::class)->items($this->user);

        $this->assertSame('clarification_needed', $items['open'][0]['state']);
    }

    public function test_add_to_today_is_explicit_and_places_the_task_on_today(): void
    {
        $this->actingAs($this->user)
            ->post(route('capture.store'), ['text' => 'Call the bank', 'destination' => 'today', 'client_token' => 'tok-today'])
            ->assertRedirect();

        $task = Task::firstOrFail();

        $this->assertSame(Task::WORKFLOW_TODAY, $task->workflow_state);
        $this->assertSame('2026-06-23', $task->due_date->toDateString());
    }

    public function test_urgent_sounding_wording_alone_does_not_put_a_capture_on_today(): void
    {
        $this->actingAs($this->user)
            ->post(route('capture.store'), ['text' => 'URGENT!! do this right now today immediately', 'client_token' => 'tok-urgent'])
            ->assertRedirect();

        // Destination was not requested, so it stays in the Inbox.
        $this->assertSame(Task::WORKFLOW_INBOX, Task::firstOrFail()->workflow_state);
    }

    public function test_quick_capture_rejects_empty_text(): void
    {
        $this->actingAs($this->user)
            ->post(route('capture.store'), ['text' => '   '])
            ->assertSessionHasErrors('text');

        $this->assertDatabaseCount('tasks', 0);
    }

    // -------------------------------------------------- inbox consistency

    public function test_inbox_shows_web_and_slack_captures_in_the_same_list(): void
    {
        $this->actingAs($this->user)->post(route('capture.store'), ['text' => 'From the web', 'client_token' => 'tok-web']);
        $this->postSignedSlackEvent('Remind me to call Julian tomorrow at 10 AM.');

        $this->actingAs($this->user)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inbox/Index')
                ->where('inbox.counts.open', 2)
            );

        $sources = collect(app(\App\Services\Inbox\InboxService::class)->items($this->user)['open'])
            ->pluck('capture_source')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['Quick Capture', 'Slack'], $sources);
    }

    public function test_a_web_capture_is_not_shown_in_my_tasks_until_it_is_triaged(): void
    {
        $this->actingAs($this->user)->post(route('capture.store'), ['text' => 'Untriaged thought', 'client_token' => 'tok-hidden']);

        foreach (['today', 'all', 'upcoming', 'later'] as $view) {
            $this->actingAs($this->user)
                ->get(route('tasks.index', ['view' => $view]))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->where('tasks.data', []));
        }
    }

    public function test_another_user_cannot_view_or_convert_a_capture(): void
    {
        [$intruder] = $this->userWithWorkspace('intruder');
        $this->actingAs($this->user)->post(route('capture.store'), ['text' => 'Private thought', 'client_token' => 'tok-priv']);
        $task = Task::firstOrFail();

        $this->actingAs($intruder)->get(route('inbox.show', ['task', $task->id]))->assertForbidden();
        $this->actingAs($intruder)->post(route('inbox.convert', ['task', $task->id]))->assertForbidden();
        $this->actingAs($intruder)->post(route('inbox.dismiss', ['task', $task->id]))->assertForbidden();

        $this->assertSame(Task::WORKFLOW_INBOX, $task->refresh()->workflow_state);
    }

    // ------------------------------------------------------- full web loop

    public function test_the_complete_web_daily_loop_without_slack(): void
    {
        $text = 'Follow up with Julian about CF Football Academy tomorrow morning';

        // 1. Capture from the web.
        $this->actingAs($this->user)->post(route('capture.store'), ['text' => $text, 'client_token' => 'tok-loop'])->assertRedirect();
        $capture = Task::firstOrFail();

        // 2. It appears in the Inbox with the original wording.
        $this->actingAs($this->user)
            ->get(route('inbox.show', ['task', $capture->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inbox/Show')
                ->where('item.original_text', $text)
                ->where('item.state', 'unprocessed')
            );

        // 3. Correct the interpreted title without retyping the thought.
        $this->actingAs($this->user)
            ->post(route('inbox.convert', ['task', $capture->id]), [
                'title' => 'Follow up with Julian on the academy invoice',
                'destination' => 'today',
            ])
            ->assertRedirect();

        // 4. Exactly one task, still carrying the original words.
        $this->assertDatabaseCount('tasks', 1);
        $task = Task::firstOrFail();
        $this->assertSame('Follow up with Julian on the academy invoice', $task->title);
        $this->assertSame($text, $task->source_metadata['original_text']);
        $this->assertSame(Task::WORKFLOW_TODAY, $task->workflow_state);

        // 5. It is on Today.
        $this->actingAs($this->user)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('groups.due_today.0.id', $task->id));

        // 6. Complete it from Today, and stay on Today.
        $this->actingAs($this->user)
            ->from(route('today.index'))
            ->patch(route('tasks.complete', $task))
            ->assertRedirect(route('today.index'));

        // 7. Gone from active Today.
        $this->actingAs($this->user)
            ->get(route('today.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('groups.due_today', [])
                ->where('summary.completed_today', 1)
            );

        // 8. Present under Completed.
        $this->actingAs($this->user)
            ->get(route('tasks.index', ['view' => 'completed']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('tasks.data.0.id', $task->id));

        // 9. Reopen returns it to a valid active state.
        $this->actingAs($this->user)->patch(route('tasks.restore', $task))->assertRedirect();
        $task->refresh();
        $this->assertSame('todo', $task->status);
        $this->assertNotContains($task->workflow_state, Task::INACTIVE_WORKFLOW_STATES);

        // 10. The capture is still traceable to the task.
        $this->assertSame($text, $task->source_metadata['original_text']);
        $this->assertSame(WebCaptureService::SOURCE, $task->source);
    }

    // ------------------------------------------- task capture agent repair

    public function test_no_task_capture_agent_action_points_at_a_blank_task_form(): void
    {
        $page = file_get_contents(resource_path('js/Pages/Agents/TaskCapture/Index.jsx'));

        $this->assertStringNotContainsString("route('tasks.create')", $page);
        $this->assertStringContainsString("agents.task-capture.capture", $page);
    }

    public function test_a_task_capture_proposal_converts_through_the_shared_pipeline(): void
    {
        $output = $this->agentOutput('Follow up with May about SayaraForce invoices');

        $this->actingAs($this->user)
            ->post(route('agents.task-capture.capture', $output->id))
            ->assertRedirect();

        $this->assertDatabaseCount('tasks', 1);
        $task = Task::firstOrFail();

        $this->assertSame(Task::WORKFLOW_INBOX, $task->workflow_state);
        $this->assertSame('Follow up with May about SayaraForce invoices', $task->source_metadata['original_text']);

        // Idempotent: converting the same proposal again reuses the capture.
        $this->actingAs($this->user)->post(route('agents.task-capture.capture', $output->id))->assertRedirect();
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_another_user_cannot_convert_someone_elses_agent_proposal(): void
    {
        [$intruder] = $this->userWithWorkspace('intruder');
        $output = $this->agentOutput('Private proposal');

        $this->actingAs($intruder)->post(route('agents.task-capture.capture', $output->id))->assertForbidden();
        $this->assertDatabaseCount('tasks', 0);
    }

    // ------------------------------------------------- canonical workflow

    public function test_an_arbitrary_workflow_state_is_rejected_by_the_task_form(): void
    {
        $this->actingAs($this->user)
            ->post(route('tasks.store'), $this->taskPayload(['workflow_state' => 'whatever-i-typed']))
            ->assertSessionHasErrors('workflow_state');

        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_a_canonical_workflow_state_is_accepted(): void
    {
        $this->actingAs($this->user)
            ->post(route('tasks.store'), $this->taskPayload(['workflow_state' => Task::WORKFLOW_LATER]))
            ->assertRedirect();

        $this->assertSame(Task::WORKFLOW_LATER, Task::firstOrFail()->workflow_state);
    }

    public function test_a_free_text_section_label_is_still_allowed_and_never_touched_by_transitions(): void
    {
        // `section` is the operator's own grouping label; 424 live tasks use it.
        $this->actingAs($this->user)
            ->post(route('tasks.store'), $this->taskPayload(['section' => 'Phase 4 - Sales Kit']))
            ->assertRedirect();

        $task = Task::firstOrFail();
        $this->assertSame('Phase 4 - Sales Kit', $task->section);

        $this->actingAs($this->user)->patch(route('today.tasks.today', $task))->assertRedirect();

        $task->refresh();
        $this->assertSame('Phase 4 - Sales Kit', $task->section, 'A workflow move must not overwrite the section label.');
        $this->assertSame(Task::WORKFLOW_TODAY, $task->workflow_state);
    }

    // ------------------------------------------------------- authorization

    public function test_the_task_detail_panel_enforces_authorization(): void
    {
        [$intruder] = $this->userWithWorkspace('intruder');
        $task = $this->task(['title' => 'Private task']);

        $this->actingAs($this->user)->getJson(route('tasks.panel', $task))->assertOk();
        $this->actingAs($intruder)->getJson(route('tasks.panel', $task))->assertForbidden();
    }

    public function test_search_and_pagination_never_expose_another_users_tasks(): void
    {
        [$intruder, $intruderWorkspace] = $this->userWithWorkspace('intruder');
        $this->task(['title' => 'Secret roadmap review']);

        Task::create([
            'workspace_id' => $intruderWorkspace->id,
            'title' => 'Intruder own task',
            'status' => 'todo',
            'priority' => 'medium',
            'assignee_id' => $intruder->id,
            'reporter_id' => $intruder->id,
        ]);

        $this->actingAs($intruder)
            ->get(route('tasks.index', ['view' => 'all', 'search' => 'Secret roadmap']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('tasks.data', []));

        $this->actingAs($intruder)
            ->get(route('tasks.index', ['view' => 'all']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('tasks.total', 1));
    }

    // ---------------------------------------------------------- navigation

    public function test_the_task_list_is_paginated_rather_than_unbounded(): void
    {
        foreach (range(1, 60) as $index) {
            $this->task(['title' => "Bulk task {$index}", 'due_date' => '2026-06-23']);
        }

        $this->actingAs($this->user)
            ->get(route('tasks.index', ['view' => 'today']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('tasks.per_page', 50)
                ->where('tasks.total', 60)
                ->has('tasks.data', 50)
            );
    }

    public function test_inbox_and_notifications_are_separate_destinations(): void
    {
        $this->actingAs($this->user)->get(route('inbox.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Inbox/Index'));

        $this->actingAs($this->user)->get(route('notifications.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Notifications/Index'));
    }

    public function test_every_navigation_destination_in_the_sidebar_resolves(): void
    {
        // Mirrors resources/js/Components/Shell/navigation.js.
        $routes = [
            'today.index', 'inbox.index', 'tasks.index',
            'projects.index', 'portfolios.index',
            'reminders.index', 'waiting.index', 'approvals.index', 'decisions.index', 'blockers.index',
            'health.index', 'spiritual.index', 'planner.index',
            'notifications.index', 'notes.index', 'reports.index', 'operations-center.index',
            'assistant.index', 'agents.index', 'dashboard',
            'settings.workspace.edit', 'settings.integrations.index', 'settings.system-health.index',
            'settings.automations.index', 'settings.ai.edit', 'templates.index', 'admin.custom-fields.index',
        ];

        foreach ($routes as $name) {
            $this->assertTrue(\Illuminate\Support\Facades\Route::has($name), "Route [{$name}] is missing.");
            $this->actingAs($this->user)->get(route($name))->assertSuccessful();
        }
    }

    public function test_the_reminders_page_reports_finite_poker_state(): void
    {
        MiriamReminder::create([
            'user_id' => $this->user->id,
            'category' => 'personal',
            'title' => 'call the bank',
            'timezone' => 'Asia/Dubai',
            'due_at' => CarbonImmutable::now('UTC'),
            'status' => 'exhausted',
            'reminder_attempts' => 3,
            'next_reminder_at' => null,
        ]);

        $this->actingAs($this->user)
            ->get(route('reminders.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reminders/Index')
                ->where('groups.needs_attention.0.title', 'call the bank')
                ->where('groups.needs_attention.0.exhausted', true)
                ->where('groups.needs_attention.0.attempts', 3)
                ->where('poker.max_pokes', 3)
                // Truthful integration state, not an assumption.
                ->where('delivery.slack_configured', true)
            );
    }

    // ------------------------------------------------------------- helpers

    private function agentOutput(string $input): AgentOutput
    {
        $agent = app(TaskCaptureAgent::class)->ensureRegistered();

        $run = AgentRun::create([
            'agent_id' => $agent->id,
            'user_id' => $this->user->id,
            'status' => AgentRun::STATUS_COMPLETED,
            'original_input' => $input,
            'result' => [],
        ]);

        return AgentOutput::create([
            'agent_run_id' => $run->id,
            'category' => 'general_task',
            'priority' => 'medium',
            'due_label' => 'no_date',
            'generated_task_title' => $input,
            'suggested_next_action' => 'Follow up',
            'payload' => [],
        ]);
    }

    private function taskPayload(array $overrides = []): array
    {
        return [
            'workspace_id' => $this->workspace->id,
            'title' => 'Payload task',
            'status' => 'todo',
            'priority' => 'medium',
            ...$overrides,
        ];
    }

    private function task(array $overrides = []): Task
    {
        return Task::create([
            'workspace_id' => $this->workspace->id,
            'title' => 'Redesign task',
            'status' => 'todo',
            'priority' => 'medium',
            'assignee_id' => $this->user->id,
            'reporter_id' => $this->user->id,
            ...$overrides,
        ]);
    }

    private function userWithWorkspace(string $prefix = 'owner'): array
    {
        $user = User::factory()->create(['email' => "{$prefix}-".uniqid().'@example.test']);
        $workspace = Workspace::create([
            'name' => ucfirst($prefix).' Workspace',
            'slug' => $prefix.'-workspace-'.uniqid(),
            'created_by' => $user->id,
        ]);

        return [$user, $workspace];
    }

    private function postSignedSlackEvent(string $text)
    {
        $payload = json_encode([
            'team_id' => 'T123',
            'event' => ['type' => 'message', 'channel' => 'CMIRIAM', 'user' => 'U123', 'text' => $text, 'ts' => '1710000000.000100'],
        ]);
        $timestamp = (string) time();

        return $this->call('POST', route('slack.events', absolute: false), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SLACK_REQUEST_TIMESTAMP' => $timestamp,
            'HTTP_X_SLACK_SIGNATURE' => 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$payload}", 'test-signing-secret'),
        ], $payload);
    }
}
