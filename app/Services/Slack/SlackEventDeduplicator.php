<?php

namespace App\Services\Slack;

use App\Models\SlackWebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Claims a Slack `event_id` exactly once per endpoint.
 *
 * Slack retries any delivery it does not get a fast 2xx for, so without this
 * a single "Done" click can acknowledge a reminder twice. The claim is taken
 * before the work runs and released if the work throws, which keeps a
 * genuinely failed delivery retryable.
 */
class SlackEventDeduplicator
{
    /** @return bool true when this process owns the event and should handle it */
    public function claim(string $endpoint, ?string $eventId, ?string $eventType = null): bool
    {
        if (! filled($eventId)) {
            // Slack did not give us an id (slash commands, interaction
            // payloads). There is nothing to deduplicate on, so process it.
            return true;
        }

        try {
            SlackWebhookEvent::query()->create([
                'endpoint' => $endpoint,
                'event_id' => $eventId,
                'event_type' => $eventType,
                'outcome' => 'processing',
                'received_at' => CarbonImmutable::now('UTC'),
            ]);
        } catch (QueryException) {
            // Unique constraint: another delivery of this event already ran.
            return false;
        }

        return true;
    }

    public function complete(string $endpoint, ?string $eventId, string $outcome): void
    {
        if (! filled($eventId)) {
            return;
        }

        SlackWebhookEvent::query()
            ->where('endpoint', $endpoint)
            ->where('event_id', $eventId)
            ->update(['outcome' => $outcome, 'updated_at' => CarbonImmutable::now('UTC')]);
    }

    /** Let Slack's next retry have another go at an event that blew up. */
    public function release(string $endpoint, ?string $eventId): void
    {
        if (! filled($eventId)) {
            return;
        }

        SlackWebhookEvent::query()
            ->where('endpoint', $endpoint)
            ->where('event_id', $eventId)
            ->delete();
    }
}
