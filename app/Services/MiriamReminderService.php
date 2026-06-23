<?php

namespace App\Services;

use App\Models\MiriamReminder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MiriamReminderService
{
    public const DEFAULT_TIMEZONE = 'Asia/Dubai';

    public function parse(string $text, ?CarbonImmutable $now = null): ?array
    {
        $now ??= CarbonImmutable::now(self::DEFAULT_TIMEZONE);
        $normalized = trim(preg_replace('/\s+/', ' ', Str::of($text)->lower()->replaceMatches('/^miriam,?\s*/', '')->toString()));

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

        $reminder = MiriamReminder::create([
            'user_id' => $user?->id,
            'category' => $parsed['category'],
            'title' => $parsed['title'],
            'timezone' => $parsed['timezone'],
            'due_at' => $parsed['due_at']->utc(),
            'status' => 'pending',
            'next_reminder_at' => $parsed['due_at']->utc(),
            'slack_user_id' => $slackUserId,
            'slack_channel_id' => $channelId,
            'source_message_ts' => $messageTs,
            'metadata' => [
                'source' => 'slack_event',
                'original_text' => $text,
            ],
        ]);

        $this->recordEvent($reminder, 'captured', 'slack', [
            'slack_user_id' => $slackUserId,
            'source_message_ts' => $messageTs,
        ]);

        return $reminder;
    }

    public function sendConfirmation(MiriamReminder $reminder): array
    {
        return $this->sendSlack(
            $reminder->slack_channel_id,
            sprintf(
                'Reminder saved: %s at %s.',
                $reminder->title,
                $reminder->due_at->setTimezone($reminder->timezone)->format('M j, g:i A')
            )
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

    public function recordEvent(MiriamReminder $reminder, string $type, ?string $channel = null, array $metadata = []): void
    {
        $reminder->events()->create([
            'event_type' => $type,
            'channel' => $channel,
            'occurred_at' => CarbonImmutable::now('UTC'),
            'metadata' => array_filter($metadata, fn ($value) => $value !== null && $value !== ''),
        ]);
    }

    private function parseTimeOnDate(string $time, CarbonImmutable $date): CarbonImmutable
    {
        $time = trim($time);
        $parsed = CarbonImmutable::parse($date->toDateString().' '.$time, self::DEFAULT_TIMEZONE);

        return $date->setTime((int) $parsed->format('H'), (int) $parsed->format('i'));
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
