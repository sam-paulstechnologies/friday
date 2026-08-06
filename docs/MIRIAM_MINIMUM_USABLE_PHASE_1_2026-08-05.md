# Miriam — Phase 1: Minimum Usable Daily Loop

**Date:** 2026-08-05
**Scope:** Phase 1 only — one reliable daily workflow.
**Source of truth:** [docs/MIRIAM_WHOLE_APP_AUDIT_2026-08-05.md](MIRIAM_WHOLE_APP_AUDIT_2026-08-05.md) (not modified by this work).

> Capture → Inbox → Clarify or convert → Today → Complete / defer / delegate / waiting → Review completed work

Nothing was committed, pushed, or deployed. `.env` was not modified. No migration or seeder was run against the MySQL database. No real Slack message, email, webhook, deployment, or coding agent was triggered.

---

## 1. Baseline

| Item | Value |
|---|---|
| Branch | `main` |
| HEAD | `29466579b5cffa8dec9c4b129379c12567f2a06e` |
| Working tree at start | **Dirty** — 13 modified files, 8 untracked paths |
| Full suite at start | **136 failed, 369 passed** (2,458 assertions, 505 tests, 144.84 s) |
| Routes at start | 195 |
| App timezone at start | `UTC`, with `Asia/Dubai` hardcoded across reminders/medication |

### Pre-existing working-tree state (preserved in full)

Modified at start: `Agents/AgentOrchestratorController.php`, `SlackEventsController.php`, `SlackMedicationActionController.php`, `TaskController.php`, `TodayController.php`, `Models/MiriamReminder.php`, `Models/Task.php`, `Miriam/MiriamSlackConversationRouter.php`, `MiriamReminderService.php`, `config/services.php`, `AuthenticatedLayout.jsx`, `Agents/Orchestrator/Index.jsx`, `routes/web.php`.

Untracked at start: `OperationsCenterController.php`, `MiriamSlackThoughtCaptureService.php`, `Services/OperationsCenter/`, `2026_07_25_090000_add_slack_capture_fields…php`, `Components/OperationsCenter/`, `Pages/OperationsCenter/`, `MiriamSlackThoughtCaptureTest.php`, `OperationsCenterTest.php`.

**Every one of these paths still exists.** Nothing was stashed, reset, cleaned, discarded, or overwritten. Two pre-existing stashes were left untouched. `git add` was never run.

---

## 2. Audit findings addressed

| Audit ref | Finding | Status |
|---|---|---|
| P0-1 | Application timezone split-brain (`UTC` vs `Asia/Dubai`) | **Fixed** — operational-timezone service |
| P0-2 | `POST /webhooks/slack/events` returns HTTP 500 on every request | **Fixed** — route repointed to a handler that boots |
| P0-3 | IDOR: `PATCH /prioritization-review/apply` bulk-updates any task by id | **Fixed** — route and controller removed |
| P0-4 | No Inbox; `section = 'inbox'` written and never read | **Fixed** — real Inbox built |
| P0-5 | Capture proposals link to a blank task form | **Fixed** — prefilled conversion |
| P1-1 | "Prioritization" nav item opens a page that does not exist | **Fixed** — removed |
| P1-2 | `/health` unreachable from navigation | **Fixed** — in primary nav |
| P1-3 | `TaskController::store()` never scheduled a reminder | **Fixed** |
| P1-4 | Completing from Today ejects you to the task page | **Fixed** — returns you where you were |
| P1-5 | Same task rendered in three Today panels | **Fixed** — duplicate panel removed |
| P1-6 | Today product cards link to searches matching nothing | **Fixed** — panel removed from Today |
| P1-9 | Slack channel gate read `env()` at runtime (opens under `config:cache`) | **Fixed** — config only |
| P1-11 | `env()` outside config in 3 places | **Fixed** |
| P1-16 | Codex panel implies a pipeline that cannot boot | **Fixed** — reports itself unavailable |
| P2-18 | `tomorrowDate()` used UTC `toISOString()` | **Fixed** — local calendar date |
| Slack | No `event_id` deduplication | **Fixed** — per-endpoint idempotency |
| P0-7 | 26 `MiriamReminderTest` failures | **Partially fixed** — 26 → 19, all remaining classified (§10) |

