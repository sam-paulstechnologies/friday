<?php

namespace App\Services;

use App\Models\MiriamReminder;
use App\Models\User;
use App\Services\Calendar\CalendarSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MiriamReminderService
{
    public const DEFAULT_TIMEZONE = 'Asia/Dubai';
    private const HIGH_CONFIDENCE = 0.8;

    public function __construct(
        private readonly SmartSlackCaptureParser $smartParser,
        private readonly CalendarSyncService $calendarSyncService,
    ) {}

    public function parse(string $text, ?CarbonImmutable $now = null): ?array
    {
        $now ??= CarbonImmutable::now(self::DEFAULT_TIMEZONE);
        $normalized = $this->normalizeReminderText($text);

        if (! str_starts_with($normalized, 'remind me ')) {
            return null;
        }

        if (preg_match('/^remind me in (\d+) minutes? to (.+)$/i', $normalized, $matches)) {
            $minutes = max(1, (int) $matches[1]);
            $title = $this->cleanTitle($matches[2]);

            return [
                'title' => $title,
                'due_at' => $now->addMinutes($minutes),
                'timezone' => self::DEFAULT_TIMEZONE,
                'category' => $this->categoryFor($title),
            ];
        }

        if (preg_match('/^remind me to (.+?) in (\d+) minutes?$/i', $normalized, $matches)) {
            $title = $this->cleanTitle($matches[1]);
            $minutes = max(1, (int) $matches[2]);

            return [
                'title' => $title,
                'due_at' => $now->addMinutes($minutes),
                'timezone' => self::DEFAULT_TIMEZONE,
                'category' => $this->categoryFor($title),
            ];
        }

        if (preg_match('/^remind me to (.+?) at (.+?) today$/i', $normalized, $matches)) {
            $title = $this->cleanTitle($matches[1]);
            $dueAt = $this->parseTimeOnDate($matches[2], $now);

            return [
                'title' => $title,
                'due_at' => $dueAt,
                'timezone' => self::DEFAULT_TIMEZONE,
                'category' => $this->categoryFor($title),
            ];
        }

        if (preg_match('/^remind me to (.+?) tomorrow (.+)$/i', $normalized, $matches)) {
            $title = $this->cleanTitle($matches[1]);
            $dueAt = $this->parseTimeOnDate($matches[2], $now->addDay());

            return [
                'title' => $title,
                'due_at' => $dueAt,
                'timezone' => self::DEFAULT_TIMEZONE,
                'category' => $this->categoryFor($title),
            ];
        }

        return null;
    }

    public function captureFromSlack(string $text, string $slackUserId, string $channelId, ?string $messageTs = null, ?User $user = null): ?MiriamReminder
    {
        $parsed = $this->parse($text);

        if (! $parsed) {
            return null;
        }

        if ($messageTs) {
            $existing = MiriamReminder::query()->where('source_message_ts', $messageTs)->first();

            if ($existing) {
                return $existing;
            }
        }

        return $this->createFromParsedItem($parsed + [
            'type' => 'reminder',
            'source' => 'slack',
            'original_text' => $text,
            'confidence' => 1.0,
        ], $slackUserId, $channelId, $messageTs, $user);
    }

    public function captureSmartFromSlack(string $text, string $slackUserId, string $channelId, ?string $messageTs = null, ?User $user = null): array
    {
        $items = $this->smartParser->parse($text);

        if ($items === []) {
            return ['status' => 'failed', 'items' => [], 'reminders' => collect()];
        }

        if (collect($items)->contains(fn (array $item): bool => (float) $item['confidence'] < self::HIGH_CONFIDENCE)) {
            return ['status' => 'needs_confirmation', 'items' => $items, 'reminders' => collect()];
        }

        $reminders = collect($items)
            ->map(fn (array $item, int $index): MiriamReminder => $this->createFromParsedItem(
                $item,
                $slackUserId,
                $channelId,
                $messageTs ? "{$messageTs}:{$index}" : null,
                $user,
            ));

        return ['status' => 'created', 'items' => $items, 'reminders' => $reminders];
    }

    public function sendConfirmation(MiriamReminder $reminder): array
    {
        return $this->sendSlack(
            $reminder->slack_channel_id,
            sprintf(
                'Saved reminder: %s at %s.',
                $reminder->title,
                $reminder->due_at->setTimezone($reminder->timezone)->format('M j, g:i A')
            )
        );
    }

    public function sendCaptureSummary(string $channel, Collection $reminders): array
    {
        $lines = $reminders->values()->map(function (MiriamReminder $reminder, int $index): string {
            $when = $this->summaryTime($reminder);

            return ($index + 1).". {$when} — {$reminder->title}";
        })->implode("\n");

        return $this->sendSlack($channel, "Captured {$reminders->count()} ".Str::plural('item', $reminders->count()).":\n{$lines}");
    }

    public function sendClarification(string $channel, array $items): array
    {
        return $this->sendSlack(
            $channel,
            'I found '.count($items).' possible '.Str::plural('task', count($items)).'. Should I save them?'
        );
    }

    public function sendParseHelp(string $channel): array
    {
        return $this->sendSlack(
            $channel,
            'I can save reminders like: Remind me to call Jasion in 5 minutes.'
        );
    }

    public function sendDueReminders(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');
        $sent = 0;

        MiriamReminder::query()
            ->whereIn('status', ['pending', 'snoozed'])
            ->where('next_reminder_at', '<=', $now->utc())
            ->orderBy('next_reminder_at')
            ->get()
            ->each(function (MiriamReminder $reminder) use ($now, &$sent): void {
                if ($reminder->last_sent_at && $reminder->last_sent_at->greaterThanOrEqualTo($reminder->next_reminder_at)) {
                    return;
                }

                $result = $this->sendSlack(
                    $reminder->slack_channel_id,
                    "Miriam reminder: {$reminder->title}",
                    $this->dueReminderBlocks($reminder)
                );

                $attempt = $reminder->reminder_attempts + 1;

                $reminder->forceFill([
                    'status' => 'pending',
                    'reminder_attempts' => $attempt,
                    'last_sent_at' => $now->utc(),
                    'next_reminder_at' => $now->utc()->addMinutes(15),
                ])->save();

                $this->recordEvent($reminder, ($result['ok'] ?? false) ? 'slack_reminder_sent' : 'slack_reminder_failed', 'slack', [
                    'attempt' => $attempt,
                    'slack_error' => $result['error'] ?? null,
                ]);

                $sent++;
            });

        return $sent;
    }

    public function markDone(MiriamReminder $reminder, string $slackUserId): MiriamReminder
    {
        if ($reminder->status !== 'done') {
            $reminder->forceFill([
                'status' => 'done',
                'completed_at' => CarbonImmutable::now('UTC'),
                'next_reminder_at' => null,
            ])->save();
        }

        $this->recordEvent($reminder, 'done_clicked', 'slack', ['slack_user_id' => $slackUserId]);

        return $reminder;
    }

    public function snooze(MiriamReminder $reminder, string $slackUserId, int $minutes = 15): MiriamReminder
    {
        if (! in_array($reminder->status, ['done', 'cancelled'], true)) {
            $reminder->forceFill([
                'status' => 'snoozed',
                'next_reminder_at' => CarbonImmutable::now('UTC')->addMinutes($minutes),
            ])->save();
        }

        $this->recordEvent($reminder, 'snooze_clicked', 'slack', [
            'slack_user_id' => $slackUserId,
            'minutes' => $minutes,
        ]);

        return $reminder;
    }

    public function cancel(MiriamReminder $reminder, string $slackUserId): MiriamReminder
    {
        if ($reminder->status !== 'cancelled') {
            $reminder->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => CarbonImmutable::now('UTC'),
                'next_reminder_at' => null,
            ])->save();
        }

        $this->recordEvent($reminder, 'cancel_clicked', 'slack', ['slack_user_id' => $slackUserId]);

        return $reminder;
    }

    public function handleSlackAction(MiriamReminder $reminder, string $action, string $slackUserId, array $payload = []): string
    {
        $message = match ($action) {
            'miriam_reminder_done' => $this->handleDoneAction($reminder, $slackUserId),
            'miriam_reminder_snooze_15' => $this->handleSnoozeAction($reminder, $slackUserId),
            'miriam_reminder_cancel' => $this->handleCancelAction($reminder, $slackUserId),
            default => 'Unknown Miriam reminder action.',
        };

        if ($message !== 'Unknown Miriam reminder action.') {
            $this->updateSlackActionMessage($payload, $message);
        }

        return $message;
    }

    public function recordEvent(MiriamReminder $reminder, string $type, ?string $channel = null, array $metadata = []): void
    {
        $reminder->events()->create([
            'event_type' => $type,
            'channel' => $channel,
            'occurred_at' => CarbonImmutable::now('UTC'),
            'metadata' => array_filter($metadata, fn ($value) => $value !== null && $value !== ''),
        ]);
    }

    private function createFromParsedItem(array $item, string $slackUserId, string $channelId, ?string $messageTs, ?User $user): MiriamReminder
    {
        $dueAt = $item['due_at'] instanceof CarbonImmutable
            ? $item['due_at']
            : CarbonImmutable::parse($item['due_at'], $item['timezone'] ?? self::DEFAULT_TIMEZONE);

        $reminder = MiriamReminder::create([
            'user_id' => $user?->id,
            'category' => $this->categoryFor($item['title']),
            'item_type' => $item['type'] ?? 'reminder',
            'title' => $item['title'],
            'timezone' => $item['timezone'] ?? self::DEFAULT_TIMEZONE,
            'confidence' => $item['confidence'] ?? 1,
            'due_at' => $dueAt->utc(),
            'status' => 'pending',
            'next_reminder_at' => $dueAt->utc(),
            'slack_user_id' => $slackUserId,
            'slack_channel_id' => $channelId,
            'source_message_ts' => $messageTs,
            'metadata' => [
                'source' => $item['source'] ?? 'slack',
                'original_text' => $item['original_text'] ?? null,
            ],
        ]);

        $this->recordEvent($reminder, 'captured', 'slack', [
            'slack_user_id' => $slackUserId,
            'source_message_ts' => $messageTs,
            'item_type' => $reminder->item_type,
            'confidence' => $reminder->confidence,
        ]);

        $this->calendarSyncService->syncMiriamReminder($reminder);

        return $reminder->fresh() ?: $reminder;
    }

    private function summaryTime(MiriamReminder $reminder): string
    {
        $local = $reminder->due_at->setTimezone($reminder->timezone);
        $today = CarbonImmutable::now($reminder->timezone)->toDateString();

        if ($local->toDateString() === $today && $local->format('H:i') === '21:00') {
            return 'Tonight '.$local->format('g:i A');
        }

        if ($local->toDateString() === CarbonImmutable::now($reminder->timezone)->addDay()->toDateString()) {
            return 'Tomorrow '.$local->format('g:i A');
        }

        return $local->format('M j, g:i A');
    }

    private function handleDoneAction(MiriamReminder $reminder, string $slackUserId): string
    {
        if ($reminder->status === 'cancelled') {
            return $this->actionStatusMessage($reminder->fresh() ?: $reminder);
        }

        if ($reminder->status !== 'done') {
            $this->markDone($reminder, $slackUserId);
        }

        return $this->actionStatusMessage($reminder->fresh() ?: $reminder);
    }

    private function handleSnoozeAction(MiriamReminder $reminder, string $slackUserId): string
    {
        if (in_array($reminder->status, ['done', 'cancelled'], true)) {
            return $this->actionStatusMessage($reminder->fresh() ?: $reminder);
        }

        $this->snooze($reminder, $slackUserId, 15);

        return $this->actionStatusMessage($reminder->fresh() ?: $reminder);
    }

    private function handleCancelAction(MiriamReminder $reminder, string $slackUserId): string
    {
        if ($reminder->status === 'done') {
            return $this->actionStatusMessage($reminder->fresh() ?: $reminder);
        }

        if ($reminder->status !== 'cancelled') {
            $this->cancel($reminder, $slackUserId);
        }

        return $this->actionStatusMessage($reminder->fresh() ?: $reminder);
    }

    private function actionStatusMessage(MiriamReminder $reminder): string
    {
        return match ($reminder->status) {
            'done' => "✅ Done — {$reminder->title}",
            'cancelled' => "🛑 Cancelled — {$reminder->title}",
            'snoozed' => '⏰ Snoozed until '.$reminder->next_reminder_at?->setTimezone($reminder->timezone)->format('g:i A')." — {$reminder->title}",
            default => "Miriam reminder: {$reminder->title}",
        };
    }

    private function updateSlackActionMessage(array $payload, string $message): void
    {
        $blocks = [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => $message,
                ],
            ],
        ];

        $responseUrl = (string) ($payload['response_url'] ?? '');

        if (filled($responseUrl)) {
            try {
                Http::asJson()->timeout(5)->post($responseUrl, [
                    'replace_original' => true,
                    'response_type' => 'in_channel',
                    'text' => $message,
                    'blocks' => $blocks,
                ]);
            } catch (\Throwable) {
                //
            }

            return;
        }

        $token = config('services.slack.bot_token');
        $channel = (string) data_get($payload, 'channel.id', '');
        $ts = (string) data_get($payload, 'message.ts', '');

        if (! filled($token) || ! filled($channel) || ! filled($ts)) {
            return;
        }

        try {
            Http::withToken($token)
                ->acceptJson()
                ->timeout(5)
                ->post('https://slack.com/api/chat.update', [
                    'channel' => $channel,
                    'ts' => $ts,
                    'text' => $message,
                    'blocks' => $blocks,
                ]);
        } catch (\Throwable) {
            //
        }
    }

    private function parseTimeOnDate(string $time, CarbonImmutable $date): CarbonImmutable
    {
        $time = trim($time);
        $parsed = CarbonImmutable::parse($date->toDateString().' '.$time, self::DEFAULT_TIMEZONE);

        return $date->setTime((int) $parsed->format('H'), (int) $parsed->format('i'));
    }

    private function normalizeReminderText(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::of($text)
            ->lower()
            ->replaceMatches('/<@[a-z0-9]+>/i', '')
            ->replaceMatches('/^@miriam[:,]?\s*/i', '')
            ->replaceMatches('/^miriam[:,]?\s*/i', '')
            ->toString()));
    }

    private function cleanTitle(string $title): string
    {
        return trim(Str::of($title)->replaceMatches('/\s+/', ' ')->toString(), " \t\n\r\0\x0B.");
    }

    private function categoryFor(string $title): string
    {
        $value = Str::lower($title);

        return match (true) {
            Str::contains($value, ['medicine', 'medication', 'dose', 'tablet', 'injection']) => 'medication',
            Str::contains($value, ['codex', 'deploy', 'release', 'development', 'churchforce', 'catererhq']) => 'development',
            Str::contains($value, ['sunny', 'family', 'mom', 'dad', 'wife', 'son', 'daughter']) => 'family',
            Str::contains($value, ['client', 'invoice', 'meeting', 'work']) => 'work',
            default => 'personal',
        };
    }

    private function dueReminderBlocks(MiriamReminder $reminder): array
    {
        return [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "Miriam reminder: {$reminder->title}",
                ],
            ],
            [
                'type' => 'actions',
                'elements' => [
                    [
                        'type' => 'button',
                        'style' => 'primary',
                        'text' => ['type' => 'plain_text', 'text' => 'Done'],
                        'action_id' => 'miriam_reminder_done',
                        'value' => (string) $reminder->id,
                    ],
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Snooze 15 min'],
                        'action_id' => 'miriam_reminder_snooze_15',
                        'value' => (string) $reminder->id,
                    ],
                    [
                        'type' => 'button',
                        'style' => 'danger',
                        'text' => ['type' => 'plain_text', 'text' => 'Cancel'],
                        'action_id' => 'miriam_reminder_cancel',
                        'value' => (string) $reminder->id,
                    ],
                ],
            ],
        ];
    }

    private function sendSlack(?string $channel, string $text, array $blocks = []): array
    {
        $token = config('services.slack.bot_token');
        $targetChannel = $this->miriamChannel($channel);

        if (! filled($token) || ! filled($targetChannel)) {
            return ['ok' => false, 'error' => 'missing_slack_configuration'];
        }

        $payload = [
            'channel' => $targetChannel,
            'text' => $text,
        ];

        if ($blocks !== []) {
            $payload['blocks'] = $blocks;
        }

        try {
            return Http::withToken($token)
                ->acceptJson()
                ->post('https://slack.com/api/chat.postMessage', $payload)
                ->json() ?? ['ok' => false, 'error' => 'empty_slack_response'];
        } catch (\Throwable $exception) {
            return ['ok' => false, 'error' => class_basename($exception)];
        }
    }

    private function miriamChannel(?string $fallback = null): ?string
    {
        return config('services.slack.miriam_channel_id')
            ?: env('SLACK_MIRIAM_CHANNEL_ID')
            ?: $fallback
            ?: config('services.slack.default_channel');
    }
}
