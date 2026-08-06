<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Single source of truth for "what day is it for the operator".
 *
 * Timestamps are persisted and processed in UTC. Everything the user reads —
 * "today", "tomorrow", "overdue", local schedule times — is interpreted in the
 * operational timezone (Asia/Dubai by default).
 *
 * Date-only columns (tasks.due_date, tasks.start_date) are calendar dates and
 * must never be shifted through a UTC conversion. Compare them as strings
 * against todayString()/dateString(). Timestamp columns (completed_at,
 * due_at, next_reminder_at) are UTC instants and must be filtered with
 * dayRangeUtc() rather than whereDate().
 */
class OperationalClock
{
    public function timezone(): string
    {
        $timezone = config('app.operational_timezone');

        if (! is_string($timezone) || $timezone === '') {
            $timezone = config('app.timezone');
        }

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }

    /** Current instant, expressed in the operational timezone. */
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    /** Start of the current operational day, in the operational timezone. */
    public function today(): CarbonImmutable
    {
        return $this->now()->startOfDay();
    }

    public function todayString(): string
    {
        return $this->now()->toDateString();
    }

    public function tomorrowString(): string
    {
        return $this->dateString(1);
    }

    public function yesterdayString(): string
    {
        return $this->dateString(-1);
    }

    /** Calendar date N days from the operational today, as Y-m-d. */
    public function dateString(int $daysFromToday = 0): string
    {
        return $this->now()->addDays($daysFromToday)->toDateString();
    }

    /**
     * The UTC instant at which the given operational calendar day begins.
     * Use for timestamp-column range queries instead of whereDate().
     */
    public function startOfDayUtc(?string $date = null): CarbonImmutable
    {
        return $this->localDay($date)->startOfDay()->utc();
    }

    /** The UTC instant at which the given operational calendar day ends. */
    public function endOfDayUtc(?string $date = null): CarbonImmutable
    {
        return $this->localDay($date)->endOfDay()->utc();
    }

    /**
     * [$startUtc, $endUtc] covering one operational calendar day.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function dayRangeUtc(?string $date = null): array
    {
        return [$this->startOfDayUtc($date), $this->endOfDayUtc($date)];
    }

    /** Convert any instant into the operational timezone. */
    public function toLocal(DateTimeInterface|string|null $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->setTimezone($this->timezone());
    }

    /** The operational calendar date a timestamp falls on. */
    public function localDateString(DateTimeInterface|string|null $value): ?string
    {
        return $this->toLocal($value)?->toDateString();
    }

    /** Parse a calendar date in the operational timezone (no UTC shift). */
    private function localDay(?string $date): CarbonImmutable
    {
        if ($date === null || $date === '') {
            return $this->today();
        }

        return CarbonImmutable::parse($date, $this->timezone())->startOfDay();
    }
}