---

## 3. Root causes

1. **Timezone.** `config/app.php` set `UTC`. Every task-side day computation used `now()->toDateString()`, so between 00:00 and 04:00 Dubai the application computed the *previous* calendar day. Medication used `Asia/Dubai` correctly, so the system contradicted itself for four hours daily. Compounding this, `whereDate('completed_at', …)` compared a UTC **timestamp** column against a local **calendar date**.

2. **Broken webhook.** `SlackWebhookController` type-hints nine Development Manager classes that are absent from the repository. Constructor injection happens before `__invoke`, so the container threw before signature verification — the route could never return anything but 500.

3. **No Inbox.** `section = 'inbox'` had zero readers repository-wide, and the nav item labelled "Inbox" pointed at system notifications.

4. **Conversion dead end.** The capture agent produced a classified proposal whose only control was a link to an empty `/tasks/create`.

5. **Scattered state writes.** Task status/section were written directly by controllers, Slack handlers and the reminder sync, each with its own rules — so "completed" did not reliably mean the same thing everywhere.

6. **A silent configuration fault.** `TASKFLOW_DAILY_USER_ID` is set to a user id that does not exist in test or fresh databases. The Slack daily-review lookup filtered on it directly, so every review query returned nothing and the endpoint reported "no active review" instead of a configuration problem.

7. **A partially-selected relation reaching a policy.** `MiriamReminder::with('task:id,title')` produced a `Task` without `workspace_id` or `assignee_id`; passing it to `TaskPolicy::update` yielded a spurious 403 on repeat conversion.

---

## 4. Architecture decisions

**One clock.** `App\Support\OperationalClock` is the single place that answers "what day is it for the operator". Nothing else reads the timezone directly.

**One transition layer.** `App\Services\Tasks\TaskTransitionService` is the only code that changes a task's daily-loop state. The Inbox, Today, the task page and Slack all call it, so authorization, validation, audit history and reminder synchronisation cannot drift apart.

**Inbox over the existing domain, not beside it.** No new capture table. The Inbox is a read model over the two records captures already produce, normalised into one item shape.

**The webhook was replaced, not restored.** Rebuilding the Development Manager is explicitly out of Phase 1 scope. The route now points at `SlackDailyReviewWebhookController`, which carries over every path that had working dependencies. The legacy controller stays on disk, unrouted.

**Honest labels over deletion.** Unfinished modules are tagged (`Preview`, `Rule-based`, `Not connected`) rather than removed, so nothing safe is lost while nothing over-promises.

---

## 5. Timezone strategy

Adopted rule, implemented in `App\Support\OperationalClock`:

- **Persist in UTC.** `config('app.timezone')` stays `'UTC'`. No historical timestamp was rewritten.
- **Interpret in Asia/Dubai.** New `config('app.operational_timezone')`, default `Asia/Dubai`, overridable by `APP_OPERATIONAL_TIMEZONE` (not set; `.env` untouched).
- **Date-only columns stay calendar dates.** `tasks.due_date` and `tasks.start_date` are compared as strings against `todayString()` / `dateString(±n)`. They are never pushed through a UTC conversion.
- **Timestamp columns use a UTC range.** An operational day becomes `[startOfDayUtc, endOfDayUtc]`. `Task::scopeCompletedOn()` replaces `whereDate('completed_at', …)`.
- **The frontend formats the local calendar date** instead of slicing `toISOString()`.

Worked example (verified by test): at **02:00 Dubai on 2026-06-24**, `todayString()` is `2026-06-24` while the UTC range is `2026-06-23 20:00:00` → `2026-06-24 19:59:59`.

