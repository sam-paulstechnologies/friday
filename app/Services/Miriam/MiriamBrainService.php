<?php

namespace App\Services\Miriam;

use App\Models\User;
use App\Services\SmartSlackCaptureParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class MiriamBrainService
{
    public const DEFAULT_TIMEZONE = 'Asia/Dubai';
    public const MIN_CONFIDENCE = 0.75;

    public function __construct(
        private readonly SmartSlackCaptureParser $parser,
        private readonly MiriamToolRegistry $tools,
    ) {}

    public function selectTool(string $text, ?User $user = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now(self::DEFAULT_TIMEZONE);
        $normalized = $this->normalizeConversationText($text);

        if ($normalized === '') {
            return ['intent' => 'ignore', 'tool' => null, 'confidence' => 1.0, 'arguments' => []];
        }

        if ($this->isExternalSendRequest($normalized)) {
            return [
                'intent' => 'approval_required',
                'tool' => null,
                'confidence' => 0.9,
                'risk_level' => 'medium',
                'message' => 'I can save that as a task, but sending external messages needs confirmation.',
                'arguments' => [],
            ];
        }

        if ($this->isMedicationScheduleChange($normalized)) {
            return [
                'intent' => 'approval_required',
                'tool' => null,
                'confidence' => 0.95,
                'risk_level' => 'high',
                'message' => 'Changing medication schedules needs confirmation in Miriam. I will not change it from Slack.',
                'arguments' => [],
            ];
        }

        if (Str::contains($normalized, ['what does my tomorrow look like', 'tomorrow look like', 'my tomorrow', 'agenda tomorrow', 'tomorrow agenda'])) {
            return $this->toolSelection('calendar_day_query', 'read_tomorrow_agenda');
        }

        if (Str::contains($normalized, ['what does my today look like', 'today look like', 'my today', 'agenda today', 'today agenda'])) {
            return $this->toolSelection('calendar_day_query', 'read_today_agenda');
        }

        if (Str::contains($normalized, ['show me my meds', 'my meds', 'medication status', 'medicine status', 'dose status', 'evening dose', 'morning dose'])) {
            return $this->toolSelection('health_status_query', 'read_medication_status', [
                'dose_key' => Str::contains($normalized, 'evening') ? 'evening' : (Str::contains($normalized, 'morning') ? 'morning' : null),
            ]);
        }

        if (Str::contains($normalized, ['what is pending', 'what\'s pending', 'pending tasks', 'pending reminders'])) {
            return Str::contains($normalized, 'task')
                ? $this->toolSelection('reminder_list_query', 'list_pending_tasks')
                : $this->toolSelection('reminder_list_query', 'list_reminders', ['period' => Str::contains($normalized, 'tomorrow') ? 'tomorrow' : 'today']);
        }

        if (Str::contains($normalized, ['what did codex finish', 'codex finish', 'development status', 'dev status'])) {
            return $this->toolSelection('development_status_query', 'read_development_status');
        }

        if (Str::contains($normalized, ['recent miriam activity', 'what did miriam do'])) {
            return $this->toolSelection('activity_query', 'read_recent_miriam_activity');
        }

        $items = $this->parser->parse($text, $now);
        $item = $items[0] ?? null;

        if ($item && count($items) === 1 && ! ($item['needs_clarification'] ?? false) && filled($item['title'] ?? null) && ($item['due_at'] ?? null)) {
            $dueAt = $item['due_at'] instanceof CarbonImmutable
                ? $item['due_at']
                : CarbonImmutable::parse($item['due_at'], $item['timezone'] ?? self::DEFAULT_TIMEZONE);

            if ($dueAt->gt($now) && (float) ($item['confidence'] ?? 0) >= self::MIN_CONFIDENCE) {
                $tool = in_array(($item['type'] ?? 'reminder'), ['task', 'document_task', 'note', 'follow_up'], true)
                    ? 'create_task'
                    : 'create_reminder';

                return [
                    'intent' => $tool,
                    'tool' => $tool,
                    'confidence' => (float) ($item['confidence'] ?? 1),
                    'risk_level' => (string) ($item['risk_level'] ?? 'low'),
                    'arguments' => [
                        'title' => $this->cleanToolTitle((string) $item['title']),
                        'due_at_local' => $dueAt->format('Y-m-d H:i:s'),
                        'timezone' => (string) ($item['timezone'] ?? self::DEFAULT_TIMEZONE),
                        'item_type' => (string) ($item['type'] ?? 'reminder'),
                        'category' => 'unknown',
                    ],
                ];
            }
        }

        return ['intent' => 'unclear', 'tool' => null, 'confidence' => 0.3, 'arguments' => []];
    }

    public function interpretSlackCapture(string $text, ?User $user = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now(self::DEFAULT_TIMEZONE);
        $items = $this->parser->parse($text, $now);

        if ($items !== []) {
            if (! $this->needsClarification($items)) {
                return [
                    'status' => 'created',
                    'source' => 'deterministic',
                    'items' => $items,
                    'ai_events' => [],
                ];
            }

            if ($this->hasExplicitClarificationQuestion($items)) {
                return [
                    'status' => 'needs_confirmation',
                    'source' => 'deterministic',
                    'items' => $items,
                    'ai_events' => [],
                ];
            }

            $aiCapture = $this->interpretWithAi($text, $user, $now);

            if (($aiCapture['status'] ?? 'failed') !== 'failed') {
                return $aiCapture;
            }

            return [
                'status' => 'needs_confirmation',
                'source' => 'deterministic',
                'items' => $items,
                'ai_events' => [],
            ];
        }

        return $this->interpretWithAi($text, $user, $now);
    }

    private function interpretWithAi(string $text, ?User $user, CarbonImmutable $now): array
    {
        if (! $this->enabled()) {
            return ['status' => 'failed', 'source' => 'disabled', 'items' => [], 'ai_events' => []];
        }

        $events = [[
            'event_type' => 'ai_request_created',
            'channel' => 'openai',
            'metadata' => ['model' => $this->model()],
        ]];

        try {
            $payload = $this->callResponsesApi($text, $user, $now);
            $events[] = [
                'event_type' => 'ai_response_received',
                'channel' => 'openai',
                'metadata' => [
                    'intent' => $payload['intent'] ?? null,
                    'confidence' => $payload['confidence'] ?? null,
                    'risk_level' => $payload['risk_level'] ?? null,
                ],
            ];
        } catch (\Throwable $exception) {
            Log::warning('miriam_ai_capture_failed', [
                'exception' => class_basename($exception),
            ]);

            return [
                'status' => 'failed',
                'source' => 'ai',
                'items' => [],
                'ai_events' => array_merge($events, [[
                    'event_type' => 'ai_parse_failed',
                    'channel' => 'openai',
                    'metadata' => ['exception' => class_basename($exception)],
                ]]),
            ];
        }

        $item = $this->itemFromAiPayload($payload, $text, $now);

        if ($item === null) {
            return [
                'status' => 'failed',
                'source' => 'ai',
                'items' => [],
                'ai_events' => array_merge($events, [[
                    'event_type' => 'ai_parse_failed',
                    'channel' => 'openai',
                    'metadata' => ['reason' => 'unusable_payload'],
                ]]),
            ];
        }

        if (($item['confidence'] ?? 0) < self::MIN_CONFIDENCE || ($item['needs_clarification'] ?? false)) {
            return [
                'status' => 'needs_confirmation',
                'source' => 'ai',
                'items' => [$item],
                'ai_events' => $events,
            ];
        }

        return [
            'status' => 'created',
            'source' => 'ai',
            'items' => [$item],
            'ai_events' => $events,
        ];
    }

    private function callResponsesApi(string $text, ?User $user, CarbonImmutable $now): array
    {
        $response = Http::withToken((string) config('services.miriam_ai.api_key'))
            ->acceptJson()
            ->timeout(20)
            ->post('https://api.openai.com/v1/responses', [
                'model' => $this->model(),
                'input' => [
                    [
                        'role' => 'system',
                        'content' => [[
                            'type' => 'input_text',
                            'text' => $this->systemPrompt(),
                        ]],
                    ],
                    [
                        'role' => 'user',
                        'content' => [[
                            'type' => 'input_text',
                            'text' => json_encode([
                                'message' => $text,
                                'timezone' => self::DEFAULT_TIMEZONE,
                                'now_local' => $now->format('Y-m-d H:i:s'),
                                'user_id' => $user?->id,
                            ], JSON_UNESCAPED_SLASHES),
                        ]],
                    ],
                ],
                'reasoning' => ['effort' => config('services.miriam_ai.reasoning_effort', 'low')],
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'miriam_slack_capture',
                        'strict' => true,
                        'schema' => $this->schema(),
                    ],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('OpenAI Responses request failed with status '.$response->status());
        }

        $json = $response->json('output_text')
            ?? $response->json('output.0.content.0.text')
            ?? $response->json('output.1.content.0.text');

        $decoded = json_decode((string) $json, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI Responses returned invalid JSON.');
        }

        return $decoded;
    }

    private function itemFromAiPayload(array $payload, string $originalText, CarbonImmutable $now): ?array
    {
        $intent = (string) ($payload['intent'] ?? 'unclear');

        if (in_array($intent, ['ignore', 'unclear'], true) && ! ($payload['needs_clarification'] ?? false)) {
            return null;
        }

        $timezone = (string) ($payload['timezone'] ?? self::DEFAULT_TIMEZONE);
        $dueAt = null;

        if (filled($payload['due_at_local'] ?? null) && $payload['due_at_local'] !== 'null') {
            $dueAt = CarbonImmutable::parse((string) $payload['due_at_local'], $timezone);

            if ($dueAt->lte($now)) {
                return [
                    'title' => $this->title($payload, $originalText),
                    'due_at' => null,
                    'timezone' => $timezone,
                    'type' => $this->typeForIntent($intent),
                    'source' => 'slack_ai',
                    'original_text' => $originalText,
                    'confidence' => min((float) ($payload['confidence'] ?? 0.5), 0.74),
                    'needs_clarification' => true,
                    'clarification' => 'I understood the task, but the time looks like it is in the past. What future time should I use?',
                    'ai_payload' => $this->safePayload($payload),
                ];
            }
        }

        return [
            'title' => $this->title($payload, $originalText),
            'description' => (string) ($payload['description'] ?? ''),
            'due_at' => $dueAt,
            'timezone' => $timezone,
            'type' => $this->typeForIntent($intent),
            'source' => 'slack_ai',
            'original_text' => $originalText,
            'confidence' => (float) ($payload['confidence'] ?? 0),
            'needs_clarification' => (bool) ($payload['needs_clarification'] ?? false),
            'clarification' => $payload['clarification_question'] ?? null,
            'should_create_calendar_event' => (bool) ($payload['should_create_calendar_event'] ?? true),
            'risk_level' => (string) ($payload['risk_level'] ?? 'low'),
            'ai_payload' => $this->safePayload($payload),
        ];
    }

    private function title(array $payload, string $fallback): string
    {
        return trim((string) ($payload['title'] ?? '')) ?: Str::limit($fallback, 120, '');
    }

    private function needsClarification(array $items): bool
    {
        return collect($items)->contains(fn (array $item): bool => (float) ($item['confidence'] ?? 0) < self::MIN_CONFIDENCE
            || (bool) ($item['needs_clarification'] ?? false)
            || ! ($item['due_at'] ?? null));
    }

    private function hasExplicitClarificationQuestion(array $items): bool
    {
        return collect($items)->contains(fn (array $item): bool => filled($item['clarification'] ?? null));
    }

    private function enabled(): bool
    {
        return (bool) config('services.miriam_ai.enabled') && filled(config('services.miriam_ai.api_key'));
    }

    private function model(): string
    {
        return (string) config('services.miriam_ai.model', 'gpt-5.4-mini');
    }

    private function typeForIntent(string $intent): string
    {
        return match ($intent) {
            'create_task', 'create_calendar_event' => 'task',
            'answer_clarification' => 'reminder',
            default => 'reminder',
        };
    }

    private function safePayload(array $payload): array
    {
        return collect($payload)
            ->except(['api_key', 'token', 'secret'])
            ->all();
    }

    private function toolSelection(string $intent, string $tool, array $arguments = []): array
    {
        return [
            'intent' => $intent,
            'tool' => $this->tools->get($tool) ? $tool : null,
            'confidence' => 1.0,
            'risk_level' => 'low',
            'arguments' => array_filter($arguments, fn ($value) => $value !== null),
        ];
    }

    private function isExternalSendRequest(string $normalized): bool
    {
        return preg_match('/\b(send|message|email|whatsapp)\b.+\b(now|right now|immediately|saying|tell|asking)\b/i', $normalized) === 1
            && ! preg_match('/\b(tomorrow|today|tonight|in \d+ minutes?|at \d{1,2})\b/i', $normalized);
    }

    private function isMedicationScheduleChange(string $normalized): bool
    {
        return Str::contains($normalized, ['change my medication', 'change medication', 'move my dose', 'change my dose', 'medication schedule']);
    }

    private function normalizeConversationText(string $text): string
    {
        return trim((string) Str::of($text)
            ->lower()
            ->replaceMatches('/<@[a-z0-9]+>/i', '')
            ->replaceMatches('/^@miriam[:,]?\s*/i', '')
            ->replaceMatches('/^miriam[:,]?\s*/i', '')
            ->replaceMatches('/\s+/', ' '));
    }

    private function cleanToolTitle(string $title): string
    {
        return trim((string) Str::of($title)
            ->replaceMatches('/^remind me\s+(?:to\s+)?/i', '')
            ->ucfirst());
    }

    private function systemPrompt(): string
    {
        return 'You are Miriam AI Brain for Slack capture. Return strict JSON only. Extract one low-risk task/reminder/calendar intent. Use Asia/Dubai. Never create times in the past. Ambiguous "tomorrow at 9" needs AM/PM clarification. External send/message/email requests are reminders/tasks only; do not send anything.';
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'intent',
                'title',
                'description',
                'due_at_local',
                'timezone',
                'confidence',
                'needs_clarification',
                'clarification_question',
                'risk_level',
                'should_create_calendar_event',
            ],
            'properties' => [
                'intent' => ['type' => 'string', 'enum' => ['create_reminder', 'create_task', 'create_calendar_event', 'answer_clarification', 'unclear', 'ignore']],
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'due_at_local' => ['type' => ['string', 'null']],
                'timezone' => ['type' => 'string', 'enum' => [self::DEFAULT_TIMEZONE]],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'needs_clarification' => ['type' => 'boolean'],
                'clarification_question' => ['type' => ['string', 'null']],
                'risk_level' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                'should_create_calendar_event' => ['type' => 'boolean'],
            ],
        ];
    }
}
