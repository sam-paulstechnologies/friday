<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class SmartSlackCaptureParser
{
    public const DEFAULT_TIMEZONE = 'Asia/Dubai';

    public function parse(string $message, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now(self::DEFAULT_TIMEZONE);

        return collect($this->segments($this->stripMentions($message)))
            ->map(fn (string $segment): ?array => $this->parseSegment($segment, $now))
            ->filter()
            ->values()
            ->all();
    }

    private function parseSegment(string $segment, CarbonImmutable $now): ?array
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $segment));
        $lower = Str::lower($normalized);

        if ($normalized === '') {
            return null;
        }

        $time = $this->extractTime($lower, $now);
        $type = $this->itemType($lower);
        $title = $this->titleFor($normalized, $lower);
        $confidence = $this->confidence($lower, $time !== null);

        if ($confidence < 0.35) {
            return null;
        }

        return [
            'title' => $title,
            'due_at' => $time,
            'type' => $type,
            'source' => 'slack',
            'original_text' => $normalized,
            'confidence' => $confidence,
        ];
    }

    private function segments(string $message): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", trim($message));
        $segments = preg_split('/\n+|(?<=[.!?])\s+(?=(?:tomorrow|tonight|today|next\s+\w+|remind|message|call|ping|prepare|follow|create|add|i\s+need)\b)/i', $normalized) ?: [];

        return collect($segments)
            ->map(fn (string $segment): string => trim($segment, " \t\n\r\0\x0B-."))
            ->filter()
            ->values()
            ->all();
    }

    private function stripMentions(string $text): string
    {
        return trim(Str::of($text)
            ->replaceMatches('/<@[A-Z0-9]+>/i', '')
            ->replaceMatches('/^@miriam[:,]?\s*/i', '')
            ->replaceMatches('/^miriam[:,]?\s*/i', '')
            ->toString());
    }

    private function extractTime(string $lower, CarbonImmutable $now): ?CarbonImmutable
    {
        if (preg_match('/\bin\s+(\d+)\s+minutes?\b/', $lower, $matches)) {
            return $now->addMinutes(max(1, (int) $matches[1]));
        }

        if (preg_match('/\btomorrow\s+(?:at\s+)?(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\b/', $lower, $matches)) {
            return $this->timeOn($now->addDay(), (int) $matches[1], (int) ($matches[2] ?? 0), $matches[3] ?? null);
        }

        if (preg_match('/\bat\s+(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\b/', $lower, $matches)) {
            return $this->timeOn($now, (int) $matches[1], (int) ($matches[2] ?? 0), $matches[3] ?? null);
        }

        if (str_contains($lower, 'tomorrow morning')) {
            return $now->addDay()->setTime(9, 0);
        }

        if (str_contains($lower, 'tomorrow afternoon')) {
            return $now->addDay()->setTime(15, 0);
        }

        if (str_contains($lower, 'tomorrow evening')) {
            return $now->addDay()->setTime(19, 0);
        }

        if (str_contains($lower, 'tonight')) {
            return $now->setTime(21, 0);
        }

        if (str_contains($lower, 'afternoon')) {
            return $now->setTime(15, 0);
        }

        if (str_contains($lower, 'evening')) {
            return $now->setTime(19, 0);
        }

        if (preg_match('/\bnext\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/', $lower, $matches)) {
            return $now->next($matches[1])->setTime(9, 0);
        }

        if (str_contains($lower, 'tomorrow')) {
            return $now->addDay()->setTime(9, 0);
        }

        return null;
    }

    private function timeOn(CarbonImmutable $date, int $hour, int $minute, ?string $meridiem): CarbonImmutable
    {
        if ($meridiem === 'pm' && $hour < 12) {
            $hour += 12;
        }

        if (($meridiem === null || $meridiem === '') && $hour < 8) {
            $hour += 12;
        }

        if ($meridiem === 'am' && $hour === 12) {
            $hour = 0;
        }

        return $date->setTime($hour, $minute);
    }

    private function itemType(string $lower): string
    {
        return match (true) {
            str_contains($lower, 'prepare') && str_contains($lower, 'document') => 'document_task',
            str_contains($lower, 'follow up') || str_contains($lower, 'follow-up') => 'follow_up',
            str_contains($lower, 'create a note') || str_contains($lower, 'note for') => 'note',
            str_contains($lower, 'add task') || str_contains($lower, 'i need to') || str_contains($lower, 'prepare') => 'task',
            default => 'reminder',
        };
    }

    private function titleFor(string $original, string $lower): string
    {
        $title = preg_replace('/^remind me to\s+/i', '', $original);
        $title = preg_replace('/^tomorrow remind me to\s+/i', '', $title);
        $title = preg_replace('/^i need to\s+/i', '', $title);
        $title = preg_replace('/^add task to\s+/i', '', $title);
        $title = preg_replace('/^create a note for\s+/i', 'Note: ', $title);
        $title = preg_replace('/\s+in\s+\d+\s+minutes?\b/i', ' ', $title);
        $title = preg_replace('/\s+tomorrow\s+(?:at\s+)?\d{1,2}(?::\d{2})?\s*(?:am|pm)?\b/i', ' ', $title);
        $title = preg_replace('/\s+at\s+\d{1,2}(?::\d{2})?\s*(?:am|pm)?\b/i', ' ', $title);
        $title = preg_replace('/\btonight\b/i', ' ', $title);
        $title = preg_replace('/\btomorrow\s+(morning|afternoon|evening)\b/i', ' ', $title);
        $title = preg_replace('/\bnext\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i', ' ', $title);

        return Str::of($title ?: $original)
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->ucfirst()
            ->toString();
    }

    private function confidence(string $lower, bool $hasTime): float
    {
        $action = Str::contains($lower, ['remind', 'message', 'call', 'ping', 'prepare', 'follow up', 'create a note', 'add task', 'i need to']);

        return match (true) {
            $action && $hasTime => 0.92,
            $action => 0.68,
            $hasTime => 0.42,
            default => 0.0,
        };
    }
}