Traced and corrected: `Task` scopes (`dueToday`, `overdue`, `upcoming`, new `completedOn`), `DailyReviewService` (all groups + `completedToday` + review date), `TodayCommandCenterService` (urgency, reason, waiting follow-ups, reminders), `TodayController` (today/tomorrow/snooze, missed-yesterday), `TaskController::index`, the Slack `move … tomorrow` command, `Today/Index.jsx::tomorrowDate()`.

**Deliberately left alone:** `MedicationReminderService` and `MiriamReminderService` already resolve `Asia/Dubai` per schedule/reminder and are correct. Changing them carried regression risk with no benefit; medication tests confirm no behavioural change.

---

## 6. Inbox data model and lifecycle

A capture arrives as one of two existing records; the Inbox normalises both.

| Source key | Record | When it is created |
|---|---|---|
| `capture` | `MiriamReminder` with `status = awaiting_confirmation` | Slack capture where a time was parsed — no task exists yet |
| `task` | `Task` with `section = 'inbox'` | Capture with no parseable time — written down immediately, still untriaged |

States (derived, not a new column):

| State | `capture` source | `task` source |
|---|---|---|
| `unprocessed` | `awaiting_confirmation`, no `task_id` | `section = inbox` |
| `clarification_needed` | `metadata.capture_status = clarification_needed` | — |
| `converted` | `task_id` set | `section` moved out of `inbox` |
| `dismissed` | `cancelled` / `expired` | `section = dismissed`, `status = archived` |

Guarantees:

- Ownership is required, not assumed: a capture resolves only for `user_id === auth id`; a task goes through `TaskPolicy`.
- **Nothing is deleted.** Dismissed captures keep `metadata.original_text` and stay listed under "Already dealt with".
- A converted capture is represented by its reminder row only, so one thought never appears twice.
- The empty state explains what the Inbox is and how to put the first thing in it.

---

## 7. Capture conversion lifecycle

`MiriamSlackThoughtCaptureService::convertReminderToTask()` is the shared conversion path.

1. Dismissed capture → refused with a reason; nothing is created.
2. Capture already has `task_id` → returns that task, `created = false`. **Repeat clicks and Slack retries cannot produce a second task.**
3. Otherwise, inside one transaction: build the parsed proposal, apply the operator's corrections, create the task, stamp provenance, link the reminder, record events.

Prefill rules — nothing is invented:

- Title, description and the original wording always carry over; the raw text is stored on both the task description and `source_metadata.original_text`.
- Due date, priority and type prefill only when parsed, and only valid enum values are accepted.
- A project attaches **only** when the id resolves to a real project in a workspace the operator can reach. An unmatched project name is shown as "Miriam read X but found no matching project", and nothing is attached.
- Confidence below 0.75 shows an explicit "check this before converting" warning.

Traceability: `tasks.source_metadata` records `capture_reminder_id`, `converted_at`, `converted_by_user_id`, `converted_via`; `miriam_reminders.task_id` links back; a `capture_converted` reminder event and a `capture_converted_to_task` task activity are written. After conversion the operator is taken straight to the task.

---

## 8. Canonical task transition rules

`TaskTransitionService::apply(Task, transition, actor, options)`.

| Transition | Effect |
|---|---|
| `today` | `section = today`, `due_date = operational today` |
| `this_week` / `this_month` / `later` / `tasks` | `section` only |
| `waiting` | `section = waiting`, `task_type = waiting_for` |
| `delegated` | `section = delegated` |
| `complete` | `status = completed`, `completed_at` set (idempotent) |
| `reopen` | `status = todo`, `completed_at` cleared, `inbox`/`dismissed`/null → `tasks` |
| `dismiss` | `status = archived`, `section = dismissed` |

Rejected as invalid: reopening a task that is not closed; re-bucketing a completed or archived task without reopening it first; completing an archived task.

