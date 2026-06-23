<?php

namespace App\Services\Miriam;

use App\Models\CalendarEventMapping;
use App\Models\MedicationDoseLog;
use App\Models\MiriamReminder;
use App\Models\MiriamSlackConversationContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MiriamSlackConversationRouter
{
    public const DEFAULT_TIMEZONE = 'Asia/Dubai';

    public function route(string $text, string $slackUserId, string $channelId, ?User $user = null): array
    {
        $intent = $this->classify($text);

        return match ($intent) {
            'calendar_day_query' => $this->tomorrowAgenda($slackUserId, $channelId, $user),
            'reminder_list_query' => $this->reminderList($text, $slackUserId, $channelId, $user),
            'health_status_query' => $this->healthStatus($text, $slackUserId, $channelId, $user),
            'show_last_result' => $this->showLastResult($slackUserId, $channelId),
            'general_question', 'unclear' => [
                'handled' => true,
                'intent' => $intent,
                'text' => 'I can show your agenda, list reminders, or save a reminder. What would you like to see or create?',
            ],
            'ignore' => ['handled' => true, 'intent' => $intent, 'text' => ''],
            default => ['handled' => false, 'intent' => $intent],
        };
    }

    public function classify(string $text): string
    {
        $normalized = $this->normalize($text);

        if ($normalized === '') {
            return 'ignore';
        }

        if (in_array($normalized, ['am', 'a.m.', 'pm', 'p.m.', 'morning', 'evening', 'night'], true)) {
            return 'answer_clarification';
        }

        if (in_array($normalized, ['show me', 'show', 'details', 'show details'], true)) {
            return 'show_last_result';
        }

        if (Str::contains($normalized, ['what does my tomorrow look like', 'tomorrow look like', 'my tomorrow', 'agenda tomorrow', 'tomorrow agenda'])) {
            return 'calendar_day_query';
        }

        if (Str::contains($normalized, ['what reminders', 'my reminders', 'what is pending', 'what\'s pending', 'pending reminders'])) {
            return 'reminder_list_query';
        }

        if (Str::contains($normalized, ['dose status', 'medication status', 'medicine status', 'evening dose', 'morning dose'])) {
            return 'health_status_query';
        }

        if (preg_match('/\b(remind me|message|call|ping|prepare|follow up|follow-up|create a note|add task|i need to)\b/i', $normalized)) {
            return Str::contains($normalized, ['document', 'prepare', 'add task', 'i need to']) ? 'create_task' : 'create_reminder';
        }

        if (Str::endsWith($normalized, '?') || Str::startsWith($normalized, ['what ', 'how ', 'when ', 'where ', 'why ', 'who '])) {
            return 'general_question';
        }

        return 'unclear';
    }

    private function tomorrowAgenda(string $slackUserId, string $channelId, ?User $user): array
    {
        $timezone = self::DEFAULT_TIMEZONE;
        $start = CarbonImmutable::now($timezone)->addDay()->startOfDay();
        $end = $start->endOfDay();
        $reminders = $this->remindersBetween($user, $start, $end);
        $events = $this->calendarEventsBetween($user, $start, $end);

        $summary = $this->agendaSummary('Tomorrow', $events, $reminders);
        $detail = $this->agendaDetail('Tomorrow', $events, $reminders);

        $this->storeContext($slackUserId, $channelId, $user, 'agenda', $summary, $detail, [
            'date' => $start->toDateString(),
            'event_count' => $events->count(),
            'reminder_count' => $reminders->count(),
        ]);

        return ['handled' => true, 'intent' => 'calendar_day_query', 'text' => $summary];
    }

    private function reminderList(string $text, string $slackUserId, string $channelId, ?User $user): array
    {
        $timezone = self::DEFAULT_TIMEZONE;
        $normalized = $this->normalize($text);
        $start = Str::contains($normalized, 'tomorrow')
            ? CarbonImmutable::now($timezone)->addDay()->startOfDay()
            : CarbonImmutable::now($timezone)->startOfDay();
        $end = $start->endOfDay();
        $reminders = $this->remindersBetween($user, $start, $end);
        $label = $start->isSameDay(CarbonImmutable::now($timezone)->addDay()) ? 'Tomorrow' : 'Today';
        $summary = $reminders->isEmpty()
            ? "{$label}: no Miriam reminders found."
            : "{$label} reminders:\n".$reminders->map(fn (MiriamReminder $reminder): string => '- '.$this->localTime($reminder->due_at, $timezone).' - '.$reminder->title)->implode("\n");

        $this->storeContext($slackUserId, $channelId, $user, 'reminders', $summary, $summary, [
            'date' => $start->toDateString(),
            'reminder_count' => $reminders->count(),
        ]);

        return ['handled' => true, 'intent' => 'reminder_list_query', 'text' => $summary];
    }

    private function healthStatus(string $text, string $slackUserId, string $channelId, ?User $user): array
    {
        if (! $user) {
            return ['handled' => true, 'intent' => 'health_status_query', 'text' => 'I could not find your Miriam user for health status.'];
        }

        $timezone = self::DEFAULT_TIMEZONE;
        $today = CarbonImmutable::now($timezone)->toDateString();
        $doseKey = Str::contains($this->normalize($text), 'evening') ? 'evening' : (Str::contains($this->normalize($text), 'morning') ? 'morning' : null);
        $logs = MedicationDoseLog::query()
            ->with('schedule')
            ->where('user_id', $user->id)
            ->whereDate('dose_date', $today)
            ->get()
            ->filter(fn (MedicationDoseLog $log): bool => ! $doseKey || $log->schedule?->dose_key === $doseKey)
            ->values();

        $message = $logs->isEmpty()
            ? 'No medication dose log found for that status today.'
            : $logs->map(fn (MedicationDoseLog $log): string => ($log->schedule?->label ?: ucfirst((string) $log->schedule?->dose_key)).': '.$log->status)->implode("\n");

        $this->storeContext($slackUserId, $channelId, $user, 'health_status', $message, $message, ['dose_key' => $doseKey]);

        return ['handled' => true, 'intent' => 'health_status_query', 'text' => $message];
    }

    private function showLastResult(string $slackUserId, string $channelId): array
    {
        $context = MiriamSlackConversationContext::query()
            ->where('slack_user_id', $slackUserId)
            ->where('slack_channel_id', $channelId)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', CarbonImmutable::now('UTC'));
            })
            ->latest()
            ->first();

        if (! $context) {
            return ['handled' => true, 'intent' => 'show_last_result', 'text' => 'What would you like me to show: tomorrow agenda, reminders, or health status?'];
        }

        return ['handled' => true, 'intent' => 'show_last_result', 'text' => $context->detail ?: $context->summary ?: 'I do not have details to show yet.'];
    }

    private function remindersBetween(?User $user, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return MiriamReminder::query()
            ->when($user, fn ($query) => $query->where('user_id', $user->id))
            ->whereNotIn('status', ['done', 'cancelled'])
            ->whereBetween('due_at', [$start->utc(), $end->utc()])
            ->orderBy('due_at')
            ->get();
    }

    private function calendarEventsBetween(?User $user, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        if (! $user) {
            return collect();
        }

        return CalendarEventMapping::query()
            ->where('user_id', $user->id)
            ->where('provider', 'google')
            ->get()
            ->filter(function (CalendarEventMapping $mapping) use ($start, $end): bool {
                $metadata = $mapping->metadata ?? [];
                $date = $metadata['date'] ?? $metadata['start_date'] ?? null;

                return is_string($date) && $date >= $start->toDateString() && $date <= $end->toDateString();
            })
            ->sortBy(fn (CalendarEventMapping $mapping): string => (string) (($mapping->metadata ?? [])['date'] ?? ''))
            ->values();
    }

    private function agendaSummary(string $label, Collection $events, Collection $reminders): string
    {
        if ($events->isEmpty() && $reminders->isEmpty()) {
            return "{$label}: no calendar events or Miriam reminders found.";
        }

        $parts = [];

        if ($events->isNotEmpty()) {
            $parts[] = $events->count().' calendar '.Str::plural('event', $events->count());
        }

        if ($reminders->isNotEmpty()) {
            $parts[] = $reminders->count().' Miriam '.Str::plural('reminder', $reminders->count());
        }

        return "{$label}: ".implode(', ', $parts).". Reply \"show me\" for details.";
    }

    private function agendaDetail(string $label, Collection $events, Collection $reminders): string
    {
        $lines = ["{$label} agenda:"];

        if ($events->isEmpty()) {
            $lines[] = 'Calendar: none found.';
        } else {
            $lines[] = 'Calendar:';
            foreach ($events as $event) {
                $metadata = $event->metadata ?? [];
                $time = $metadata['time'] ?? $metadata['start_time'] ?? null;
                $lines[] = '- '.($time ? $time.' - ' : '').($metadata['title'] ?? 'Calendar event');
            }
        }

        if ($reminders->isEmpty()) {
            $lines[] = 'Miriam reminders: none found.';
        } else {
            $lines[] = 'Miriam reminders:';
            foreach ($reminders as $reminder) {
                $lines[] = '- '.$this->localTime($reminder->due_at, $reminder->timezone ?: self::DEFAULT_TIMEZONE).' - '.$reminder->title;
            }
        }

        return implode("\n", $lines);
    }

    private function storeContext(string $slackUserId, string $channelId, ?User $user, string $type, string $summary, string $detail, array $payload = []): void
    {
        MiriamSlackConversationContext::create([
            'user_id' => $user?->id,
            'slack_user_id' => $slackUserId,
            'slack_channel_id' => $channelId,
            'context_type' => $type,
            'summary' => $summary,
            'detail' => $detail,
            'payload' => $payload,
            'expires_at' => CarbonImmutable::now('UTC')->addHours(6),
        ]);
    }

    private function localTime($value, string $timezone): string
    {
        return CarbonImmutable::parse($value)->setTimezone($timezone)->format('g:i A');
    }

    private function normalize(string $text): string
    {
        return trim((string) Str::of($text)
            ->lower()
            ->replaceMatches('/<@[a-z0-9]+>/i', '')
            ->replaceMatches('/^@miriam[:,]?\s*/i', '')
            ->replaceMatches('/^miriam[:,]?\s*/i', '')
            ->replaceMatches('/\s+/', ' '));
    }
}
