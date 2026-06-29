# Medication Reminder Stale Overdue Audit

## Production Symptoms

Medication reminders continued after the user clicked Taken because the Slack action correctly acknowledged only the dose log attached to that Slack message. Older overdue dose logs from previous dates remained active and were rescheduled through quiet hours.

Examples from the production audit included older evening and morning dose logs with high attempt counts and `next_reminder_at` repeatedly pushed to the next quiet-hours end.

## Root Cause

The reminder query selected every active due dose log with `pending`, `overdue`, `snoozed`, or `critical_overdue` status. Quiet-hours suppression then rescheduled those old logs instead of closing previous-day logs that had already been superseded by newer dose logs for the same schedule.

## Files Changed

- `app/Services/Health/MedicationReminderService.php`
- `app/Console/Commands/CloseStaleOverdueMedicationLogs.php`
- `tests/Feature/MedicationReminderTest.php`
- `docs/MEDICATION_REMINDER_STALE_OVERDUE_AUDIT.md`

## Before Behavior

- Previous-day overdue logs stayed active indefinitely.
- Quiet hours rolled old logs forward to the next morning.
- Clicking Taken stopped the exact clicked dose only.
- Multiple stale logs could continue nagging across days.

## After Behavior

- Active previous-day dose logs are closed when a newer dose log exists for the same schedule.
- Today’s pending or overdue dose is not closed by stale cleanup.
- Quiet-hours suppression checks for stale logs before rescheduling.
- Clicking Taken on a newer dose also closes older active overdue logs for that schedule.
- Each stale closure records a `stale_overdue_closed` audit event.

## Cleanup Command Usage

Preview production cleanup safely:

```bash
php artisan medication:close-stale-overdue --dry-run
```

Run cleanup:

```bash
php artisan medication:close-stale-overdue
```

Verbose output includes closed dose log IDs:

```bash
php artisan medication:close-stale-overdue -v
```

## Manual Verification Commands

```bash
php artisan medication:close-stale-overdue --dry-run
php artisan medication:close-stale-overdue
php artisan miriam:send-medication-reminders --sync --test-channel=test-database --pretend-now="2026-06-30 09:01"
php artisan test --filter=MedicationReminder
php artisan route:list
```

## Tests Added

- Previous-day overdue dose with a newer same-schedule dose is closed.
- Quiet-hours suppression does not roll stale previous-day logs forward.
- Slack Taken on a newer dose closes older overdue logs for the same schedule.
- Today’s pending dose is not closed.
- Today’s overdue dose still nags until Taken or Skip.
- Tomorrow’s dose can still be scheduled after cleanup.
- Cleanup command is idempotent.
- Slack Taken remains idempotent when clicked twice.

## Remaining Risks

The cleanup requires a newer dose log for the same schedule before closing an older active log. If schedule generation is disabled or broken, an old log may remain active until the newer log exists. This protects against accidentally closing the only active dose for a schedule.