Guarantees: central `Gate::authorize('update')`; one DB transaction; `source_dedupe_key` and `source_metadata` never touched (`forceFill` of explicit fields only); a `TaskActivity` plus an `AuditLog` per real change, under the pre-existing vocabulary (`task_completed`, `task_reopened`); reminders re-synced afterwards. Callers: `InboxController`, `TodayController`, `TaskController`, `SlackDailyReviewWebhookController`.

---

## 9. Routes

**Added (7)**

| Method | URI | Name |
|---|---|---|
| GET | `/inbox` | `inbox.index` |
| GET | `/inbox/{source}/{id}` | `inbox.show` |
| POST | `/inbox/{source}/{id}/convert` | `inbox.convert` |
| POST | `/inbox/{source}/{id}/move` | `inbox.move` |
| POST | `/inbox/{source}/{id}/dismiss` | `inbox.dismiss` |
| PATCH | `/today/tasks/{task}/later` | `today.tasks.later` |
| PATCH | `/today/tasks/{task}/waiting` | `today.tasks.waiting` |

`{source}` is constrained to `capture|task`; `{id}` to digits.

**Changed (1)** — `POST /webhooks/slack/events` now resolves to `Slack\SlackDailyReviewWebhookController` (was `SlackWebhookController`, unconstructable).

**Removed (2)** — `GET /prioritization-review`, `PATCH /prioritization-review/apply`. No page, component, test or external caller used them; the PATCH endpoint was the audit's only P0 security hole. `app/Http/Controllers/PrioritizationReviewController.php` was deleted with them. `OperationsCenterGraphService` referenced the route name in two nodes; both now point at live routes.

**Hidden from primary navigation** (routes intact, no functional change): Agents, Agent Orchestrator, Task Capture Agent, Operations Center and Assistant moved into a collapsed **Preview** group with truthful tags. Dashboard moved to **More**, tagged "Duplicates Today". AI Brain tagged "Not connected". **Notifications is now called Notifications**, and Inbox points at the real Inbox.

Route total: **195 → 200**. Every routed controller now resolves from the container (verified programmatically).

---

## 10. Database changes

One new table, `slack_webhook_events` (migration `2026_08_05_090000`): `endpoint`, `event_id`, `event_type`, `outcome`, `received_at`, unique on `(endpoint, event_id)`. It records each Slack `event_id` once per endpoint so a redelivery is a no-op.

**This migration has not been run against the MySQL database.** It has only been exercised against in-memory SQLite by the test suite. Run `php artisan migrate` when you are ready.

No column was added to `tasks` or `miriam_reminders`. No existing column was altered or dropped. No data was modified.

---

## 11. Files changed

**New (14)**

```
app/Support/OperationalClock.php
app/Services/Tasks/TaskTransitionService.php
app/Services/Tasks/InvalidTaskTransitionException.php
app/Services/Inbox/InboxService.php
app/Services/Inbox/InboxConversionException.php
app/Services/Slack/SlackEventDeduplicator.php
app/Http/Controllers/InboxController.php
app/Http/Controllers/Slack/SlackDailyReviewWebhookController.php
app/Models/SlackWebhookEvent.php
database/migrations/2026_08_05_090000_create_slack_webhook_events_table.php
resources/js/Pages/Inbox/Index.jsx
resources/js/Pages/Inbox/Show.jsx
tests/Feature/MiriamDailyLoopTest.php
docs/MIRIAM_MINIMUM_USABLE_PHASE_1_2026-08-05.md
```

**Modified (18)**

