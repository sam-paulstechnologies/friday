# Phase 1 Google Calendar reliability

Branch: codex/jarvis-p5-calendar. Base: 78527ef4a00ab8bd906f0514bbe86ff5a0b0aa87.

## Implemented contract

syncConnection returns success / partial / failed / auth_required / skipped plus attempted, succeeded, failed and skipped counts for authentication, push and pull. Counts describe required logical operations, not individual HTTP retry attempts. Authentication validation alone cannot make data sync partial/success. A successful empty provider pull is verified work; disabled, backoff and overlapping no-work runs are skipped.

Only complete required work advances last_synced_at and new verified-success metadata. Partial, failed, auth-required, budget-limited or credential-changed work cannot advance success. Manual UI flashes success only for success; the command exits nonzero for partial/failed/auth-required. System Health requires recent verified success for every active connection, with failure severity taking precedence over unknown connections. Historical manual logs lacking the structured contract and old last_synced_at are unverified evidence. Logs/UI expose controlled statuses and safe codes, not provider bodies, exceptions, token data or arbitrary old metadata.

OAuth callback preserves an existing refresh token when its replacement is omitted, null or blank. Failed/malformed authorization returns a sanitized error. Callback rechecks workspace access; successful reconnect/disconnect resets verification state. Credential-version comparisons fence stale syncs from overwriting reconnect/disconnect health; changed credentials stop further pushes and cannot earn a new success timestamp.

Shared connection leases cover manual and scheduled sync (1800 seconds); refresh leases last 90 seconds. Refresh occurs once for an expired connection before its batch. An unexpected access-token 401 permits one forced refresh/replay before requiring authorization; permanent OAuth errors pause all channels until reconnect. Reminder APIs retain created/updated legacy success statuses and add explicit outcome fields, auth pauses and sanitized failure evidence.

Transient network/429/5xx and rate-limit 403 errors get at most three attempts with bounded exponential jitter, 8-second HTTP and 3-second connection timeouts. Long Retry-After pauses the entire connection rather than sleeping/repeating task requests; cooldown respects the bounded provider delay (up to 24 hours). Connection failure backoff grows from 60 seconds to 1 hour with jitter. A run has 120 HTTP requests, 100 pushed tasks, 10 pull pages and a 240-second work-start budget; three consecutive push failures stop the remaining pushes. Unprocessed work is counted, so a task cap cannot produce full success.

New event POSTs use stable valid Google event IDs. Timeout/retry conflicts read and verify identity before patching the existing event, preventing duplicate creations and avoiding claims on unrelated events. Existing provider mappings are retained; failed calls never mark them synchronized.

## Migration and compatibility

Prepared only: database/migrations/pending/2026_10_08_220001_add_calendar_sync_health_fields.php. It is outside the default migration scan and **was not applied**, including in the tests. It adds last_attempted_at, last_successful_sync_at, sync_state, consecutive_failures, next_retry_at and last_error_code; historical success is not backfilled. Its application/rollback and production-version compatibility require a separate approved migration exercise after recovery/CI gates.

Current code supports the legacy schema through shared persistent cache, and persists health fields when all approved columns exist. Production must use a shared atomic persistent cache with cache/cache_locks available across scheduler/web processes. Array/file-per-process caches cannot provide cross-host leases or durable auth pauses. Losing the fallback cache makes verified health unknown and may permit one new bounded auth attempt; persistent schema state remains a planned prerequisite for stronger durability. No cache/configuration change was made here.

## Verification

Final command: php vendor/bin/phpunit --filter 'CalendarFailureReportingTest|GoogleCalendarIntegrationTest|MedicationReminderTest|SystemHealthTest' --log-junit test-results/p5-final.xml.

**107 passed, 0 failed/errors/skipped; 514 assertions.** New provider-fake suite contributes 35 cases; 72 existing Calendar/medication/health regressions pass. Tests cover token preservation, refresh-once, permanent auth fan-out prevention, 401 recovery, bounded retries/cooldown/pagination/task limits, partial push/pull, invalid response/identity, credential changes, old-log/cache redaction, legacy-schema behavior and pending-migration quarantine.

Cross-process command: set JARVIS_TEST_MYSQL_BIN to C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin; python -m unittest discover -s tests/operations -v. **1 passed, 0 failed/skipped.** Two independent PHP clients on a fresh disposable MySQL 8.4.3 instance share the database-backed lease: one runs, the other skips with zero provider requests, and exactly one mapping exists. Google responses are faked; the clients are not coding agents or Development Manager runners.

Pint on changed PHP, PHP/Python syntax, git diff --check and npm run build passed. Existing partial verification runs (72, 98, 102 and 104 cases) passed; subsequent additions were rerun. The full legacy PHP suite is separately recorded in package 3 and integrated verification; these targeted results do not imply all CI is green.

## Remaining release dependencies

No live OAuth exchange/reconnection, provider request, credential change, scheduler/job execution on production or deployment occurred. Live root cause and end-to-end Google health remain **UNVERIFIED**. Deployable recovery, CI/main controls, shared-cache proof, migration approval and owner-authorized Google recovery remain prerequisites.

OAuth provider-account identity still follows the historical application-email fallback when Google omits identity metadata. Account-switch identity binding and existing duplicate connection/mapping cleanup need a separate verified design/authorization before broad automation; no extra OAuth scopes or destructive cleanup were introduced. The pending schema's runtime persistence and Linux/production MySQL version need separately authorized validation.

Provider references: [Calendar errors](https://developers.google.com/workspace/calendar/api/guides/errors), [client event IDs](https://developers.google.com/workspace/calendar/api/guides/create-events), [OAuth web-server flow](https://developers.google.com/identity/protocols/oauth2/web-server).

A later synthetic MySQL rerun exceeded the original 60-second setup timeout before lock assertions; it was recorded as 1 error. The harness now records safe setup stages and uses a bounded 180-second fixture-setup budget. Final rerun after credential fencing passed **1/1 in 47.985 seconds**, with no removed assertions. Production-version/Linux migration and performance remain unverified.

Final health review also added attempt-start recording and credential-epoch cache keys. In-flight work becomes unverified without discarding failure counters/history; a stale cache write cannot validate rotated credentials. Success recovered solely from optional database fields without current credential-bound cache proof remains unverified. Two new regression cases cover these invariants. An intermediate run exposed four stale-projection assertions; canonical current-row reads corrected them, and the final 107-case rerun passed. Health-field persistence still requires separately approved migration testing.
