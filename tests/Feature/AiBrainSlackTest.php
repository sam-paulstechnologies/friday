<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\AiTaskRecommendation;
use App\Models\Area;
use App\Models\Portfolio;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Ai\AiContextBuilder;
use App\Services\Ai\AiIntentResolver;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\AiSnapshotImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiBrainSlackTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_disabled_returns_helpful_slack_response(): void
    {
        config(['services.slack.signing_secret' => 'secret', 'services.slack.default_channel' => 'C123', 'services.slack.bot_token' => 'xoxb-test']);
        $this->context();
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSlack('friday give me Career tasks snapshot')->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat.postMessage')
            && str_contains((string) $request->body(), 'AI Brain is not enabled yet'));
    }

    public function test_intent_resolver_maps_common_hints(): void
    {
        $this->context();
        $resolver = app(AiIntentResolver::class);

        $this->assertSame('Career', $resolver->resolve('Career tasks')['match']['name']);
        $this->assertStringContainsString('Stellantis', $resolver->resolve('Stellantis work')['match']['name']);
        $this->assertSame("Paul's Photography", $resolver->resolve('photography campaigns')['match']['name']);
        $this->assertSame('UAE Realtor Agents App', $resolver->resolve('realtor app Eid sprint')['match']['name']);
        $this->assertSame('SayaraForce', $resolver->resolve('garage CRM blockers')['match']['name']);
        $this->assertSame('ChurchForce', $resolver->resolve('church app status')['match']['name']);
    }

    public function test_context_builder_limits_tasks_using_ai_settings(): void
    {
        [$user, $workspace, $project] = $this->context();
        $this->enableAi(['max_tasks_sent' => 3]);

        foreach (range(1, 8) as $index) {
            $this->task($user, $workspace, $project, ['title' => "Career task {$index}"]);
        }

        $context = app(AiContextBuilder::class)->build(null, app(AiSettingsService::class)->maxTasksSent());

        $this->assertCount(3, $context['tasks']);
        $this->assertSame(3, $context['task_count_sent']);
    }

    public function test_normal_chat_uses_default_model(): void
    {
        $this->fakeSlackAndOpenAi('Snapshot answer');
        $this->context();
        $this->enableAi(['default_model' => 'gpt-4o-mini', 'planner_model' => 'gpt-5.4-mini']);

        $this->postSlack('friday what is due this week?')->assertOk();

        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/chat/completions'
            && ($request->data()['model'] ?? null) === 'gpt-4o-mini');
    }

    public function test_priority_review_uses_planner_model_and_stores_pending_without_changing_task(): void
    {
        [$user, $workspace, $project] = $this->context();
        $task = $this->task($user, $workspace, $project, ['priority' => 'urgent']);
        $this->enableAi(['default_model' => 'gpt-4o-mini', 'planner_model' => 'gpt-5.4-mini']);
        $this->fakeSlackAndOpenAi(json_encode([
            'suggestions' => [[
                'task_id' => $task->id,
                'current_priority' => 'urgent',
                'suggested_priority' => 'high',
                'current_status' => 'todo',
                'suggested_status' => null,
                'current_due_date' => null,
                'suggested_due_date' => null,
                'reason' => 'Important, but not due this week.',
                'confidence' => 82,
            ]],
        ]));

        $this->postSlack('friday reprioritize my week but ask before applying changes')->assertOk();

        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/chat/completions'
            && ($request->data()['model'] ?? null) === 'gpt-5.4-mini');
        $this->assertDatabaseHas('ai_task_recommendations', [
            'task_id' => $task->id,
            'recommendation_type' => 'priority',
            'status' => 'pending',
            'suggested_value' => 'high',
        ]);
        $this->assertSame('urgent', $task->refresh()->priority);
    }

    public function test_approve_ai_one_applies_only_that_recommendation(): void
    {
        [$user, $workspace, $project] = $this->context();
        $first = $this->task($user, $workspace, $project, ['priority' => 'urgent']);
        $second = $this->task($user, $workspace, $project, ['priority' => 'medium']);
        $this->pendingRecommendation($user, $first, 'priority', 'urgent', 'high');
        $this->pendingRecommendation($user, $second, 'priority', 'medium', 'low');
        config(['services.slack.signing_secret' => 'secret', 'services.slack.default_channel' => 'C123', 'services.slack.bot_token' => 'xoxb-test']);
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSlack('approve ai 1')->assertOk();

        $this->assertSame('high', $first->refresh()->priority);
        $this->assertSame('medium', $second->refresh()->priority);
        $this->assertDatabaseHas('task_activities', [
            'task_id' => $first->id,
            'action' => 'ai_brain_approval_applied',
        ]);
    }

    public function test_reject_ai_one_does_not_update_task(): void
    {
        [$user, $workspace, $project] = $this->context();
        $task = $this->task($user, $workspace, $project, ['priority' => 'urgent']);
        $this->pendingRecommendation($user, $task, 'priority', 'urgent', 'high');
        config(['services.slack.signing_secret' => 'secret', 'services.slack.default_channel' => 'C123', 'services.slack.bot_token' => 'xoxb-test']);
        Http::fake(['slack.com/*' => Http::response(['ok' => true])]);

        $this->postSlack('reject ai 1')->assertOk();

        $this->assertSame('urgent', $task->refresh()->priority);
        $this->assertDatabaseHas('ai_task_recommendations', [
            'task_id' => $task->id,
            'status' => 'rejected',
        ]);
    }

    public function test_voice_transcription_failure_returns_fallback_response(): void
    {
        $this->context();
        $this->enableAi();
        config(['services.slack.signing_secret' => 'secret', 'services.slack.default_channel' => 'C123', 'services.slack.bot_token' => 'xoxb-test']);
        Http::fake([
            'https://files.slack.test/*' => Http::response('audio-bytes', 200),
            'https://api.openai.com/v1/audio/transcriptions' => Http::response(['error' => 'failed'], 500),
            'slack.com/*' => Http::response(['ok' => true]),
        ]);

        $this->postSlack('', [
            'files' => [[
                'mimetype' => 'audio/ogg',
                'url_private_download' => 'https://files.slack.test/voice.ogg',
            ]],
        ])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat.postMessage')
            && str_contains((string) $request->body(), 'I could not transcribe the voice note'));
    }

    public function test_snapshot_image_failure_falls_back_to_text(): void
    {
        $this->context();
        $this->enableAi();
        $this->fakeSlackAndOpenAi('Career Snapshot text');
        $this->app->instance(AiSnapshotImageService::class, new class extends AiSnapshotImageService
        {
            public function generate(array $context, string $recommendation): string
            {
                throw new \RuntimeException('Image failed.');
            }
        });

        $this->postSlack('friday give me Career snapshot')->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat.postMessage')
            && str_contains((string) $request->body(), 'Career Snapshot text'));
    }

    public function test_api_key_is_redacted_from_ai_response(): void
    {
        $this->context();
        $this->enableAi(['api_key' => 'sk-test-secretabcd']);
        $this->fakeSlackAndOpenAi('The key is sk-test-secretabcd');

        $this->postSlack('friday tell me what is due this week')->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat.postMessage')
            && str_contains((string) $request->body(), '[redacted]')
            && ! str_contains((string) $request->body(), 'sk-test-secretabcd'));
    }

    private function context(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'TaskFlow Workspace',
            'slug' => 'taskflow-workspace',
            'created_by' => $user->id,
        ]);
        $area = Area::create(['name' => 'Career', 'slug' => 'career', 'is_active' => true]);
        $portfolio = Portfolio::create(['workspace_id' => $workspace->id, 'area_id' => $area->id, 'name' => 'SayaraForce', 'slug' => 'sayaraforce']);
        Portfolio::create(['workspace_id' => $workspace->id, 'area_id' => $area->id, 'name' => 'ChurchForce', 'slug' => 'churchforce']);
        Portfolio::create(['workspace_id' => $workspace->id, 'area_id' => $area->id, 'name' => "Paul's Photography", 'slug' => 'pauls-photography']);
        Portfolio::create(['workspace_id' => $workspace->id, 'area_id' => $area->id, 'name' => 'Stellantis GCC', 'slug' => 'stellantis-gcc']);
        Portfolio::create(['workspace_id' => $workspace->id, 'area_id' => $area->id, 'name' => 'Stellantis South Africa', 'slug' => 'stellantis-south-africa']);
        $project = Project::create([
            'workspace_id' => $workspace->id,
            'area_id' => $area->id,
            'portfolio_id' => $portfolio->id,
            'owner_id' => $user->id,
            'name' => 'UAE Realtor Agents App',
            'slug' => 'uae-realtor-agents-app',
            'status' => 'active',
            'visibility' => 'workspace',
        ]);

        return [$user, $workspace, $project, $area, $portfolio];
    }

    private function task(User $user, Workspace $workspace, Project $project, array $overrides = []): Task
    {
        return Task::create([
            'workspace_id' => $workspace->id,
            'area_id' => $project->area_id,
            'portfolio_id' => $project->portfolio_id,
            'project_id' => $project->id,
            'title' => 'Task',
            'status' => 'todo',
            'priority' => 'medium',
            'assignee_id' => $user->id,
            'reporter_id' => $user->id,
            ...$overrides,
        ]);
    }

    private function enableAi(array $overrides = []): void
    {
        $setting = new AiSetting(['provider' => AiSetting::PROVIDER_OPENAI]);
        $setting->setApiKey($overrides['api_key'] ?? 'sk-test-secretabcd');
        $setting->fill([
            'default_model' => $overrides['default_model'] ?? 'gpt-4o-mini',
            'planner_model' => $overrides['planner_model'] ?? 'gpt-5.4-mini',
            'max_tasks_sent' => $overrides['max_tasks_sent'] ?? 30,
            'max_output_tokens' => $overrides['max_output_tokens'] ?? 1200,
            'is_enabled' => true,
        ])->save();
    }

    private function fakeSlackAndOpenAi(string $content): void
    {
        config(['services.slack.signing_secret' => 'secret', 'services.slack.default_channel' => 'C123', 'services.slack.bot_token' => 'xoxb-test']);
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => $content]],
                ],
            ]),
            'slack.com/*' => Http::response(['ok' => true, 'channel' => 'C123', 'ts' => '123.456']),
        ]);
    }

    private function pendingRecommendation(User $user, Task $task, string $type, ?string $current, ?string $suggested): AiTaskRecommendation
    {
        return AiTaskRecommendation::create([
            'user_id' => $user->id,
            'task_id' => $task->id,
            'recommendation_type' => $type,
            'current_value' => $current,
            'suggested_value' => $suggested,
            'reason' => 'Test recommendation.',
            'status' => 'pending',
            'source' => 'slack_text',
        ]);
    }

    private function postSlack(string $text, array $eventOverrides = [])
    {
        $payload = json_encode(['event' => array_merge(['channel' => 'C123', 'user' => 'U123', 'text' => $text], $eventOverrides)]);
        $timestamp = (string) time();
        $signature = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$payload}", 'secret');

        return $this->withHeaders([
            'X-Slack-Request-Timestamp' => $timestamp,
            'X-Slack-Signature' => $signature,
            'Content-Type' => 'application/json',
        ])->postJson(route('webhooks.slack.events'), json_decode($payload, true));
    }
}