```
app/Http/Controllers/SlackEventsController.php      event-id idempotency, config over env(), channel gate
app/Http/Controllers/TaskController.php             reminder on create, transition service, back() on complete, timezone
app/Http/Controllers/TodayController.php            timezone, transition-backed actions, inbox count, operational date
app/Http/Middleware/HandleInertiaRequests.php       shared flash + inbox count
app/Models/Task.php                                 section constants, inInbox/triaged/completedOn scopes, timezone scopes
app/Services/DailyReview/DailyReviewService.php     operational clock, inbox excluded from Today
app/Services/MiriamReminderService.php              snooze idempotency, Cancel button restored, config over env()
app/Services/TodayCommandCenterService.php          timezone, honest Codex state, real card links, reminders section
app/Services/Miriam/MiriamSlackThoughtCaptureService.php   shared conversion entry point (pre-existing untracked file)
app/Services/OperationsCenter/OperationsCenterGraphService.php  two dead route references (pre-existing untracked file)
config/app.php                                      operational_timezone
config/services.php                                 slack.daily_user_id
routes/web.php                                      see §9
resources/js/Layouts/AuthenticatedLayout.jsx        honest navigation, badges, mobile capture
resources/js/Pages/Today/Index.jsx                  de-duplicated, reminders, honest Codex, local dates, errors
resources/js/Pages/Dashboard.jsx                    dead prioritization link → Inbox
tests/Feature/DailyExecutionTest.php                Inertia component assertion instead of raw-HTML assertSee
tests/Feature/MiriamReminderTest.php                6 stale Slack copy expectations (see §12)
```

**Deleted (1)** — `app/Http/Controllers/PrioritizationReviewController.php`

---

## 12. Tests

### Added — `tests/Feature/MiriamDailyLoopTest.php` (39 tests, 341 assertions, all passing)

Every Slack request is locally signed with a test secret and every outbound call is `Http::fake`d.

Capture and Inbox: signed capture creates exactly one Inbox item · replayed `event_id` does not duplicate it · Inbox page shows the capture with its original wording and source · empty state.

Conversion: creates one task preserving the original wording · repeating three times still yields one task · converted capture stops counting as unresolved but is not deleted · dismissal keeps the wording · an unreachable project id is discarded rather than attached.

Timezone: Inbox → Today lands on the right day · task due today in Dubai appears at **00:30** Dubai (the old broken window) · **23:59** Dubai still reports the same operational day · task due tomorrow does not appear early · yesterday's task is overdue from **00:01** · clock maps a Dubai day to `20:00 UTC → 19:59:59 UTC` · completion at **01:00** Dubai counts as completed today.

Transitions: completing from Today removes it from active Today and returns you to Today · it appears under Completed · reopening restores an active state · Waiting and Later persist · invalid transitions rejected · completed task cannot be re-bucketed · completing twice is idempotent.

Authorization: another user's task cannot be completed, moved, converted or dismissed by id (6 endpoints, all 403) · another user's capture cannot be converted · the removed bulk endpoint is gone and returns 404.

Slack: invalid signature → 403 · expired timestamp → 403 · URL verification → challenge · unknown event → safe 200 · a Development Manager command replies "not available… nothing was started" and claims no action · replayed `event_id` deduplicated.

Reminders: acknowledgement idempotent and stops delivery · snooze idempotent with exactly one next occurrence · acknowledged reminder is not delivered again · three scheduler runs deliver once (`Http::assertSentCount(1)`) · Today shows a due reminder.

Navigation: all 29 navigable routes resolve successfully · Today reports Codex as unavailable rather than idle.

### Changed

- `DailyExecutionTest::test_today_page_loads` — `assertSee('Today/Index')` could never match: Inertia JSON-escapes the component name inside `data-page`. Replaced with `assertInertia(…->component('Today/Index'))`.
- `MiriamReminderTest` — 6 expectations updated from `✅ Done — x` to `Done - x` etc. The working-tree refactor deliberately moved Slack copy to plain ASCII. **No status, timing, idempotency or count assertion was weakened**; only literal strings moved. One delivery test's message prefix was likewise realigned to `Reminder:`.

### Behavioural repairs made because a test proved them real

