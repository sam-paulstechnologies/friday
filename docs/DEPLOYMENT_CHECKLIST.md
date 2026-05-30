# Miriam / Friday Deployment Checklist

This checklist assumes an archive-first, additive-first deployment. Do not run destructive database commands during deploy.

## Before Deploy

- Confirm a fresh database backup exists.
- Confirm the target branch is `main`.
- Confirm `.env` and credential files are not committed.
- Confirm `backups/` is not committed.
- Review release notes and migrations.
- Run the test suite locally or in CI.

## Pull And Install

```bash
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

Use `npm install` only when the deployment environment intentionally does not use `npm ci`.

## Environment

- Set `APP_ENV=production`.
- Set `APP_DEBUG=false`.
- Set a real `APP_KEY`.
- Configure database credentials using deployment secrets.
- Configure `SESSION_DRIVER`, `CACHE_STORE`, and `QUEUE_CONNECTION`.
- Configure mail/logging according to the production host.
- Configure Slack, Google Calendar, and AI only when ready.
- Keep AI disabled by default unless the provider is intentionally configured.
- Never paste secrets into tracked files.

## Database

Run normal additive migrations only:

```bash
php artisan migrate --force
```

Do not run:

- `migrate:fresh`
- `migrate:refresh`
- `db:wipe`
- `db:seed`
- table truncation
- hard-delete/reset scripts

## Cache And Storage

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
```

Confirm these paths are writable by the web/PHP user:

- `storage/`
- `storage/app/`
- `storage/framework/cache/`
- `storage/framework/sessions/`
- `storage/framework/views/`
- `bootstrap/cache/`

## Scheduler

Install one cron entry for Laravel scheduler:

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

Expected scheduled jobs:

- `taskflow:send-task-reminders`
- `taskflow:send-daily-briefing`
- `taskflow:send-evening-checkin`
- `miriam:run-automations`
- `miriam:sync-google-calendar` when Google Calendar is enabled

Recurring task generation currently happens when recurring tasks are completed, so no separate recurring-task scheduler is required.

## Queue Worker

Local development can run with `QUEUE_CONNECTION=sync` or `database`.

Production should use a durable queue connection and a supervised worker:

```bash
php artisan queue:work --sleep=3 --tries=3 --max-time=3600
```

Restart workers after deploy:

```bash
php artisan queue:restart
```

Use Supervisor, systemd, Laravel Horizon, or the host platform worker manager.

## Backups

- Back up the database before each deploy.
- Store production backups outside the repo and outside the web root.
- Encrypt or access-control production backups.
- Retain backups according to business requirements.
- Do not commit SQL dumps.
- `backups/` is ignored for local safety.

## Health Check

After deploy, run:

```bash
php artisan miriam:health-check
```

Resolve failed checks before opening UAT. Warnings should be reviewed and accepted explicitly.

Owners/admins can also review:

```text
/settings/system-health
```

## Smoke Test

Run the minimum smoke path in `docs/SMOKE_TEST.md`.

## Rollback Notes

- Keep the previous release artifact available.
- Restore the previous code release if needed.
- Restore the pre-deploy database backup only if the failure requires data rollback and stakeholders approve.
- Avoid manual destructive fixes. Prefer forward fixes when data integrity is intact.
