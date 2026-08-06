<?php

namespace App\Http\Controllers;

use App\Models\MiriamReminder;
use App\Support\OperationalClock;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reminders outside Slack.
 *
 * Read-only for now: acknowledgement and snooze are driven by the Slack
 * buttons and the finite Reminder Poker escalation, and this page reports that
 * state truthfully rather than offering controls that are not wired.
 */
class ReminderController extends Controller
{
    /** Poker delivers at due time, +30 min, then +2 h, then stops. */
    private const MAX_POKES_FALLBACK = 3;

    public function __construct(private readonly OperationalClock $clock) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $maxPokes = (int) (config('services.miriam_capture.max_pokes') ?: self::MAX_POKES_FALLBACK);

        $reminders = MiriamReminder::query()
            ->with(['task:id,title,status'])
            ->where('user_id', $user->id)
            ->orderByRaw('next_reminder_at is null')
            ->orderBy('next_reminder_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (MiriamReminder $reminder) => $this->resource($reminder, $maxPokes));

        $groups = [
            'due' => $reminders->where('group', 'due')->values()->all(),
            'scheduled' => $reminders->where('group', 'scheduled')->values()->all(),
            'snoozed' => $reminders->where('group', 'snoozed')->values()->all(),
            'needs_attention' => $reminders->where('group', 'needs_attention')->values()->all(),
            'closed' => $reminders->where('group', 'closed')->take(40)->values()->all(),
        ];

        return Inertia::render('Reminders/Index', [
            'groups' => $groups,
            'counts' => collect($groups)->map(fn (array $items) => count($items)),
            'poker' => [
                'max_pokes' => $maxPokes,
                'second_poke_minutes' => (int) (config('services.miriam_capture.second_poke_minutes') ?: 30),
                'final_poke_minutes' => (int) (config('services.miriam_capture.final_poke_minutes') ?: 120),
            ],
            'delivery' => [
                // Truthful integration state — never assumed.
                'slack_configured' => filled(config('services.slack.bot_token')) && filled(config('services.slack.signing_secret')),
                'channel_configured' => filled(config('services.slack.miriam_channel_id')),
                'timezone' => $this->clock->timezone(),
            ],
        ]);
    }

    private function resource(MiriamReminder $reminder, int $maxPokes): array
    {
        $now = $this->clock->now();
        $isDue = $reminder->next_reminder_at !== null && $reminder->next_reminder_at->lessThanOrEqualTo($now->utc());
        $attempts = (int) $reminder->reminder_attempts;
        $failed = $reminder->events()->where('event_type', 'slack_reminder_failed')->exists();

        $group = match (true) {
            in_array($reminder->status, ['done', 'cancelled', 'expired'], true) => 'closed',
            $reminder->status === 'exhausted' => 'needs_attention',
            $reminder->status === 'snoozed' => 'snoozed',
            $reminder->status === 'awaiting_confirmation' => 'needs_attention',
            $isDue => 'due',
            default => 'scheduled',
        };

        return [
            'id' => $reminder->id,
            'title' => $reminder->title,
            'status' => $reminder->status,
            'group' => $group,
            'category' => $reminder->category,
            'due_at' => $this->clock->toLocal($reminder->due_at)?->format('D j M, g:i A'),
            'next_reminder_at' => $this->clock->toLocal($reminder->next_reminder_at)?->format('D j M, g:i A'),
            'last_sent_at' => $this->clock->toLocal($reminder->last_sent_at)?->format('D j M, g:i A'),
            'attempts' => $attempts,
            'max_pokes' => $maxPokes,
            'exhausted' => $reminder->status === 'exhausted',
            'delivery_failed' => $failed,
            'acknowledged' => $reminder->status === 'done',
            'awaiting_confirmation' => $reminder->status === 'awaiting_confirmation',
            'task' => $reminder->task ? [
                'id' => $reminder->task->id,
                'title' => $reminder->task->title,
                'status' => $reminder->task->status,
                'url' => route('tasks.show', $reminder->task->id),
            ] : null,
            'capture_url' => $reminder->status === 'awaiting_confirmation'
                ? route('inbox.show', ['capture', $reminder->id])
                : null,
        ];
    }
}