- **Cancel restored to the due-reminder card.** The escalation refactor dropped the button while keeping its handler, leaving no way to stop an unwanted reminder except marking undone work as done.
- **Snooze made idempotent per occurrence** — a redelivered interaction no longer records a second snooze.
- **Daily-review lookup scoped to the resolved operator** rather than the raw configured id, so a stale `TASKFLOW_DAILY_USER_ID` no longer silently voids every review query.
- **Full task row loaded before policy checks**, fixing a spurious 403 on repeat conversion.

---

## 13. Results

| Check | Baseline | After Phase 1 |
|---|---|---|
| Full PHPUnit suite | **136 failed, 369 passed** (505) | **114 failed, 430 passed** (544, 2,862 assertions, 114.68 s) |
| `MiriamDailyLoopTest` (new) | — | **39 passed** |
| `AiBrainSlackTest` | 8 failed | **8 passed** |
| `DailyExecutionTest` | 9 failed | **35 passed** |
| `MiriamReminderTest` | 26 failed | 19 failed, 73 passed |
| `MedicationReminderTest` | passing | **passing — no regression** |
| `MiriamSlackThoughtCaptureTest` | passing | **passing** |
| `TaskTest` / `MiriamTodayCommandCenterTest` / `OperationsCenterTest` | passing | **passing** |
| `MiriamDevelopmentManagerTest` | 95 failed, 13 passed | 95 failed, 13 passed — **unchanged** |
| Production frontend build | pass | **pass** — built in 12.02 s, no errors |
| `php artisan route:list` | 195 routes | **200 routes**, app boots |
| Every routed controller constructs | 1 could not | **all resolve** |
| Laravel Pint (`--test`, new code) | not run | **passed** |
| ESLint / TypeScript | not configured | not configured |

