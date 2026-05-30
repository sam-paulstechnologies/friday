<?php

namespace App\Services\Ai;

use App\Models\User;
use App\Services\Slack\SlackService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiBrainService
{
    public function __construct(
        private readonly AiSettingsService $settings,
        private readonly AiIntentResolver $intentResolver,
        private readonly AiContextBuilder $contextBuilder,
        private readonly AiRecommendationService $recommendations,
        private readonly AiSnapshotImageService $snapshotImage,
    ) {
    }

    public function answer(string $prompt, ?User $user = null, array $options = []): array
    {
        if (! $this->settings->isEnabled() || blank($this->settings->apiKey())) {
            return [
                'text' => 'AI Brain is not enabled yet. Please configure it in Friday > AI Brain Settings.',
                'context' => null,
                'model' => null,
                'used_planner' => false,
            ];
        }

        $source = (string) ($options['source'] ?? 'manual');
        $cleanPrompt = $this->stripPrefix($prompt);
        $usePlanner = (bool) ($options['planner'] ?? $this->isPlannerPrompt($cleanPrompt));
        $wantsImage = (bool) ($options['image'] ?? $this->wantsImage($cleanPrompt));
        $model = $usePlanner ? $this->settings->plannerModel() : $this->settings->defaultModel();
        $intent = $this->intentResolver->resolve($cleanPrompt);

        if (($intent['status'] ?? null) === 'ambiguous') {
            return [
                'text' => 'I found multiple matches: '.collect($intent['matches'])->pluck('name')->implode(', ').'. Which one should I review?',
                'context' => null,
                'model' => $model,
                'used_planner' => $usePlanner,
            ];
        }

        $scope = $intent['match'] ?? null;
        $context = $this->contextBuilder->build($scope, $this->settings->maxTasksSent());
        $scopeName = $scope['name'] ?? 'All Tasks';

        Log::info('ai_request_started', [
            'model' => $model,
            'scope' => $scopeName,
            'task_count_sent' => $context['task_count_sent'],
            'source' => $source,
        ]);

        try {
            $response = $usePlanner
                ? $this->plannerResponse($model, $cleanPrompt, $context, $user?->id, $source)
                : $this->chatResponse($model, $cleanPrompt, $context);

            Log::info('ai_request_completed', [
                'model' => $model,
                'scope' => $scopeName,
                'task_count_sent' => $context['task_count_sent'],
                'source' => $source,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('ai_request_failed', [
                'model' => $model,
                'scope' => $scopeName,
                'task_count_sent' => $context['task_count_sent'],
                'source' => $source,
                'message' => $exception->getMessage(),
            ]);

            $response = $this->localSnapshot($context, $scopeName);
        }

        $response = $this->redactSecrets($response);

        $imagePath = null;
        if ($wantsImage) {
            try {
                $imagePath = $this->snapshotImage->generate($context, $this->firstRecommendationLine($response));
            } catch (\Throwable $exception) {
                Log::warning('ai_snapshot_image_failed', ['message' => $exception->getMessage()]);
            }
        }

        return [
            'text' => $response,
            'context' => $context,
            'image_path' => $imagePath,
            'image_title' => 'Friday AI Snapshot - '.$scopeName,
            'image_filename' => 'friday-ai-snapshot-'.now()->format('Ymd-His').'.png',
            'model' => $model,
            'used_planner' => $usePlanner,
        ];
    }

    public function sendSlackAnswer(string $channel, string $prompt, ?User $user, SlackService $slackService, array $options = []): array
    {
        $answer = $this->answer($prompt, $user, $options + ['source' => $options['source'] ?? 'slack_text']);

        if (! empty($answer['image_path'])) {
            $upload = $slackService->sendImage(
                $channel,
                $answer['text'],
                $answer['image_path'],
                $answer['image_filename'],
                $answer['image_title'],
            );

            if (($upload['ok'] ?? false)) {
                return $answer;
            }
        }

        $slackService->sendMessage($channel, $answer['text']);

        return $answer;
    }

    public function isAiPrompt(string $text): bool
    {
        return preg_match('/^\s*(hey\s+friday|friday|ai)\b/i', $text) === 1;
    }

    private function chatResponse(string $model, string $prompt, array $context): string
    {
        $content = $this->callOpenAi($model, [
            ['role' => 'system', 'content' => $this->systemPrompt(false)],
            ['role' => 'user', 'content' => json_encode([
                'instruction' => $prompt,
                'context' => $context,
                'required_format' => ['Short summary', 'Key numbers', 'Top focus tasks', 'Risks/blockers', 'Suggested next action'],
            ], JSON_UNESCAPED_SLASHES)],
        ]);

        return trim($content) !== '' ? trim($content) : $this->localSnapshot($context, $context['scope']['name'] ?? 'All Tasks');
    }

    private function plannerResponse(string $model, string $prompt, array $context, ?int $userId, string $source): string
    {
        $raw = $this->callOpenAi($model, [
            ['role' => 'system', 'content' => $this->systemPrompt(true)],
            ['role' => 'user', 'content' => json_encode([
                'instruction' => $prompt,
                'context' => $context,
                'json_schema' => [
                    'suggestions' => [[
                        'task_id' => 'integer',
                        'current_priority' => 'string',
                        'suggested_priority' => 'string|null',
                        'current_status' => 'string',
                        'suggested_status' => 'string|null',
                        'current_due_date' => 'YYYY-MM-DD|null',
                        'suggested_due_date' => 'YYYY-MM-DD|null',
                        'reason' => 'string',
                        'confidence' => '0-100 integer|null',
                    ]],
                ],
            ], JSON_UNESCAPED_SLASHES)],
        ]);

        $payload = $this->decodeJsonPayload($raw);
        $stored = $this->recommendations->storeFromPlanner($payload['suggestions'] ?? [], $userId, $source, null, $raw);

        if ($stored->isEmpty()) {
            return $this->localSnapshot($context, $context['scope']['name'] ?? 'All Tasks')."\n\nNo task changes were queued. I will not update anything unless you approve a pending recommendation.";
        }

        return "I found {$stored->count()} suggested changes:\n\n"
            .$stored->map(function ($recommendation, int $index): string {
                return ($index + 1).". Task #{$recommendation->task_id} - {$recommendation->task?->title}\n"
                    .ucfirst($recommendation->recommendation_type).": {$recommendation->current_value} -> {$recommendation->suggested_value}\n"
                    ."Reason: {$recommendation->reason}";
            })->implode("\n\n")
            ."\n\nReply:\n- approve ai 1\n- approve ai 1,2,3\n- approve ai all\n- reject ai 1\n- reject ai all\n- show ai pending";
    }

    private function callOpenAi(string $model, array $messages): string
    {
        $response = Http::withToken((string) $this->settings->apiKey())
            ->acceptJson()
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => $messages,
                'temperature' => 0.2,
                'max_tokens' => $this->settings->maxOutputTokens(),
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('OpenAI request failed with status '.$response->status());
        }

        return (string) ($response->json('choices.0.message.content') ?? '');
    }

    private function systemPrompt(bool $planner): string
    {
        $base = 'You are Friday, Miriam taskflow AI Brain. Use only the provided focused context. Do not expose secrets. Do not claim to update tasks. Keep responses concise and useful.';

        if (! $planner) {
            return $base.' Format normal snapshots with: short summary, key numbers, top focus tasks, risks/blockers, suggested next action.';
        }

        return $base.' Return only JSON. Suggest task changes but never apply them. Use valid priorities low, medium, high, urgent and statuses todo, in_progress, blocked, review, completed, archived.';
    }

    private function localSnapshot(array $context, string $scopeName): string
    {
        $summary = $context['summary'];
        $tasks = collect($context['tasks'])->take(5)->values();
        $waiting = collect($context['waiting_delegated'])->take(5)->values();

        return "{$scopeName} Snapshot\n"
            ."Open: {$summary['open']} | Overdue: {$summary['overdue']} | Due this week: {$summary['due_this_week']} | Urgent/high: {$summary['urgent_high']}\n\n"
            ."Top focus:\n"
            .$tasks->map(fn (array $task, int $index): string => ($index + 1).'. '.$task['title'])->implode("\n")
            ."\n\nWaiting on:\n"
            .($waiting->isEmpty() ? '- None found' : $waiting->map(fn (array $task): string => '- '.$task['title'])->implode("\n"))
            ."\n\nRecommendation:\nFocus first on overdue and urgent/high work with active stakeholder visibility.";
    }

    private function decodeJsonPayload(string $raw): array
    {
        $json = trim($raw);

        if (preg_match('/```(?:json)?\s*(.*?)```/is', $json, $matches)) {
            $json = trim($matches[1]);
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : ['suggestions' => []];
    }

    private function stripPrefix(string $text): string
    {
        return trim((string) preg_replace('/^\s*(hey\s+friday|friday|ai)[,\s:]*/i', '', $text));
    }

    private function isPlannerPrompt(string $text): bool
    {
        return Str::of($text)->lower()->contains([
            'review priorities',
            'reprioritize',
            'wrongly urgent',
            'what should i focus on',
            'focus on today',
            'plan my week',
        ]);
    }

    private function wantsImage(string $text): bool
    {
        return Str::of($text)->lower()->contains(['give me image', 'snapshot', 'dashboard', 'send as image']);
    }

    private function firstRecommendationLine(string $response): string
    {
        return Str::limit(trim(preg_split('/\R+/', $response)[0] ?? $response), 150);
    }

    private function redactSecrets(string $text): string
    {
        $apiKey = $this->settings->apiKey();

        if (blank($apiKey)) {
            return $text;
        }

        return str_replace((string) $apiKey, '[redacted]', $text);
    }
}
