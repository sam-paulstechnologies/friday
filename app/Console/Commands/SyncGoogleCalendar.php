<?php

namespace App\Console\Commands;

use App\Models\CalendarConnection;
use App\Services\Calendar\CalendarSyncService;
use App\Services\Calendar\GoogleCalendarService;
use Illuminate\Console\Command;

class SyncGoogleCalendar extends Command
{
    protected $signature = 'miriam:sync-google-calendar {--user_id=} {--connection_id=}';

    protected $description = 'Sync active Google Calendar connections without printing tokens or credentials.';

    public function handle(CalendarSyncService $calendarSyncService, GoogleCalendarService $googleCalendarService): int
    {
        if (! $googleCalendarService->configured()) {
            $this->info('Google Calendar is disabled or not configured. No sync was run.');

            return self::SUCCESS;
        }

        $connections = CalendarConnection::query()
            ->with('user')
            ->where('provider', 'google')
            ->where('is_active', true)
            ->when($this->option('user_id'), fn ($query, $userId) => $query->where('user_id', (int) $userId))
            ->when($this->option('connection_id'), fn ($query, $connectionId) => $query->whereKey((int) $connectionId))
            ->get();

        $totals = ['connections' => $connections->count(), 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        $connections->each(function (CalendarConnection $connection) use ($calendarSyncService, &$totals): void {
            $counts = $calendarSyncService->syncConnection($connection);

            foreach (['created', 'updated', 'skipped', 'failed'] as $key) {
                $totals[$key] += $counts[$key] ?? 0;
            }
        });

        $this->info(sprintf(
            'Google Calendar sync processed %d connection(s): %d created, %d updated, %d skipped, %d failed.',
            $totals['connections'],
            $totals['created'],
            $totals['updated'],
            $totals['skipped'],
            $totals['failed'],
        ));

        return self::SUCCESS;
    }
}