`MiriamDevelopmentManagerTest` was measured both ways: with the old controller temporarily rewired to the route and with the new one, the result is identically **95 failed / 13 passed**. The route change caused no regression there. (The audit's "~93" was an approximation.)

### Remaining failures, classified

**A · `MiriamDevelopmentManagerTest` — 95 · deferred, unchanged**

| Cause | Count |
|---|---|
| `Target class [App\Services\MiriamPromptQueueService] does not exist` | 57 |
| Unregistered `miriam:*` console commands | 19 |
| `Target class [App\Services\MiriamRunnerMonitoringService] does not exist` | 3 |
| Development Manager Slack commands answered as unavailable | 8 |
| `/api/miriam/runner/*` routes do not exist (404) | 3 |
| `product-brain.development-manager.*` routes not defined | 2 |
| `tools/miriam-runner/runner-config.example.json` absent (gitignored) | 2 |
| `App\Models\MiriamSlackPendingConfirmation` not found | 1 |

Every one is the Development Manager module, which Phase 1 was explicitly told not to rebuild.

**B · `MiriamReminderTest` — 19 · superseded by the working tree's own refactor**

These assert the **old** capture pipeline (`MiriamReminderService::captureSmartFromSlack`): multi-item capture with a "Captured N items:" summary, immediately-`pending` reminders, AM/PM clarification records, and Google Calendar event creation at capture time. The uncommitted `MiriamSlackThoughtCaptureService` replaced that route with single-thought capture, `awaiting_confirmation` plus a confirm card. The tests were never updated.

| Group | Count |
|---|---|
| Expects the old "Captured N items" Slack summary / immediate `pending` status | 8 |
| Old deterministic time parser (`tonight`, ambiguous hour) | 4 |
| Old clarification records (`MiriamSlackClarification`) | 3 |
| Old AI-fallback response shape | 2 |
| Google Calendar event created at capture time (Calendar unconfigured) | 2 |

Rewriting them means rewriting the reminder platform's test contract — out of Phase 1 scope, and doing it blind would risk endorsing whichever behaviour happens to exist. **Nothing in group B affects the Phase 1 daily loop**, which is covered independently and passing in `MiriamDailyLoopTest`.

---

## 14. Deferred audit findings

Not touched in Phase 1, still open:

- Development / Agent OS: 9 missing classes, 4 unregistered commands, 2 undefined routes, unrouted `DevelopmentManagerController`, orphan tables (`miriam_release_packages`, `miriam_release_approvals`, `miriam_app_validation_profiles`).
- **P0-6** scheduler/queue heartbeat; System Health still reports Queue and Scheduler "passed" without checking a worker or a run.
- **P1-7** `/settings/ai` has no authorization. **P1-8** Command Center pages ship all areas/portfolios/projects/200 task titles. **P1-10** medication Slack actions lack a user/ownership check. **P1-12** task list unpaginated. **P1-13** no in-app reminder management view. **P1-14** delivery failures not surfaced in System Health. **P1-15** agent approval produces no record. **P1-17** ambiguous capture times still guessed.
- **P2** items: nav collapse to 5 groups, five duplicated concepts, retiring `/dashboard`, Friday→Miriam rename, Bible summary caching, `due_date` index, mobile rate limit and token TTL, error monitoring, Slack log redaction, universal search, Google Calendar and OpenAI configuration, PII redaction, audit retention, CI branch, splitting the two oversized files, Tasks header `grid hidden` bug.
- **P3**: business command center, Gmail, Mission Control, Command Map, per-user timezone UI, Playwright in CI.

Missing classes **not** repaired, and why: all nine belong to the Development Manager. The instruction was to fix a missing class only where it affects authentication, capture, Inbox, Today, reminders, Slack or startup. The only such impact was the Slack route, which is fixed by replacement. The legacy controller is now unrouted, so no missing class is reachable from any registered route, webhook, middleware, command or scheduler entry.

---

## 15. Manual verification

1. `php artisan migrate` (adds `slack_webhook_events`).
2. `npm run build`, then start the app.
3. **Capture** — DM Miriam on Slack: "remind me to call the client tomorrow at 4pm". Do not press Confirm.
4. **Inbox** — open `/inbox`. The thought is listed as *Unprocessed*, source *Slack*, with your exact wording under "What you actually said", and the proposed due date/time/priority.
5. **Clarify** — "Open and edit". Correct the title, pick a project, choose a destination. The original wording stays on screen and is never retyped.
6. **Convert** — "Convert to task". You land on the task. Its description contains your original wording. Press back and convert again: the same task opens, no second one is created.
7. **Today** — send it to Today from the Inbox. It appears under Due today. Confirm the subtitle shows today's Dubai date. If you can, check between 00:30 and 03:00 Dubai — the date must still be the new day.
8. **Complete** — press Mark done on Today. You stay on Today; the task disappears from the active sections and Completed today increments.
9. **Completed** — `/tasks` → Completed tab shows it. Reopen it from the task page: it returns to an active state.
10. **Traceability** — the task's activity feed shows `capture_converted_to_task` and `task_completed`; the reminder row carries `task_id`.
11. **Navigation** — every sidebar item opens a real page. Notifications is called Notifications. Codex on Today reads "Codex not available".
12. **Security** — signed in as a second user, `POST /inbox/task/{id}/convert` for the first user's task returns 403.

---

## 16. Rollback

Everything is uncommitted, so rollback is per-file with `git checkout --`. Ordered by risk:

- **Zero-risk to revert alone:** `resources/js/Pages/Inbox/*`, `InboxController`, `Services/Inbox/*`, `MiriamDailyLoopTest.php` (new files; delete them and remove the five Inbox routes).
- **Revert together:** `TaskTransitionService` + `TodayController` + `TaskController` + `SlackDailyReviewWebhookController` — the controllers call the service.
- **Revert together:** `OperationalClock` + `config/app.php` + `Task.php` + `DailyReviewService` + `TodayCommandCenterService`. Reverting the clock alone leaves callers pointing at a missing class.
- **Restoring the old webhook** means restoring HTTP 500 on that route. Prefer leaving it.
- **Restoring `PrioritizationReviewController`** (`git checkout -- app/Http/Controllers/PrioritizationReviewController.php`) reinstates the P0 IDOR. Do not do this without also adding ownership checks.
- The new migration is additive and reversible (`down()` drops the table). No existing data is touched by rolling back.

---

## 17. Known limitations

- The two capture entry points still differ: Slack captures with a parseable time wait for confirmation; captures without one become tasks immediately. Both land in the Inbox, so the loop is intact, but the Slack conversation is not uniform.
- Slack **interaction payloads** carry no `event_id`, so button clicks are protected by status guards and snooze idempotency rather than by the dedupe table.
- Reminder delivery still depends on `schedule:run` and `queue:work`, and System Health still misreports both (P0-6, deferred). **This is the biggest remaining risk to daily use**: if the scheduler is not running, reminders stop silently.
- Today keeps the Later/backlog and Reading panels and the seven metric cards. Only the duplicated task list and the dead product cards were removed.
- The Inbox lists at most 200 records per source and 30 resolved items; there is no pagination.
- `MiriamReminderTest`'s 19 superseded tests mean the reminder platform is only partly covered by its own suite; Phase 1 behaviour is covered by `MiriamDailyLoopTest` instead.
- The product is still named Friday in `APP_NAME`, the page title and notification copy.
- Nothing here addresses multi-user hosting: `/settings/ai` and the Command Center pages remain unauthorized (P1-7, P1-8).

---

## 18. Acceptance gate

| # | Requirement | Result | Evidence |
|---|---|---|---|
| 1 | Thought captured via signed fake Slack request or web UI | **Pass** | `test_signed_slack_capture_creates_one_inbox_item` |
| 2 | Appears in the real Inbox | **Pass** | `test_inbox_page_displays_the_capture` |
| 3 | Interpreted details editable without retyping the thought | **Pass** | `Inbox/Show.jsx` prefill; conversion test supplies a corrected title while the wording persists |
| 4 | Converts into exactly one task | **Pass** | `test_repeating_a_conversion_does_not_create_a_second_task` |
| 5 | Task can be moved to Today | **Pass** | `test_moving_an_inbox_item_to_today_makes_it_appear_in_today` |
| 6 | Appears in Today per Asia/Dubai boundaries | **Pass** | 5 boundary tests incl. 00:30 and 23:59 Dubai |
| 7 | Can be completed directly from Today | **Pass** | `test_completing_from_today_removes_the_task_from_active_today` |
| 8 | Disappears from active Today | **Pass** | same test |
| 9 | Appears under Completed | **Pass** | `test_completed_task_appears_under_completed_in_the_task_list` |
| 10 | Capture-to-task relationship traceable | **Pass** | `capture_reminder_id`, `converted_by_user_id`, `miriam_reminders.task_id` asserted |
| 11 | No cross-user task modifiable by changing an id | **Pass** | 8 endpoints asserted 403; bulk endpoint removed |
| 12 | No real Slack, email, webhook, deployment or agent triggered | **Pass** | all Slack signed locally and `Http::fake`d; `MAIL_MAILER=array`; `QUEUE_CONNECTION=sync`; no agent executed |

**Acceptance gate: PASSED**, with these limits stated plainly:

- Phase 1 is complete for the daily loop. It is **not** a green test suite: 114 tests still fail, in two classes, both classified above and neither on the daily-loop path.
- Item 12 covers this work. It does **not** prove the running application never sends Slack messages — it will, once the scheduler runs, which is the intended behaviour.
- Reminder *delivery* remains unverified in the real environment because scheduler and queue liveness is deferred (P0-6). The loop is correct in code and under test; whether it fires on your machine at 07:00 tomorrow depends on infrastructure this phase did not touch.

---

*Phase 1 only. Phase 2 was not started. No commit, push, deployment, `.env` change, destructive database command, real Slack/email/webhook delivery, or real agent execution occurred.*
