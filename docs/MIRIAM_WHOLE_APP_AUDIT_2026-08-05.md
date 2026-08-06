# Miriam — Whole Application Audit

**Date:** 2026-08-05
**Scope:** Entire repository at `C:\laragon\www\taskflow`
**Type:** Audit only. No fixes applied, nothing committed, pushed, deployed, or configured.
**Method:** Source inspection + full automated test run (in-memory SQLite) + production frontend build + route inspection. No external API was called, no Slack/email/webhook was sent, no coding agent was run, no migration or seeder was executed, `.env` was not modified.

---

## 1. Executive Summary

### What Miriam currently is, in practical terms

Miriam is a **Laravel 12 + Inertia/React 18 single-user personal task and reminder system** with an unusually strong Slack capture pipeline and an unusually strong medication-reminder engine, wrapped in a 27-item sidebar that advertises roughly three times more capability than is actually wired.

It is **not** currently a company operating system. The pieces that would make it one — the development/agent OS, the business command center, the AI brain, the knowledge layer — are respectively **broken, absent, disabled, and unbuilt**.

### Is it usable today?

**No — not as a dependable daily system.** It is usable as a *Slack capture inbox that writes into a task list you have to hunt for*. The daily loop breaks in four specific, provable places, listed below.

### Scores

| Score | Value | One-line basis |
|---|---|---|
| **Daily-Use Readiness** | **32 / 100** | Capture works; triage, conversion, and truthful "today" do not. |
| **Vision Completion** | **34 / 100** | Personal OS ≈ 60% real; company OS ≈ 8% real. |
| **Operational Reliability** | **28 / 100** | 136 of 505 tests fail; one live route returns HTTP 500 permanently; timezone is wrong 4 hours a day. |

Weighting and calculation are given in full in **Section 12A**.

### Why you currently feel unable to use it

Five concrete, evidence-backed reasons (full analysis in Section 12):

1. **There is no Inbox.** Slack captures are written with `section = 'inbox'` ([MiriamSlackThoughtCaptureService.php:313](app/Services/Miriam/MiriamSlackThoughtCaptureService.php:313)) and **nothing in the entire codebase ever reads that value**. The sidebar item labelled "Inbox" points at the *system notifications* page ([AuthenticatedLayout.jsx:14](resources/js/Layouts/AuthenticatedLayout.jsx:14)). Captured thoughts land in a bucket with no screen.
2. **Capture does not complete the lifecycle.** The Task Capture Agent produces classified proposals, and the only conversion control is a link to a **blank** task form ([Agents/TaskCapture/Index.jsx:219](resources/js/Pages/Agents/TaskCapture/Index.jsx:219)) that prefills nothing. You must retype everything the agent just parsed.
3. **"Today" is not your today.** `config/app.php` sets `timezone => 'UTC'` ([config/app.php:68](config/app.php:68)) while reminders/medication use `Asia/Dubai`. Between 00:00 and 04:00 Dubai time, every task query computes the *previous* calendar day. Overdue, due-today and Today Command Center are all wrong for four hours daily.
4. **A live Slack route is permanently broken.** `POST /webhooks/slack/events` ([routes/web.php:63](routes/web.php:63)) returns **HTTP 500** on every request because `SlackWebhookController` depends on **nine classes that do not exist**. Runtime-verified via test output.
5. **Too many unfinished modules are visible and indistinguishable from finished ones.** "Prioritization" in the sidebar renders a React page that **does not exist**. `/health` (your medication screen) is **not in the sidebar at all**. The Codex/Development panel occupies prime real estate on Today while its backing module cannot boot.

### Strongest completed foundations

1. **Medication reminder engine** — quiet hours, hard deadlines, stale-overdue cleanup, idempotent acknowledgement, per-dose audit events, safe Slack buttons. Genuinely production-grade.
2. **Slack thought capture** (`/slack/events`) — signature verification, replay window, dedupe keys, confirm/edit/cancel/move-to-today buttons, expiry, full event trail.
3. **Bible / 90-day reading plan** — day plan, chapters, progress, streaks, behind/ahead, missed-yesterday catch-up, journal, notes, Today integration, Slack block.
4. **Google Calendar integration** (built, not configured) — OAuth state check, encrypted tokens, two-way sync, dedupe via `extendedProperties`, sync logs, truthful connection status in the UI.
5. **Agent safety posture** — every agent is explicitly `rule_based`, `external_api_required: false`, `creates_actions_automatically: false`. Nothing auto-executes. Approval gates exist for external sends and medication changes.

### Most serious usability blockers

1. No Inbox / no capture-to-record conversion (P0)
2. Timezone split-brain making Today untrustworthy (P0)
3. Nav-linked page that does not exist (`/prioritization-review`) (P1)
4. Medication screen unreachable from navigation (P1)
5. Same task appearing in three panels on Today; product cards linking to searches that match nothing (P1)

### Most serious technical risks

1. Nine missing classes break `/webhooks/slack/events` and the entire Development Manager (P0)
2. 136 of 505 automated tests fail on the current working tree (P0)
3. Reminder delivery depends on `schedule:run` + `queue:work`, and System Health reports "passed" whether or not either is running (P1)
4. `env()` called outside config in two places — silently returns `null` under `php artisan config:cache` (P1)
5. Unbounded, unpaginated task queries executed twice per page view (P2)

### Most serious security risks

1. **P0 — IDOR / mass task modification:** `PATCH /prioritization-review/apply` accepts arbitrary `task_ids` with no ownership check and bulk-updates status/priority/due date on **any task in the database**.
2. **P1 — Unauthorized global AI settings:** `/settings/ai` has zero authorization; any authenticated user can set/replace the OpenAI API key, enable AI, and change token limits for the whole installation.
3. **P1 — Cross-user data disclosure:** `/waiting`, `/decisions`, `/blockers`, `/risks`, `/approvals` ship **every** area, portfolio, project, and 200 task titles in the database to any authenticated user.
4. **P1 — Slack channel gate fails open:** with `SLACK_MIRIAM_CHANNEL_ID` unset (it is unset), Miriam accepts capture from *any* channel the bot is in.
5. **P2 — No rate limiting on mobile login**, and mobile tokens are created with no expiry.

---

## 2. Repository and Environment Baseline

| Item | Value |
|---|---|
| Repository root | `C:\laragon\www\taskflow` |
| Current branch | `main` |
| HEAD commit | `29466579b5cffa8dec9c4b129379c12567f2a06e` — "Add Miriam Agent OS support classes" (2026-07-09) |
| Remote | `origin` → `github.com/sam-paulstechnologies/friday.git` (no credentials in URL) |
| Working tree | **Dirty** — 13 modified files, 8 untracked paths (unchanged by this audit; see below) |
| Product name in config | **`APP_NAME=Friday`** (`.env` and `.env.example`) |
| Legacy names found | Friday, TaskFlow, taskflow, Task Management |
| Backend | PHP `^8.2`, Laravel `^12.0`, Inertia Laravel `^2.0`, Sanctum `^4.0`, Ziggy `^2.0` |
| Frontend | React `^18.2`, `@inertiajs/react ^2.0`, Vite `^6.0`, Tailwind `^3.2` (+ `@tailwindcss/vite ^4.0` — mixed majors) |
| Database | MySQL (`DB_CONNECTION=mysql`, `DB_DATABASE=taskflow`) |
| Queue driver | `QUEUE_CONNECTION=database` — **requires a running `queue:work` worker** |
| Cache / session | `database` |
| Mail | `MAIL_MAILER=log` — **no email is actually delivered** |
| Scheduler | 7 entries in `routes/console.php`; two run `everyMinute`. Requires `php artisan schedule:run` every minute. **No cron/Task-Scheduler artifact found in the repo.** |
| App timezone | **`UTC`** (`config/app.php:68`) — conflicts with `Asia/Dubai` used throughout reminders |
| Auth | Laravel Breeze (session), + custom bearer-token middleware for the mobile API |
| Test frameworks | PHPUnit `^11.5` (39 files), Playwright `^1.60` (11 e2e specs) |
| Build tools | Vite 6, PostCSS, Tailwind |
| Deployment config | 4 GitHub workflows. `tests.yml` triggers on `master`/`*.x` — **the repo's branch is `main`, so CI never runs on push.** |
| Tenancy shape | **Effectively single-user.** Workspace/role scaffolding exists, but Slack resolves to `User::orderBy('id')->first()` and AI settings are a single global row. |

### Third-party integrations present in code

Slack (bot token + signing secret **configured**), OpenAI (`/v1/chat/completions`, `/v1/responses`, `/v1/audio/transcriptions`, `/v1/models` — **no key configured**), Google Calendar OAuth + Calendar v3 (**no credentials configured**), AWS S3 (keys present in `.env`).

### Configuration state (values never read or reproduced)

| Feature | Config path | Effective state |
|---|---|---|
| Miriam AI brain | `services.miriam_ai.enabled` | **false** (`MIRIAM_AI_ENABLED` absent; `OPENAI_API_KEY` absent) |
| AI Assistant | `services.ai_assistant.enabled` / `.provider` | **false / `mock`** |
| Google Calendar | `services.google_calendar.enabled` | **false** (no `GOOGLE_CLIENT_ID`/`SECRET`) |
| Slack bot | `services.slack.bot_token`, `.signing_secret` | **configured** |
| Slack Miriam channel | `services.slack.miriam_channel_id` | **unset → channel gate fails open** |
| Slack allowed user | `services.slack.allowed_user_id` | **set** (constrains events endpoint only) |

### Working-tree condition (unchanged by this audit)

Modified: `Agents/AgentOrchestratorController.php`, `SlackEventsController.php`, `SlackMedicationActionController.php`, `TaskController.php`, `TodayController.php`, `Models/MiriamReminder.php`, `Models/Task.php`, `Miriam/MiriamSlackConversationRouter.php`, `MiriamReminderService.php` (+430 lines), `config/services.php`, `AuthenticatedLayout.jsx`, `Agents/Orchestrator/Index.jsx`, `routes/web.php`.

Untracked: `OperationsCenterController.php`, `MiriamSlackThoughtCaptureService.php`, `Services/OperationsCenter/`, `2026_07_25_090000_add_slack_capture_fields...php`, `Components/OperationsCenter/`, `Pages/OperationsCenter/`, 2 test files.

This is a **substantial uncommitted feature branch living in the working tree**. `git status` was byte-identical before and after the audit. Two pre-existing stashes were left untouched.

---

## 3. Actual Architecture

**Request path:** Browser → Laravel route (`routes/web.php`, 224 lines, 195 total routes) → Controller (58) → occasionally a Service (58) → Eloquent Model (77) → MySQL (92 tables) → Inertia `render` → React page (67 pages).

**Backend layering is inconsistent.** Task, Today, Reminder, Medication, Calendar and Agent flows go through services. Waiting/Decision/Blocker/Risk/Approval bypass services entirely via a shared abstract `CommandCenterController`. `SlackWebhookController` is a 1,500-line controller containing business logic directly.

**Frontend:** Inertia SSR-less SPA. Page components resolved by `import.meta.glob('./Pages/**/*.jsx')` — **a missing page throws a client-side error and renders a blank screen**, which is exactly what `/prioritization-review` does. `AuthenticatedLayout` holds a hardcoded 27-item, 3-group nav plus 8 hardcoded "Workstream" shortcuts. The largest component is `OperationsGraph.jsx` at **1,820 lines**.

**Queue:** `database` driver. Only **one** job class exists (`SendMedicationReminderJob`). Every other outbound call — Slack messages, OpenAI, Google Calendar — is **synchronous inside the web request or the scheduler process**.

**Scheduler:** 7 entries. `miriam:send-medication-reminders` and `miriam:send-reminders` run `everyMinute` with **no `withoutOverlapping()`**.

**Notifications:** One class, `TaskFlowNotification` (legacy name), database channel + optional mail. Mail is `log` driver, so no email leaves the machine.

**AI:** Four real OpenAI call sites exist, all currently cold. Everything the user sees labelled "AI" today is deterministic rule-based PHP.

**Agents:** Two systems. (a) `AgentOrchestratorService` — 8 rule-based markdown generators, synchronous, review-only. (b) `MiriamDevelopment*` — 12 models, 6 tables, a `tools/miriam-runner` directory and 4 console commands, **but 9 of its classes do not exist**, so it cannot boot.

**Integrations:** Slack (live, two endpoints, one broken), Google Calendar (complete, unconfigured), OpenAI (complete, unconfigured), AWS S3 (keys present, `FILESYSTEM_DISK=local`).

---

## 4. Complete Feature Matrix

| Area | Expected capability | Current implementation | Status | Runtime verified? | Evidence | User impact | Severity | Recommended next action |
|---|---|---|---|---|---|---|---|---|
| **Navigation / IA** | Command · Workspaces · Review · Capture · More | Flat 27-item, 3-group sidebar; "Work" group has 12 peers | **PARTIAL** | Source trace | [AuthenticatedLayout.jsx:8-51](resources/js/Layouts/AuthenticatedLayout.jsx:8) | No obvious starting point | P1 | Collapse to 5 groups; hide unfinished modules |
| Navigation | Health/medication reachable | `/health` exists, **absent from sidebar** | **BACKEND-ONLY** | Route list | route:list vs nav diff | Core personal feature hidden | P1 | Add to nav |
| Navigation | Blockers reachable | `/blockers` exists, absent from sidebar (Waiting/Decisions/Risks/Approvals are present) | **BACKEND-ONLY** | Route list | route:list vs nav diff | Inconsistent | P2 | Add or remove |
| Navigation | Areas/Goals/Calendar/Workload reachable | Exist, no sidebar entry | **BACKEND-ONLY** | Route list | route:list vs nav diff | Dead modules | P2 | Decide keep/hide |
| Navigation | Nav links resolve | "Prioritization" → `PrioritizationReview/Index.jsx` **does not exist** | **BROKEN** | Filesystem + resolver check | [PrioritizationReviewController.php:19](app/Http/Controllers/PrioritizationReviewController.php:19); no such `.jsx` | Blank screen | P1 | Build page or remove nav item |
| Navigation | Truthful product name | Sidebar says "Miriam", `APP_NAME=Friday`, `<title>Friday</title>`, fallback `'Friday'` | **PARTIAL** | Test output; `.env` | [app.jsx:8](resources/js/app.jsx:8) | Identity confusion | P2 | Rename after audit |
| **Capture — Slack** | Free-form text → record | Full pipeline: dedupe, parse, classify, confirm/edit/cancel/move-today | **WORKING WITH LIMITATIONS** | Automated test (`MiriamSlackThoughtCaptureTest` PASS) | [MiriamSlackThoughtCaptureService.php](app/Services/Miriam/MiriamSlackThoughtCaptureService.php) | Best capture path | — | Keep; fix time ambiguity |
| Capture — Slack | Preserve original text | `metadata.original_text` + task description | **WORKING END-TO-END** | Automated test | :96-112, :782-800 | Good | — | — |
| Capture — Slack | Show classification / allow correction | Confirmation card shows type/project/due/priority; "Edit" only replies with instructions | **PARTIAL** | Source trace | :241-250 | Cannot correct in place | P2 | Slack modal for edit |
| Capture — Slack | Ambiguous time clarification | AI path clarifies; AI is **off**, so deterministic path silently guesses (`hour < 8 → +12`) | **PARTIAL** | Source trace | :904 | Reminders fire at wrong hour | P1 | Ask AM/PM deterministically |
| Capture — Slack | No-date captures confirmed | No-date captures create a task **immediately, without confirmation**; dated ones ask | **WORKING WITH LIMITATIONS** | Source trace | :56-77 | Inconsistent | P2 | Unify |
| Capture — Slack | Project detection from registry | Hardcoded 10-project alias map in source | **STUB OR MOCK** | Source trace | :751-765 | New projects invisible | P2 | Read from `projects` |
| **Capture — in-app** | Quick capture from anywhere | Header "+ Create" → full task form; no quick-capture box on Today | **MISSING** | Source trace | [AuthenticatedLayout.jsx:207](resources/js/Layouts/AuthenticatedLayout.jsx:207) | High friction | P0 | Add capture bar |
| Capture — in-app | Capture Agent converts to record | Proposal → link to **blank** `/tasks/create`, no prefill | **UI-ONLY** | Source trace | [TaskCapture/Index.jsx:219](resources/js/Pages/Agents/TaskCapture/Index.jsx:219) | Lifecycle dead-ends | **P0** | Add "Create task from proposal" |
| Capture — voice | Dictation-first | Browser `webkitSpeechRecognition` in Assistant panel; falls back to "coming soon" | **PARTIAL** | Source trace | [AssistantPanel.jsx:51-60](resources/js/Components/AssistantPanel.jsx:51) | Chromium only, feeds chat not capture | P2 | Route voice into capture |
| Capture — voice | Server transcription | `AiTranscriptionService` → OpenAI Whisper; **no API key** | **NOT CONNECTED** | Config inspection | `.env` has no `OPENAI_API_KEY` | Unusable | P2 | Configure or label Planned |
| **Inbox** | Unresolved captures triaged | **Does not exist.** `section='inbox'` written, never read. Nav "Inbox" = notifications | **MISSING** | Grep across repo (0 readers) | [Service:313](app/Services/Miriam/MiriamSlackThoughtCaptureService.php:313) vs zero consumers | Captures invisible | **P0** | Build Inbox view |
| Inbox | Convert to task/reminder/decision/note | No conversion UI anywhere | **MISSING** | Source trace | — | Lifecycle broken | **P0** | Build converters |
| **Tasks** | Create / edit / complete | Works; activity log; audit log on complete | **WORKING END-TO-END** | Automated test (`TaskTest` PASS) | [TaskController.php:142-313](app/Http/Controllers/TaskController.php:142) | Solid | — | — |
| Tasks | Reminder scheduled on create | `store()` **never calls** `syncAfterTaskSaved`; update/complete/status/archive do | **BROKEN** | Source trace | TaskController.php:142-161 vs :240 | New task with due date gets no reminder | **P1** | Call sync in `store()` |
| Tasks | Reopen completed | `restore()` exists but is not exposed in the list; only from detail page | **PARTIAL** | Source trace | :328 | Hard to undo | P2 | Add to row menu |
| Tasks | Delete | **No delete route.** Archive only | **MISSING** | Route list | routes/web.php:185-203 | Cannot remove mistakes | P2 | Add soft delete |
| Tasks | Completed leaves active section | `upcoming`/`overdue` exclude completed; `all` = active then completed | **WORKING END-TO-END** | Source trace | :34-63 | Correct | — | — |
| Tasks | Prioritization buckets (Now/Week/Month/Later/Waiting/Drop) | Defined in `PrioritizationReviewService::BUCKETS`; **the only UI is the missing page** | **BACKEND-ONLY** | Filesystem | Service:15-22; no `.jsx` | Buckets unusable | P1 | Build the page |
| Tasks | Search / filter / sort | Search + status + priority + project filters | **WORKING WITH LIMITATIONS** | Source trace | [Tasks/Index.jsx:48-64](resources/js/Pages/Tasks/Index.jsx:48) | No sort control, no saved views | P2 | Add sorting |
| Tasks | Bulk actions | Only via the broken prioritization page | **BROKEN** | Filesystem | — | None available | P2 | Add to list |
| Tasks | Pagination | **None.** 4 unbounded `->get()` with 7 eager loads each | **BROKEN (at scale)** | Source trace | TaskController.php:34-59 | Page degrades badly | P1 | Paginate |
| Tasks | Recurrence | Daily/weekly/monthly, duplicate-guarded by `(recurring_parent_id, due_date)` | **WORKING WITH LIMITATIONS** | Source trace | [RecurringTaskService.php:29-36](app/Services/Tasks/RecurringTaskService.php:29) | Only regenerates on completion — skipped occurrences never appear | P2 | Add scheduled generation |
| Tasks | Dependencies | `parent_task_id` (subtasks) only; no blocking relations | **MISSING** | Model | Task.php:122-140 | No dependency chains | P3 | Design later |
| Tasks | Waiting / delegated state | `task_type` enum has `waiting_for`; separate `WaitingItem` model also exists | **PARTIAL** | Model + controller | Task.php:40-50; WaitingItemController | Two competing concepts | P2 | Pick one |
| Tasks | Attachments / comments | Both implemented with policies and download route | **WORKING END-TO-END** | Automated test (`TaskTest` PASS) | TaskAttachmentController, TaskCommentController | Good | — | — |
| **Today** | Operational home screen | 7 metric cards + 8 panels | **WORKING WITH LIMITATIONS** | Automated test (`MiriamTodayCommandCenterTest` PASS) | [Today/Index.jsx](resources/js/Pages/Today/Index.jsx) | Reads as an analytics dashboard | P1 | Reduce to 3 sections |
| Today | Real, current data | All panels query live models | **WORKING END-TO-END** | Source trace | [TodayCommandCenterService.php](app/Services/TodayCommandCenterService.php) | No fabricated data | — | — |
| Today | No duplication | Same task can appear in "Do this now", "Overdue and blocked", **and** "Due today detail" | **BROKEN** | Source trace | Today/Index.jsx:`LegacyTaskList` + `OverdueBlockedPanel` + `doThisNow` | Confusing triple-listing | P1 | De-duplicate |
| Today | Items actionable in place | "Mark done" calls `tasks.complete`, which **redirects to the task page** | **WORKING WITH LIMITATIONS** | Source trace | TaskController.php:279 | Kicked off Today on every completion | P1 | Return `back()` |
| Today | Product cards link somewhere useful | `href` = `tasks.index?search=<label>` with labels like `Miriam/Friday`, `Personal/Health` | **BROKEN** | Source trace | TodayCommandCenterService.php:345 | Links return zero results | P1 | Link to real filters |
| Today | Correct overdue / timezone | All task queries use UTC `now()`; reminders use `Asia/Dubai` | **BROKEN** | Source trace | [config/app.php:68](config/app.php:68); Task.php:198-211 | Wrong "today" 00:00–04:00 daily | **P0** | Set app timezone |
| Today | Development jobs scoped to user | `developmentJobs()` / `developmentFailures()` query **all rows, no user filter** | **BROKEN** | Source trace | TodayCommandCenterService.php:436-461 | Cross-user leak if multi-user | P1 | Scope by user |
| Today | Truthful integration labels | Medication and Codex badges reflect real records; Codex panel shown even though module cannot boot | **PARTIAL** | Source trace | :119-134, :291-309 | Implies a working Codex pipeline | P1 | Label "Not available" |
| Today | Codex "Open details" link | `route('today.index') . '#codex-workstream'` — links to itself | **BROKEN** | Source trace | :228, :394 | Dead end | P2 | Link to real detail |
| Today | Capture from Today | None | **MISSING** | Source trace | — | Must leave home screen | P0 | Add capture bar |
| **Reminders** | Create / schedule | Created via Slack capture and task sync | **WORKING WITH LIMITATIONS** | Test **FAILING** (26 in `MiriamReminderTest`) | [MiriamReminderService.php](app/Services/MiriamReminderService.php) | Cannot be trusted on this tree | **P0** | Fix tests |
| Reminders | Escalation, max pokes, dedupe | 3 attempts, `reminder_deduplicated` event, `exhausted` status | **WORKING WITH LIMITATIONS** | Source trace | :325-400 | Well designed, unverified | P1 | Re-verify |
| Reminders | Cancel on task completion | `syncAfterTaskSaved` cancels on complete/archive | **WORKING END-TO-END** | Source trace | :402-475 | Correct | — | — |
| Reminders | Done / snooze / cancel / tonight / tomorrow / today | Slack buttons + message update | **PARTIAL** | Tests failing | :565-720, :891 | Unverified | P1 | Re-verify |
| Reminders | In-app reminder list | **None.** Reminders are only visible in Slack and the mobile API | **MISSING** | Route list | — | Invisible in the web app | P1 | Add reminders view |
| Reminders | Delivery depends on infra | `schedule:run` every minute + `queue:work` for medication | **UNVERIFIED** | Cannot verify safely | routes/console.php:11-17 | Silent total failure if absent | **P0** | Verify + monitor |
| Reminders | Failure visibility | `slack_reminder_failed` events recorded; **no UI surfaces them** | **BACKEND-ONLY** | Source trace | :384 | Silent failures | P1 | Surface in System Health |
| **Medication** | Schedule / snooze / acknowledge / repeat | Full engine with quiet hours (22:00–07:00), hard deadlines, idempotent ack | **WORKING END-TO-END** | Automated test (`MedicationReminderTest` PASS) | [MedicationReminderService.php](app/Services/Health/MedicationReminderService.php) | Strongest module | — | — |
| Medication | Stale overdue handling | `closeStaleOverdueLogs()` on every cycle + dedicated command | **WORKING END-TO-END** | Automated test | :431-499 | Correct | — | — |
| Medication | Timezone | Per-schedule timezone, defaults `Asia/Dubai` | **WORKING END-TO-END** | Automated test | :123, :572 | Correct — unlike tasks | — | — |
| Medication | Slack buttons safe | Signature verified; acknowledged doses ignored | **WORKING END-TO-END** | Automated test | [SlackMedicationActionController.php:63](app/Http/Controllers/SlackMedicationActionController.php:63) | Good | — | — |
| Medication | Slack action authorization | **No `allowed_user_id` check** on this endpoint; `MedicationDoseLog::find()` unscoped | **BROKEN** | Source trace | :31, :16-20 | Any workspace member can ack your meds | P1 | Add owner check |
| Medication | Reachable in UI | `/health` **not in the sidebar** | **BACKEND-ONLY** | Route list | — | Hidden | P1 | Add to nav |
| **Slack layer** | `/slack/events` (capture + conversation) | Signature, replay window, retry drop, bot filter, allowed-user gate | **WORKING WITH LIMITATIONS** | Automated test PASS | [SlackEventsController.php](app/Http/Controllers/SlackEventsController.php) | Primary working path | — | — |
| Slack | `/webhooks/slack/events` (done/move/note/block/skip, dev status) | **HTTP 500 on every request** — 9 missing classes | **BROKEN** | **Runtime verified** (test: `ReflectionException … MiriamPromptQueueService does not exist`) | [routes/web.php:63](routes/web.php:63) | Whole legacy command set dead | **P0** | Remove route or restore classes |
| Slack | Signature verification | HMAC-SHA256, 300 s window, `hash_equals` | **WORKING END-TO-END** | Automated test | SlackEventsController.php:185-202 | Correct | — | — |
| Slack | `url_verification` before signature check | Challenge echoed **without** verifying the signature | **WORKING WITH LIMITATIONS** | Source trace | :23-27 | Unauthenticated echo endpoint | P3 | Verify first |
| Slack | Replay / duplicate protection | `X-Slack-Retry-Num` ⇒ drop. **No `event_id` dedupe** | **PARTIAL** | Source trace | :41-45 | A genuinely failed first delivery is lost forever | P1 | Dedupe on `event_id` |
| Slack | Interaction dedupe | None; `replace_original: false` leaves buttons live | **PARTIAL** | Source trace | :176-183 | Double-clicks possible (mitigated by status checks) | P2 | Replace message |
| Slack | Channel restriction | Fails **open** when `SLACK_MIRIAM_CHANNEL_ID` unset (it is) | **BROKEN** | Source trace + config | :215-224 | Captures from any channel | P1 | Fail closed |
| Slack | User → Miriam user mapping | `env('TASKFLOW_DAILY_USER_ID')` then `User::orderBy('id')->first()` | **STUB OR MOCK** | Source trace | :251-260 | Breaks under `config:cache`; single-user only | P1 | Real mapping table |
| Slack | Intents (status/blocker/next-action/start/pause/resume/release/demo) | Router handles ~7 read intents; start/pause/resume/release live in the **broken** controller | **PARTIAL** | Source trace | [MiriamBrainService.php:54-80](app/Services/Miriam/MiriamBrainService.php:54) | Half the vocabulary is dead | P1 | Consolidate |
| Slack | Approval gates for risky asks | External sends and medication schedule changes refused with an explanation | **WORKING END-TO-END** | Automated test | MiriamBrainService.php:32-52 | Excellent | — | — |
| Slack | Rate limit / retry handling | Single `Http::post`, no 429 handling, no retry, no backoff | **PARTIAL** | Source trace | MiriamReminderService.php:1079-1086 | Drops on rate limit | P2 | Add retry |
| Slack | Message logging | `Log::info` includes **full message text** | **WORKING WITH LIMITATIONS** | Source trace | :235-249 | PII in logs | P2 | Redact |
| **AI assistant** | Conversational help | Entirely rule-based; no LLM call | **STUB OR MOCK** | Source trace | [AiAssistantService.php:82-151](app/Services/Ai/AiAssistantService.php:82) | Not AI | P2 | Label clearly |
| AI assistant | Truthful state in UI | `enabled`, `provider`, `api_key_configured` all exposed | **WORKING END-TO-END** | Source trace | AssistantController.php:22-25 | Honest | — | — |
| AI assistant | Create task from suggestion | `assistant.actions.create-task` — workspace-scoped, validated, logged as `AiAction` | **WORKING END-TO-END** | Automated test (`AiAssistantTest` PASS) | AssistantController.php:53-83 | Works | — | — |
| **AI brain** | Structured LLM capture | `MiriamBrainService` — strict JSON schema, confidence floor 0.75, past-time guard, redaction, deterministic fallback | **NOT CONNECTED** | Config inspection | [MiriamBrainService.php:227-281](app/Services/Miriam/MiriamBrainService.php:227) | Best-designed AI code, entirely cold | P1 | Configure or label Planned |
| AI brain | Model identifiers | Defaults `gpt-5.4-mini`; options `gpt-5.4`, `gpt-5.4-nano` | **UNVERIFIED** | Cannot call API | config/services.php:47,53 | Enabling may 404 | P1 | Verify IDs before enabling |
| AI brain | Prompt injection defence | System prompt forbids sends; schema-constrained output; no tool execution from model output | **WORKING WITH LIMITATIONS** | Source trace | :412-447 | Reasonable | P2 | Add explicit tests |
| AI brain | Cost / token tracking | `max_output_tokens` setting only; **no usage recorded** | **MISSING** | Schema inspection | AiSetting model | No spend visibility | P2 | Record usage |
| AI settings | Authorization | **None.** Any authenticated user can set the global API key | **BROKEN** | Source trace | [AiSettingsController.php](app/Http/Controllers/Settings/AiSettingsController.php) — 0 auth checks | Privilege escalation / cost abuse | **P1** | Add policy |
| AI settings | Key at rest | `Crypt::encryptString`, `$hidden`, masked display | **WORKING END-TO-END** | Source trace | AiSetting.php:49-77 | Correct | — | — |
| **Agents (orchestrator)** | Multi-agent pipeline | 8 agents, all deterministic markdown template generators | **STUB OR MOCK** | Automated test (`AgentOrchestratorTest` PASS) | [AgentOrchestratorService.php:17-56](app/Services/Agents/AgentOrchestratorService.php:17) | Not real agents | P2 | Label honestly |
| Agents | Nothing executes without approval | `creates_actions_automatically: false`; outputs need review | **WORKING END-TO-END** | Automated test | :80-88 | Excellent safety | — | — |
| Agents | Approval produces an outcome | Approve/reject/send-to-today only **flip a status field**; no task/record is created | **UI-ONLY** | Source trace | [AgentOutputReviewController.php](app/Http/Controllers/Agents/AgentOutputReviewController.php) | Approval leads nowhere | P1 | Create records on approve |
| Agents | Run asynchronously | 7-agent pipeline runs inline in the HTTP request | **WORKING WITH LIMITATIONS** | Source trace | AgentOrchestratorService.php:`runPipeline` | Slow request | P3 | Queue it |
| Agents | Control Codex / Claude Code / Gemini | Generates prompt text to paste elsewhere; no process control | **MISSING** | Source trace | CodexClaudePromptAgent.php | Vision gap | P2 | Design properly |
| **Development / Agent OS** | App registry, tickets, runs, phases, approvals, releases | 12 models + 6 tables + runner scripts exist; **9 required classes do not** | **BROKEN** | **Runtime verified** (57 test failures) | See §16 missing-class list | Entire module unbootable | **P0** | Restore or excise |
| Dev OS | Development Manager page | Controller + `ProductBrain/DevelopmentManager.jsx` exist; **no route registered** | **BROKEN** | Route list | `DevelopmentManagerController` unrouted | Unreachable | P0 | Restore or remove |
| Dev OS | Runner heartbeat / job dispatch | Tests reference `/api/miriam/runner/*`; **no such routes** | **MISSING** | Route list | `MiriamDevelopmentManagerTest` — 404s | Runner cannot connect | P0 | Same |
| Dev OS | Console commands | `miriam:apps:seed-defaults`, `miriam:sprint-plan`, `miriam:runner-monitor`, `miriam:dev:create-test-failure` **not registered** | **MISSING** | **Runtime verified** (`CommandNotFoundException`) | Test output | Ops commands unavailable | P0 | Same |
| Dev OS | Release packages / approvals | Tables `miriam_release_packages`, `miriam_release_approvals` exist; **models do not** | **BROKEN** | Filesystem vs migrations | §16 | Orphan tables | P1 | Same |
| Dev OS | Failure classification & auto-repair (≤3, then notify) | `DevelopmentFailureClassifierService` + `RecoveryService` + `SmartSlackNotificationService` exist and are complete | **BACKEND-ONLY** | Source trace | app/Services/DevelopmentFailure*.php | Real logic stranded behind broken deps | P1 | Reconnect |
| **Operations Center** | Journey Flow / Mind Map / Technical Map | 3 tabs, real entity counts, honest per-node status, `routeIfExists` checks | **WORKING WITH LIMITATIONS** | Automated test (`OperationsCenterTest` PASS) | [OperationsCenterGraphService.php](app/Services/OperationsCenter/OperationsCenterGraphService.php) | Documentation, not control | P2 | Keep as reference |
| Ops Center | Command Map | **Does not exist** | **MISSING** | Source trace | tabs array = 3 entries | — | P3 | Design or drop |
| Ops Center | Agent Orchestrator graph | Implemented and route-accepted, but **not in the tabs list** | **BACKEND-ONLY** | Source trace | Controller `tabs` vs `validateView` | Hidden view | P3 | Add tab or remove |
| Ops Center | Canvas (zoom/pan/fit/fullscreen/minimap/search/filters/drag/resize/connect) | All present | **WORKING END-TO-END** | Source trace | [OperationsGraph.jsx](resources/js/Components/OperationsCenter/OperationsGraph.jsx) | Rich | — | — |
| Ops Center | Layout persistence | **`localStorage` only**; service declares `backend_layout_persistence: false` | **PARTIAL** | Source trace | OperationsGraph.jsx:110-126; Service:57-61 | Lost per device | P3 | Server-side layouts |
| Ops Center | Edits cannot damage records | **No write routes exist** — only 3 GET endpoints | **WORKING END-TO-END** | Route list | routes/web.php:71-73 | Safe by construction | — | — |
| Ops Center | Technical map traces real code | Hand-authored nodes enriched with live counts + route existence checks | **PARTIAL** | Source trace | Service:302-374 | Accurate but manual | P3 | Generate from code |
| **Business command center** | Clients, pipeline, leads, marketing, renewals, subscriptions, invoices, revenue | **No model, table, route, or page for any of these** | **MISSING** | Full model/migration inventory | 77 models, 92 tables — none commercial | Entire pillar absent | P2 | Scope separately |
| Business | Application/product registry | `MiriamManagedApp` model + table exist; unreachable (module broken) | **BACKEND-ONLY** | Route list | — | Not usable | P1 | Restore with Dev OS |
| Business | Portfolios / areas / goals as proxy | Implemented and working | **WORKING END-TO-END** | Automated test (`ReportTest`, `LeadershipVisibilityTest` PASS) | PortfolioController, GoalController | Partial substitute | — | — |
| **Personal command center** | Medication, workouts, daily health log | Implemented | **WORKING END-TO-END** | Automated test | HealthDisciplineController | Strong, but hidden | P1 | Expose in nav |
| Personal | Bible 90-day plan, progress, streak, missed, catch-up, journal | Fully implemented incl. Today + Slack summary | **WORKING WITH LIMITATIONS** | Automated test (`SpiritualAndNotesTest` PASS) | [SpiritualController.php](app/Http/Controllers/SpiritualController.php) | Needs `BibleContentSeeder` for verse text | P2 | Confirm seeded |
| Personal | Bible performance | Loads all 90 days + all chapters + per-chapter verse queries on **every Today load** | **WORKING WITH LIMITATIONS** | Source trace | SpiritualController.php:26-44; SpiritualReadingSummaryService.php:16-38 | Slow home screen | P2 | Cache today only |
| Personal | Daily check-in / weekly review | `SendEveningCheckin` command + `DailyReview` models; no web UI | **BACKEND-ONLY** | Route list | — | Slack-only | P2 | Add review screen |
| **Google Calendar** | OAuth connect/disconnect/reconnect | State token, ownership check on disconnect, truthful UI flags | **NOT CONNECTED** | Automated test (`GoogleCalendarIntegrationTest` PASS) | [IntegrationSettingsController.php](app/Http/Controllers/Settings/IntegrationSettingsController.php) | Complete but unconfigured | P2 | Configure |
| Google Calendar | Two-way sync, dedupe, logs | Push tasks/reminders/medication; pull current month; `extendedProperties` dedupe; `calendar_sync_logs` | **NOT CONNECTED** | Automated test | [CalendarSyncService.php](app/Services/Calendar/CalendarSyncService.php) | Good design | P2 | Configure |
| Google Calendar | Token expiry / refresh | Refresh token stored encrypted; `token_expires_at` tracked | **UNVERIFIED** | Cannot call API | CalendarConnection.php:33-42 | Unknown under expiry | P2 | Test after connecting |
| Google Calendar | Pull is bounded | Current month, single page, no pagination; task push capped at 100 silently | **WORKING WITH LIMITATIONS** | Source trace | CalendarSyncService.php:`pullExternalEvents`, `eligibleTasks` | Silent truncation | P2 | Paginate + log |
| **Gmail** | Email capture / triage | **No code whatsoever** | **MISSING** | Repo-wide grep | — | Vision gap | P3 | Scope separately |
| **Git provider** | Branch/commit/local-vs-remote comparison | Only inside the broken Dev OS runner | **BROKEN** | — | — | Unusable | P1 | With Dev OS |
| **Error monitoring** | Sentry/Bugsnag or equivalent | None. `withExceptions` is empty | **MISSING** | [bootstrap/app.php:26](bootstrap/app.php:26) | Failures invisible | P2 | Add reporting |
| **Notifications** | In-app inbox, read/read-all | Implemented, per-user, policy-guarded | **WORKING END-TO-END** | Automated test (`NotificationReminderCalendarTest` PASS) | [NotificationController.php](app/Http/Controllers/NotificationController.php) | Works | — | — |
| Notifications | Email delivery | `MAIL_MAILER=log` | **NOT CONNECTED** | `.env` | — | No email leaves the machine | P2 | Configure or state it |
| **System Health** | Truthful operational status | Checks boot, DB, tables, migrations, paths, build, config | **WORKING WITH LIMITATIONS** | Automated test (`SystemHealthTest` PASS) | [SystemHealthService.php](app/Services/System/SystemHealthService.php) | Useful | — | — |
| System Health | Queue check | Reports **"passed"** for any non-`sync` driver — does not check for a running worker or a job backlog | **BROKEN (misleading)** | Source trace | :148-160 | False confidence | **P1** | Check worker + backlog |
| System Health | Scheduler check | Verifies commands are *registered*, not that `schedule:run` executes; omits the two `everyMinute` reminder commands | **BROKEN (misleading)** | Source trace | :162-180 | False confidence | **P1** | Add heartbeat check |
| **Mobile API** | Login, agenda, reminders, medication, dev status | 17 endpoints, SHA-256 hashed bearer tokens, expiry honoured | **WORKING WITH LIMITATIONS** | Automated test (`MobileApiTest` PASS) | [routes/api.php](routes/api.php) | Works | — | — |
| Mobile API | Login rate limiting | **None** (`bootstrap/app.php` never calls `throttleApi()`) | **BROKEN** | Source trace | bootstrap/app.php:14-25 | Brute-forceable | P2 | Add throttle |
| Mobile API | Token expiry set | `expires_at` supported but **never set at creation** | **BROKEN** | Source trace | MiriamMobileController::login | Permanent tokens | P2 | Set TTL |
| **Knowledge / Mission Control** | Decisions, ideas, prompts, specs, meeting notes, lessons, traceability | `Decision`, `Note`, `MiriamSavedPrompt`, `AuditLog`, `TaskActivity` exist as **separate silos** | **PARTIAL** | Model inventory | — | No unified event layer or cross-record search | P2 | Design activity layer |
| Knowledge | Global search | Header search queries **tasks only** | **PARTIAL** | Source trace | [AuthenticatedLayout.jsx:116-121](resources/js/Layouts/AuthenticatedLayout.jsx:116) | Cannot find notes/decisions | P2 | Universal search |
| Knowledge | Decision → task → dev work traceability | No linkage | **MISSING** | Schema | — | Vision gap | P3 | Design later |

---

## 5. Navigation and UX Findings

### Why the application is hard to use

**The sidebar has 27 items with no hierarchy of importance.** The "Work" group alone holds 12 peers: Today, Operations Center, Inbox, Agents, Agent Orchestrator, Task Capture Agent, Tasks, Projects, Dashboard, Planner, Reports, Assistant. Three of those twelve are agent surfaces; two (Dashboard and Today) compete for the same job.

### Confusing navigation

- **Today vs Dashboard.** Both compute `collectTodayTasks()` and `selectTopFocusItems()` from the same service ([TodayController.php:24](app/Http/Controllers/TodayController.php:24), [DashboardController.php:31](app/Http/Controllers/DashboardController.php:31)). Two home screens, no stated difference.
- **Three agent entries** for two systems, one of which (Task Capture Agent) cannot produce a record.
- **Waiting For / Decisions / Risks / Approvals** are in "More", but **Blockers** — same base class, same shape — is in no menu at all.
- **Health** — your medication screen — is in no menu at all.
- **Areas, Goals, Calendar, Workload** are reachable only by typing the URL.

### Duplicate concepts

| Concept | Duplicate implementations |
|---|---|
| Waiting for someone | `Task.task_type = 'waiting_for'` **and** `WaitingItem` model/page |
| Decision | `Task.task_type = 'decision'` **and** `Decision` model/page |
| Blocker | `Task.task_type = 'blocker'` **and** `Blocker` model/page |
| Risk | `Task.task_type = 'risk'` **and** `Risk` model/page |
| Approval | `Task.task_type = 'approval'` **and** `Approval` model/page |
| Slack entry point | `/slack/events` (works) **and** `/webhooks/slack/events` (500) |
| Home screen | `/today` **and** `/dashboard` |
| Grouping | `Area` **and** `Portfolio` **and** `Project` **and** `Workspace` — four levels, all optional |

Nothing tells you which to use. Dashboard shows the `task_type` versions; the sidebar links to the dedicated-model versions. **They are different data.**

### Dead ends

- `/prioritization-review` → React page does not exist → blank screen.
- `/webhooks/slack/events` → HTTP 500.
- Today's product cards → `tasks.index?search=Miriam/Friday` → matches nothing.
- Today's Codex "Open details" → `today.index#codex-workstream` → the page you are on.
- Task Capture Agent proposal → "Review as task" → empty form.
- Agent output "Approve" → status flips → nothing else happens.

### Empty screens and empty states

Empty states exist and are well written (`EmptyState` component used on Today, Tasks, Waiting, Backlog). But they describe *what would appear*, not *what to do next*. Tasks says "Create a task or adjust filters"; Today's "No fires right now" suggests "review the Today by product cards" — cards whose links go nowhere.

### Hidden primary actions

The single most important action — **capture a thought** — has no dedicated control. The header's "+ Create" opens a full task form with ~15 fields (`Tasks/Create` → `TaskForm.jsx`). On mobile that button is hidden entirely (`hidden sm:inline-flex`, [AuthenticatedLayout.jsx:207](resources/js/Layouts/AuthenticatedLayout.jsx:207)).

### Technical terminology exposed to the user

"Agent Orchestrator", "Task Capture Agent", "Operations Center", "Journey Flow", "Technical Map", "Codex workstream", "Runner agent", "Phase run", "Prompt program", "Portfolio", "Workspace", "AI Brain". Several describe internal architecture rather than an action you want to take.

### Unclear statuses

Six task statuses (`todo, in_progress, blocked, review, completed, archived`), five board statuses, four priorities, nine task types, six prioritization buckets — **and the buckets have no UI**. Meanwhile `MiriamReminder` has its own status vocabulary (`awaiting_confirmation, pending, snoozed, done, cancelled, expired, exhausted`) and `MiriamDevelopmentJob` has nine more.

### Misleading connection states

- **System Health "Queue: passed"** for any non-`sync` driver, regardless of whether a worker exists ([SystemHealthService.php:148](app/Services/System/SystemHealthService.php:148)).
- **System Health "Scheduler commands: passed"** verifies registration only — and omits `miriam:send-medication-reminders` and `miriam:send-reminders`, the two that actually matter ([:162](app/Services/System/SystemHealthService.php:162)).
- The **Codex panel on Today** implies an operating pipeline behind a module that cannot boot.

Google Calendar and AI Assistant, by contrast, **do** report their state truthfully — good precedents to copy.

### Non-actionable dashboard cards

Today's seven metric cards (Critical, Overdue, Due today, Waiting for me, Codex running, Blocked, Medicine) are display-only — none is clickable or filterable.

### Mobile and tablet

Sidebar collapses correctly and there is a mobile drawer in the graph component. But: the "+ Create" button is hidden below `sm`; Today's metric grid is `xl:grid-cols-7` (seven columns stack into a very long scroll on tablet); the Tasks list grid is `lg:grid-cols-[...]` so below `lg` every row's status/priority/assignee/due stack vertically. A separate Expo app exists at `mobile/miriam-app` but is outside this repository's build.

**One dead CSS bug:** the Tasks table header row carries both `grid` and `hidden` ([Tasks/Index.jsx:67](resources/js/Pages/Tasks/Index.jsx:67)) — `hidden` wins, so column headers never render at any breakpoint.

---

## 6. End-to-End Journey Results

| # | Journey | Start | Steps | Expected | Actual | Evidence | Verdict | Blocking defect |
|---|---|---|---|---|---|---|---|---|
| 1 | Capture a thought → task | Slack DM to Miriam | Send "remind me to call the client tomorrow" → confirmation card → Confirm | Task + reminder created | Works: reminder `awaiting_confirmation` → confirm → `Task` created with `section='inbox'`, reminder `pending` | `MiriamSlackThoughtCaptureTest` **PASS** | **PASS (Slack only)** | — |
| 1b | Capture a thought → task **in-app** | `/agents/task-capture` | Paste text → run → proposal → "Review as task" | Prefilled task form | Blank `/tasks/create` — nothing carried over | [TaskCapture/Index.jsx:219](resources/js/Pages/Agents/TaskCapture/Index.jsx:219) | **FAIL** | No conversion path |
| 2 | Create a task → appears in Today | `/tasks/create` | Fill form, due = today, save | Task on Today | Appears — **unless** local time is 00:00–04:00, when UTC `now()` still reports yesterday | [config/app.php:68](config/app.php:68); Task scopes :198-211 | **PARTIAL** | Timezone |
| 2b | Created task gets a reminder | `/tasks/create` | Save with due date | Reminder scheduled | **No reminder.** `store()` never calls `syncAfterTaskSaved` | TaskController.php:142-161 vs :240 | **FAIL** | Missing sync call |
| 3 | Complete a task → moves to Completed | `/tasks` | Click the completion circle | Row moves to Completed | Works. `status()` returns `back()`; `all` orders active-then-completed | TaskController.php:284-313, :51-63 | **PASS** | — |
| 3b | Complete from Today | `/today` | Click "Mark done" | Stay on Today | **Redirected to the task detail page** (`complete()` returns `redirect()->route('tasks.show')`) | TaskController.php:279 | **PARTIAL** | Wrong redirect |
| 4 | Reminder → acknowledge / snooze | Slack | Click Done / Snooze | Status updates, message updated | Handlers exist; **26 `MiriamReminderTest` tests fail on the current tree**, incl. "duplicate clicks keep current terminal status" | Test run | **FAIL** | Uncommitted refactor broke tests |
| 4b | Medication → acknowledge / snooze | Slack | Click Taken / Snooze 15 | Idempotent update + audit | Works, idempotent, event-logged | `MedicationReminderTest` **PASS** | **PASS** | — |
| 5 | Waiting/follow-up surfaces at the right time | `/waiting` | Create with follow-up date | Appears on Today when due | `waitingItems()` filters `follow_up_date <= today` and appears in "Waiting on me" | TodayCommandCenterService.php:231-253 | **PASS** | — |
| 6 | Today → original record | `/today` | Click an item | Land on the record | Tasks/approvals/waiting/blockers link correctly. **Codex items link to `today.index#codex-workstream`; product cards link to searches that match nothing** | :228, :345, :394 | **PARTIAL** | Dead links |
| 7 | Slack status query, no write | Slack | "development status" | Read-only reply | Router returns `development_status_query` → `MiriamToolExecutor`. No write. The richer status vocabulary lives in the **500-ing** controller | MiriamBrainService.php:74; routes/web.php:63 | **PARTIAL** | Half the vocabulary dead |
| 8 | Slack action requiring confirmation | Slack | Capture with a date | Confirm/Edit/Cancel/Move-to-Today card | Card rendered with 4 buttons; 12-hour expiry | Service:441-480; `MiriamSlackThoughtCaptureTest` **PASS** | **PASS** | — |
| 9 | Cancel a pending Slack action | Slack | Click Cancel | Nothing created | `cancel()` sets `cancelled`, clears `next_reminder_at`, records event, idempotent | Service:218-239 | **PASS** | — |
| 10 | Inspect an application's dev status | Web | Open Development Manager | App status page | **No route exists**; controller depends on 3 missing classes | Unrouted; test `Route [product-brain.development-manager.index] not defined` | **FAIL** | Module broken |
| 11 | Trace ticket → agent run → output | Web | Follow the chain | Full trace | Orchestrator runs/outputs/logs **do** trace (`agents.orchestrator.runs.logs`). The Codex/dev ticket chain does not exist | AgentOrchestratorController.php:71-99 | **PARTIAL** | Two disconnected systems |
| 12 | Stop / pause an agent safely | Source only | — | Safe stop | `stop job cancels safely` test exists but **fails** (missing classes). Orchestrator agents are synchronous — nothing to stop | Test run | **UNVERIFIED** | Module broken |
| 13 | Review a failed agent run | `/agents/orchestrator` | Open a failed run | Error + logs | `error_message` + per-agent logs via JSON endpoint, ownership-checked | AgentOrchestratorController.php:71 | **PASS** | — |
| 14 | View Journey / Mind / Technical / Command maps | `/operations-center` | Switch tabs | 4 maps | **3 maps.** Agent-Orchestrator view exists but is not in the tab list. **No Command Map** | Controller `tabs` vs `validateView`; `OperationsCenterTest` **PASS** | **PARTIAL** | Command Map absent |
| 15 | Complete today's Bible reading | `/spiritual` | Tick a chapter | Progress + streak update | `toggleReading` upserts `BibleReadingProgress`; summary recomputes; Today reflects it | `SpiritualAndNotesTest` **PASS** | **PASS** | Verse text needs seeder |
| 16 | Review medication history | `/health` | Open history | Dose log + events | Implemented — but `/health` is **not in the sidebar** | route:list vs nav | **PARTIAL** | Hidden |
| 17 | Truthful connection status | `/settings/integrations`, `/settings/ai`, `/settings/system-health` | Read status | Honest state | Calendar ✅ truthful, AI ✅ truthful, **Queue ❌ and Scheduler ❌ misreport "passed"**. **Slack and Git have no status page at all** | SystemHealthService.php:148-180 | **PARTIAL** | Misleading infra checks |

**Journey tally:** 7 PASS · 7 PARTIAL · 4 FAIL · 1 UNVERIFIED.

---

## 7. Data and Workflow Integrity

### Persistence
Sound. Transactions wrap multi-step writes (`MiriamSlackThoughtCaptureService::capture` and `::confirm`, `TaskCaptureAgent::run`). Encrypted casts on calendar tokens; `Crypt` on AI keys; SHA-256 on mobile tokens.

### Status transitions
`completed_at` is set/cleared consistently in `store`, `update`, `complete`, `status`, `restore`, and `PrioritizationReviewService::apply`. **However**, `PrioritizationReviewService::apply` accepts `Task::STATUSES` including `archived` and writes it without an activity/audit distinction, and `TaskController::status()` restricts to `BOARD_STATUSES` — two different rule sets for the same field.

### Duplication
- **Task/record duplication by design:** five concepts each have both a `task_type` and a dedicated model (§5). No sync between them.
- **Today panel duplication:** one task can render in three panels simultaneously.
- **Capture duplication is well guarded**: `source_dedupe_key` is `UNIQUE` on both `tasks` and `miriam_reminders` ([migration 2026_07_25_090000](database/migrations/2026_07_25_090000_add_slack_capture_fields_to_tasks_and_miriam_reminders.php)), and `createTask` re-checks before insert.
- One dedupe subtlety: `existingReminder()` matches `source_dedupe_key` **OR** `source_message_ts` globally, without channel scoping — a theoretical cross-channel false-positive.

### Recurrence
Guarded against duplicates by `(recurring_parent_id, due_date)` existence check ([RecurringTaskService.php:29](app/Services/Tasks/RecurringTaskService.php:29)). **But occurrences are generated only on completion** — skip a daily task for a week and no occurrences exist for those days.

### Timezone — the central integrity defect
Three regimes coexist:
1. `config/app.php` → **`UTC`** — governs `now()` in all Task scopes, `DailyReviewService`, `TodayCommandCenterService`, `SpiritualController`, `TaskController`.
2. `Asia/Dubai` hardcoded in `MiriamReminderService`, `MiriamBrainService`, `MiriamSlackThoughtCaptureService`, `MedicationReminderService`, `GoogleCalendarService`.
3. Browser-local in `Today/Index.jsx::tomorrowDate()`, which uses `toISOString()` — **UTC** — so "snooze to tomorrow" after 20:00 Dubai sets *today's* date.

Consequence: from 00:00 to 04:00 Dubai, tasks due today are not "due today", yesterday's tasks are not "overdue", and Today shows the wrong day — while medication reminders remain correct. **There is no per-user timezone field on `users`.**

### Audit trail
Strong where it exists: `task_activities`, `audit_logs`, `miriam_reminder_events`, `medication_reminder_events`, `agent_run_logs`, `calendar_sync_logs`, `miriam_tool_audits`. **All are unbounded** — no pruning, no retention policy.

Gaps: `TaskController::store()` writes a `TaskActivity` but no `AuditLog`; `PrioritizationReviewService::apply` writes activity but no audit log; Slack events are logged to the application log (with full message text) rather than to a queryable table.

### Queues
Only `SendMedicationReminderJob` uses the queue. It has no `$tries`, no `$backoff`, no `ShouldBeUnique`. Everything else is synchronous. `QUEUE_CONNECTION=database` means **medication reminders silently stop if no worker runs**, with no alert.

### Retries and idempotency
- Medication: **excellent** — `ACKNOWLEDGED_STATUSES` guard before every mutation.
- Slack capture: **good** — unique dedupe keys, `confirm()` short-circuits when `task_id` already set.
- Reminders: dedupe via `last_sent_at >= next_reminder_at`, but it is a **read-then-write without a lock** — two overlapping scheduler runs could both pass the check.
- Slack retries: `X-Slack-Retry-Num` is **dropped**. If the first delivery genuinely failed, the capture is lost with no record.

### Scheduler
7 entries; two run `everyMinute` with **no `withoutOverlapping()`**. If a Slack call hangs, runs stack. Combined with the unlocked dedupe check, this is the most plausible route to duplicate reminders.

---

## 8. Integration Audit

| Integration | Implemented | Configured | Authenticated | User-linked | Sync | Token expiry | Failure surfaced | Reconnect | Disconnect | Audit trail | UI truthful | Duplicate risk | Depends on |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **Slack — `/slack/events`** | Yes | **Yes** | HMAC sig + 300 s window | Via `SLACK_ALLOWED_USER_ID` → `TASKFLOW_DAILY_USER_ID` | Inbound events + outbound messages | n/a (bot token) | Events table only, **no UI** | n/a | n/a | `miriam_reminder_events` + app log | **No status page** | Low (unique dedupe keys) | Nothing (sync) |
| **Slack — `/webhooks/slack/events`** | Yes | Yes | Never reached | — | — | — | **HTTP 500** | — | — | — | Not represented | — | 9 missing classes |
| **Slack — `/slack/medication/actions`** | Yes | Yes | HMAC sig | **No user check at all** | Button actions | n/a | `medication_reminder_events` | n/a | n/a | Yes | No status page | Low (idempotent) | Nothing |
| **Google Calendar** | Yes, thoroughly | **No** (`enabled=false`, no client id/secret) | OAuth2 + CSRF state | `calendar_connections.user_id` | **Two-way** (push tasks/reminders/meds; pull current month) | `token_expires_at` stored; refresh path present, **untested** | `calendar_sync_logs` + shown in Settings | Re-run connect | Yes, ownership-checked | `calendar_sync_logs` | **Yes — truthful** | Low (`extendedProperties` + mapping table) | Hourly scheduler (gated on `enabled`) |
| **OpenAI — Miriam Brain** | Yes (strict JSON schema) | **No** | Bearer, per-request | n/a | n/a | n/a | Logged + `ai_parse_failed` event | n/a | n/a | `miriam_reminder_events` | Not shown to the user | n/a | Nothing |
| **OpenAI — AI Brain / Ask** | Yes | **No** | Bearer from encrypted `ai_settings` row | Global row, not per-user | n/a | n/a | Returns a message | Test-connection button | n/a | `ai_actions` | **Yes — truthful** | n/a | Nothing |
| **OpenAI — Transcription** | Yes | **No** | Bearer | n/a | n/a | n/a | Fallback response | n/a | n/a | No | Not shown | n/a | Nothing |
| **OpenAI — Assistant** | Rule-based only, **no call** | `provider=mock` | n/a | n/a | n/a | n/a | n/a | n/a | n/a | `ai_conversations` | **Yes — truthful** | n/a | Nothing |
| **Git provider** | Only inside the broken Dev OS runner | — | — | — | — | — | — | — | — | — | Not represented | — | Missing classes |
| **Deployment platform** | None found | — | — | — | — | — | — | — | — | — | — | — | — |
| **Error monitoring** | None (`withExceptions` empty) | — | — | — | — | — | — | — | — | — | — | — | — |
| **Email** | Laravel notifications | `MAIL_MAILER=log` | n/a | Per-user | Outbound | n/a | Log file only | n/a | n/a | `notifications` | **Not represented — UI implies mail is sent** | Low | Nothing |
| **AWS S3** | Keys in `.env` | `FILESYSTEM_DISK=local` — **not used** | — | — | — | — | — | — | — | — | — | — | — |
| **Gmail** | **No code** | — | — | — | — | — | — | — | — | — | — | — | — |

No credential value was read, printed, or transmitted at any point in this audit.

---

## 9. AI and Agent Safety Audit

### Context construction and data exposure
`MiriamBrainService::callResponsesApi` sends **only** `{message, timezone, now_local, user_id}` — the raw Slack text plus a numeric user id. No task list, no client data, no project names. This is a genuinely conservative context. `AiBrainService` and `AiContextBuilder` build richer contexts bounded by the `max_tasks_sent` setting (default configurable, 1–200). **No PII redaction layer exists** before either call — a Slack message containing a client name or a phone number goes to OpenAI verbatim. Currently moot (no key configured), but it must be resolved before enabling.

### Prompt injection
The system prompt explicitly forbids sending anything and constrains the output to a strict JSON schema with an enum-bounded `intent`. Crucially, **model output never selects a tool that executes an external action** — the highest-privilege outcomes are `create_task` and `create_reminder`, both local writes. Two hard gates run *before* the model is consulted and cannot be overridden by it: external-send requests and medication-schedule changes both return `approval_required` ([MiriamBrainService.php:32-52](app/Services/Miriam/MiriamBrainService.php:32)). **This is the right architecture.** There are no dedicated prompt-injection tests.

### Output validation and structured output
`strict: true` JSON schema with `additionalProperties: false` and all fields required. Post-parse the service re-validates: unusable payloads are rejected, past times force clarification, confidence below 0.75 forces confirmation. Deterministic `SmartSlackCaptureParser` runs **first** and AI is only consulted when the deterministic path is uncertain — and if AI fails, it falls back to asking for confirmation rather than guessing.

### Approval and execution truthfulness
- All 8 orchestrator agents are registered with `mode: rule_based`, `external_api_required: false`, `creates_actions_automatically: false`.
- `AgentOutput::STATUS_NEEDS_REVIEW` is the default; approval is explicit and ownership-checked.
- **Nothing in the current codebase can start, stop, commit, or deploy anything.** No `exec`, `shell_exec`, `proc_open`, `system`, or `passthru` appears anywhere in `app/`. The shell-running components live in `tools/miriam-runner`, which is outside the application and currently unbootable.
- **Weakness:** approving an output produces no downstream record. A recommendation is *visually* distinct from an executed action, but only because nothing is ever executed.

### Logging
`agent_run_logs` per agent per run, retrievable via an ownership-checked JSON endpoint. `miriam_tool_audits` table exists for tool execution. Both unbounded.

### Retries and auto-repair
`DevelopmentFailureClassifierService`, `DevelopmentFailureRecoveryService`, `DevelopmentFixPromptBuilderService` and `MiriamSmartSlackNotificationService` implement exactly the intended policy — classify, attempt safe repair up to a bounded count, deduplicate Slack alerts, escalate only on hard gates. **This logic is complete and stranded**: it can only be reached through `SlackWebhookController` and `DevelopmentManagerController`, both of which cannot resolve their dependencies. Test names confirm the intent (`monitor deduplicates slack alerts for same issue`, `manual validation requested stays quiet for normal failure`) — all currently failing for the same missing-class reason.

### Cost and token visibility
`max_output_tokens` is a setting. **No token usage, request count, or spend is recorded anywhere.** There is no cost surface.

### Production safeguards
No agent touches production. No deployment code exists. `TASKFLOW_ALLOW_DEMO_SEEDING` guards both seeders from running outside local/testing. `SystemHealthService` warns when `APP_DEBUG` is true in production.

**Note for when AI is enabled:** the configured model identifiers (`gpt-5.4-mini`, `gpt-5.4-nano`, `gpt-5.4`) could not be validated without calling the API. Confirm them against the provider's current model list before switching `MIRIAM_AI_ENABLED` on, or the first live call will fail.

---

## 10. Security Findings

### P0 — Critical

**P0-1 · Mass task modification via unscoped bulk endpoint (IDOR)**
`PATCH /prioritization-review/apply` validates `task_ids.*` with `Rule::exists('tasks','id')` only, then `PrioritizationReviewService::apply()` calls `$task->update()` with **no ownership or workspace check**.
*Evidence:* [PrioritizationReviewController.php:33-70](app/Http/Controllers/PrioritizationReviewController.php:33); [PrioritizationReviewService.php `apply()`](app/Services/TaskReview/PrioritizationReviewService.php)
*Exploitation:* Any authenticated user posts `task_ids[]=1..N`, `status=archived`, `confirmation=1` and archives or completes every task in the database. No policy is consulted; no audit log is written.
*User impact:* Silent, irreversible mass data corruption across all users.
*Correction:* Scope the query to the caller's accessible workspaces and run `Gate::authorize('update', $task)` per task inside the loop.
*Blocks:* Production use. (Single-user local use is unaffected in practice.)

**P0-2 · Permanently failing public endpoint**
`POST /webhooks/slack/events` returns HTTP 500 on every request — `ReflectionException: Class "App\Services\MiriamPromptQueueService" does not exist`. Runtime-verified.
*Evidence:* [routes/web.php:63](routes/web.php:63); test output (`DailyExecutionTest`, `AiBrainSlackTest`)
*Exploitation:* Not an attack path, but with `APP_DEBUG=true` (current `.env`) a 500 renders a **full stack trace with absolute file paths and framework internals** to the caller.
*User impact:* Slack retries hammer the endpoint; all `done/move/note/block/skip` commands are dead.
*Correction:* Remove the route or restore the classes. Independently, ensure `APP_DEBUG=false` wherever this is reachable.
*Blocks:* Daily use of Slack task commands; production use.

### P1 — High

**P1-1 · Unauthorized control of global AI settings**
`/settings/ai` (GET and PATCH) has **zero** authorization checks and operates on a single global `ai_settings` row (`whereNull('workspace_id')`).
*Evidence:* [AiSettingsController.php](app/Http/Controllers/Settings/AiSettingsController.php) — no `Gate`, no policy, no role check
*Exploitation:* Any authenticated user replaces the OpenAI API key, enables AI, raises `max_output_tokens` to 20,000, and drives spend on the owner's account. The `test_connection` action also lets a caller validate an arbitrary key against OpenAI using the server as a proxy.
*Correction:* Require `canManageWorkspace`; scope settings per workspace.
*Blocks:* Production use.

**P1-2 · Cross-user data disclosure in Command Center pages**
`CommandCenterController::index()` sends **all** areas, **all** portfolios, **all** projects, and **200 task titles** from the entire database to any authenticated user, on `/waiting`, `/decisions`, `/blockers`, `/risks`, `/approvals`.
*Evidence:* [CommandCenterController.php:57-62](app/Http/Controllers/CommandCenterController.php:57) — none of the four `options` queries filters by user or workspace
*Exploitation:* Log in as any user, open `/waiting`, read the Inertia payload.
*Correction:* Scope all four to `accessibleWorkspaceIds()`.
*Blocks:* Production use.

**P1-3 · Slack channel restriction fails open**
`isMiriamChannel()` returns `true` when `SLACK_MIRIAM_CHANNEL_ID` is unset — and it is unset.
*Evidence:* [SlackEventsController.php:215-224](app/Http/Controllers/SlackEventsController.php:215)
*Exploitation:* Anything the bot can see in any channel becomes a Miriam capture (constrained only by `SLACK_ALLOWED_USER_ID`).
*Correction:* Fail closed; require explicit channel configuration.

**P1-4 · Medication Slack actions lack any user authorization**
`SlackMedicationActionController` never checks `services.slack.allowed_user_id`, and looks up `MedicationDoseLog::find($doseLogId)` with no ownership check.
*Evidence:* [SlackMedicationActionController.php:16-53](app/Http/Controllers/SlackMedicationActionController.php:16)
*Exploitation:* Any Slack workspace member who can reach a Miriam medication message can mark another user's dose taken or snoozed by supplying a different `value`.
*User impact:* Falsified health records; a genuinely missed dose recorded as taken.
*Correction:* Enforce the allowed-user gate and verify `$log->user_id`.

**P1-5 · Reminder ownership check fails open when `slack_user_id` is blank**
`canSlackUserAct()` returns `true` for any caller when the reminder has no `slack_user_id` — which is the case for scheduler- and task-created reminders.
*Evidence:* [MiriamSlackThoughtCaptureService.php:252-255](app/Services/Miriam/MiriamSlackThoughtCaptureService.php:252); [SlackMedicationActionController.php:`handleMiriamReminderAction`](app/Http/Controllers/SlackMedicationActionController.php)
*Correction:* Resolve ownership through `reminder->user_id`, not the Slack id.

**P1-6 · `env()` used outside configuration**
Two runtime `env()` calls return `null` once `php artisan config:cache` runs (standard production practice).
*Evidence:* [SlackEventsController.php:221](app/Http/Controllers/SlackEventsController.php:221) (`SLACK_MIRIAM_CHANNEL_ID`), [:253](app/Http/Controllers/SlackEventsController.php:253) (`TASKFLOW_DAILY_USER_ID`); [MiriamReminderService.php:1092](app/Services/MiriamReminderService.php:1092)
*Failure scenario:* After caching config, all Slack captures are attributed to `User::orderBy('id')->first()` — potentially the wrong person — and the channel gate opens fully.
*Correction:* Move both to `config/services.php`.

**P1-7 · Cross-workspace object attachment**
`area_id` and `portfolio_id` in `TaskController::validatedTaskData()` — and all four foreign keys in `CommandCenterController::validatedData()` — are validated with bare `Rule::exists`, without workspace scoping (unlike `project_id` and `parent_task_id`, which *are* scoped).
*Evidence:* [TaskController.php:353-354](app/Http/Controllers/TaskController.php:353); [CommandCenterController.php:100-104](app/Http/Controllers/CommandCenterController.php:100)
*Correction:* Add the same `whereIn('workspace_id', $workspaceIds)` constraint.

**P1-8 · Development job data not user-scoped on Today**
`developmentJobs()` and `developmentFailures()` query all rows.
*Evidence:* [TodayCommandCenterService.php:436-461](app/Services/TodayCommandCenterService.php:436)
*Correction:* Scope by user or workspace.

### P2 — Medium

- **P2-1 · No rate limiting on the mobile API.** `bootstrap/app.php` never calls `throttleApi()`; `POST /api/mobile/login` is unthrottled. Web login *is* throttled (5 attempts). → Add `throttle:` to the api group.
- **P2-2 · Mobile tokens never expire.** `expires_at` is supported by the middleware but never set at creation. → Set a TTL; add revocation UI.
- **P2-3 · Full Slack message text written to application logs.** [SlackEventsController.php:243](app/Http/Controllers/SlackEventsController.php:243). With `LOG_LEVEL=debug`, every captured thought — including client names and personal health details — lands in `storage/logs`. → Redact or hash.
- **P2-4 · `APP_DEBUG=true`.** Acceptable locally; combined with P0-2 it exposes stack traces. → Verify it is false in any hosted environment.
- **P2-5 · Whole `User` model shared to the frontend on every request.** [HandleInertiaRequests.php:36](app/Http/Middleware/HandleInertiaRequests.php:36) → Send an explicit field subset.
- **P2-6 · `url_verification` handled before signature verification.** [SlackEventsController.php:23](app/Http/Controllers/SlackEventsController.php:23) → an unauthenticated echo endpoint. Verify first.
- **P2-7 · No CSRF on three Slack routes** — correct and necessary, but it means signature verification is the *only* control. P1-3/P1-4/P1-5 therefore carry more weight than they otherwise would.
- **P2-8 · No error monitoring.** `withExceptions` is empty; failures are invisible outside log files.
- **P2-9 · Unbounded audit/event tables.** Seven append-only tables with no retention policy.

### P3 — Low

- **P3-1 · No `withoutOverlapping()`** on the two `everyMinute` scheduled commands.
- **P3-2 · `Label::firstOrCreate` race** in `TaskController::syncLabels` — possible duplicate labels under concurrency.
- **P3-3 · `accessibleWorkspaceIds()` uncached** — 2 queries per call, called once per policy check, per task.
- **P3-4 · CI never runs on push** — `tests.yml` triggers on `master`/`*.x`; the branch is `main`.
- **P3-5 · Hardcoded personal data in source** — project alias map includes a personal name ([MiriamSlackThoughtCaptureService.php:763](app/Services/Miriam/MiriamSlackThoughtCaptureService.php:763)); sidebar shortcuts hardcode client names.
- **P3-6 · Production hostname in a config default** — `redirect_uri` defaults to `https://friday.paulstechnologies.com/...` ([config/services.php:68](config/services.php:68)).

**No SQL injection, XSS, path traversal, or command injection was found.** All queries use Eloquent/parameter binding; React escapes by default with no `dangerouslySetInnerHTML` anywhere; file downloads go through a policy-guarded controller; no shell execution exists in `app/`.

---

## 11. Test and Quality Assessment

### Discovered

| Suite | Files | Location |
|---|---|---|
| PHPUnit Feature | 38 | `tests/Feature` |
| PHPUnit Unit | 1 | `tests/Unit` |
| Playwright e2e | 11 specs | `tests/e2e` |

### Executed — full PHPUnit suite (in-memory SQLite, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`)

```
Tests:    136 failed, 369 passed (2458 assertions)
Duration: 115.17s
```

| Result | Count | Share |
|---|---|---|
| Passed | 369 | 73.1% |
| **Failed** | **136** | **26.9%** |
| Skipped | 0 | — |
| Total | 505 | |

**37 of 39 test classes pass entirely.** All 136 failures come from **4 classes**:

| Class | Failures | Root cause |
|---|---|---|
| `MiriamDevelopmentManagerTest` | ~93 | 9 missing classes; 4 unregistered commands; 2 undefined routes |
| `MiriamReminderTest` | 26 | Uncommitted `MiriamReminderService` refactor (+430 lines) diverged from tests |
| `AiBrainSlackTest` | 8 | Posts to `/webhooks/slack/events` → 500 (missing classes) |
| `DailyExecutionTest` | 9 | 8 post to `/webhooks/slack/events` → 500; 1 (`today page loads`) is an assertion against rendered HTML |

**Distinct causes:**

| Cause | Occurrences |
|---|---|
| `Class "App\Services\MiriamPromptQueueService" does not exist` | 57 |
| `Class "App\Services\MiriamRunnerMonitoringService" does not exist` | 3 |
| `The command "miriam:apps:seed-defaults" does not exist` | 15 |
| `The command "miriam:dev:create-test-failure" does not exist` | 2 |
| `The command "miriam:sprint-plan" / "miriam:runner-monitor" does not exist` | 2 |
| `Route [product-brain.development-manager.*] not defined` | 2 |
| Expected 200, received 500 | 22 |
| Assertion mismatches in `MiriamReminderTest` | 26 |

The `MiriamReminderTest` failures are **not** cosmetic. Alongside message-format mismatches they include:
- `Failed asserting that table [miriam_reminders] matches expected entries count of 2. Entries found: 1.` — multi-line capture no longer creates multiple items
- `Failed asserting that table [miriam_reminders] matches expected entries count of 0. Entries found: 1.` — a reminder is created where none should be
- `An expected request was not recorded` (×6) — expected Slack calls never fire
- `ModelNotFoundException` (×2) — clarification resolution path broken
- `Failed asserting that null is identical to 'google-reminder-1'` — calendar event id not persisted

### Other checks executed

| Check | Result |
|---|---|
| `php artisan route:list` | **PASS** — application boots, 195 routes |
| `npm run build` (production Vite build) | **PASS** — built in 9.82 s, no errors |
| Missing-class static scan (`app/`, `routes/`, `tests/`, `database/`) | **9 missing classes found** |
| Inertia page-existence scan (all `Inertia::render` targets) | **1 missing page:** `PrioritizationReview/Index` |
| Unrouted-controller scan | **2 unrouted:** `CommandCenterController` (abstract, correct), `DevelopmentManagerController` (defect) |

### Not executed, and why

| Check | Reason |
|---|---|
| Playwright e2e | Requires a live server + seeded DB + `PLAYWRIGHT_USER_*` credentials. Running would create records. Skipped per the audit's safety constraints. |
| Laravel Pint | Available but linting was not run; it would report style only and risks writing files. |
| PHPStan / Psalm | Not installed. |
| JS lint / typecheck | No ESLint or TypeScript configured. |
| Live Slack / OpenAI / Google calls | Prohibited. |
| Migrations / seeders against the MySQL DB | Prohibited. |

### Important workflows with no test coverage

- In-app capture → task conversion (there is nothing to test)
- Inbox triage (does not exist)
- Timezone correctness for task due dates and overdue calculation
- `/prioritization-review` page rendering
- Bulk-apply authorization
- AI settings authorization
- Mobile login rate limiting
- Scheduler/queue liveness

### Build and environment limitations

- Windows/Laragon; no cron. Scheduler liveness is unverifiable from the repository.
- `.env` has no OpenAI or Google credentials, so all AI and Calendar paths are cold.
- CI does not run on the active branch, so none of the 136 failures would have been caught automatically.

---

## 12. Why Miriam Is Not Yet Being Used

Each cause below is stated only where evidence supports it.

### 12.1 · Capture does not complete the lifecycle — and there is no Inbox to catch what falls out

This is the single largest cause. Miriam has two capture paths and both terminate before a usable record exists in a place you would look.

- The **Slack path works** and creates a real Task — with `section = 'inbox'` ([MiriamSlackThoughtCaptureService.php:313](app/Services/Miriam/MiriamSlackThoughtCaptureService.php:313)). A repository-wide search found **zero** consumers of that value. No controller filters on it, no page groups by it, no query mentions it. The task exists but appears only in the general `/tasks` list, undifferentiated from everything else.
- The **in-app path does not work**. `/agents/task-capture` classifies your text, detects the project, infers priority and a due label — and then offers a link to a **blank** `/tasks/create` form ([TaskCapture/Index.jsx:219](resources/js/Pages/Agents/TaskCapture/Index.jsx:219)). Nothing is carried across. You retype what the agent just parsed.
- The sidebar item labelled **"Inbox" is the notifications page** ([AuthenticatedLayout.jsx:14](resources/js/Layouts/AuthenticatedLayout.jsx:14)) — "task assigned", "status changed" — not captured thoughts.

Result: capture feels like shouting into a room with no door.

### 12.2 · Today cannot be trusted, because "today" is the wrong day for four hours

`config/app.php` sets `timezone => 'UTC'` ([config/app.php:68](config/app.php:68)). Every task-side date computation uses `now()`: `Task::scopeDueToday`, `scopeOverdue`, `scopeUpcoming` ([Task.php:198-211](app/Models/Task.php:198)), `DailyReviewService`, `TodayCommandCenterService`, `TaskController::index`. Meanwhile the reminder and medication engines hardcode `Asia/Dubai`.

Between midnight and 04:00 Dubai, Miriam's "today" is yesterday. Tasks due today are not shown as due today; yesterday's tasks are not yet overdue. **Your medication reminders fire correctly during exactly those hours** — so the system contradicts itself in front of you. There is no `timezone` column on `users`.

### 12.3 · Actions are spread across competing pages, and some of those pages are broken

- **Two home screens.** `/today` and `/dashboard` both call `collectTodayTasks()` + `selectTopFocusItems()`. Neither says which is authoritative.
- **Five duplicated concepts.** Waiting, Decision, Blocker, Risk and Approval each exist twice — as a `Task.task_type` and as a standalone model with its own page. Dashboard shows the task-typed ones; the sidebar links to the model-based ones. **Different data, same words.**
- **A nav item that opens nothing.** "More → Prioritization" resolves `Pages/PrioritizationReview/Index.jsx`, which does not exist. `resolvePageComponent` throws and you get a blank screen. This is also the *only* UI for the Now/This Week/This Month/Later/Waiting/Drop buckets you asked for — so the prioritization model exists in `PrioritizationReviewService::BUCKETS` with no way to use it.
- **A hidden core screen.** `/health` — medication, workouts, daily health log — has **no sidebar entry**. So does `/blockers`, while its four siblings are listed.

### 12.4 · A whole advertised subsystem cannot boot

`SlackWebhookController` and `DevelopmentManagerController` depend on nine classes that do not exist in the repository:

`MiriamPromptQueueService`, `MiriamRunnerMonitoringService`, `MiriamAppRegistryService`, `MiriamReleasePackageService`, `MiriamCodexOutputIngestionService`, `MiriamSprintPlanService`, `Slack\MiriamSlackConversationService`, `Models\MiriamReleasePackage`, `Models\MiriamSlackPendingConfirmation`.

Consequences, all runtime-verified:
- `POST /webhooks/slack/events` → **HTTP 500** on every request. Every Slack `done` / `move` / `note` / `block` / `skip` command is dead.
- `DevelopmentManagerController` has **no route**, so `ProductBrain/DevelopmentManager.jsx` is unreachable.
- Four `miriam:*` console commands are unregistered.
- 93 tests in `MiriamDevelopmentManagerTest` fail.

Yet Today devotes a full panel and a hero badge to "Codex", and the metrics row includes "Codex running". **The interface advertises a pipeline that cannot start.**

### 12.5 · Delivery depends on infrastructure that is unverified — and the health page says it is fine

Reminders need `php artisan schedule:run` every minute. Medication reminders additionally need a `queue:work` worker (`QUEUE_CONNECTION=database`). No cron or Task Scheduler artifact exists in the repository.

`SystemHealthService::checkQueue()` reports **"passed"** for any non-`sync` driver without checking whether a worker exists or whether jobs are backing up ([:148-160](app/Services/System/SystemHealthService.php:148)). `checkSchedulerCommands()` verifies commands are *registered*, not that the scheduler *runs* — and omits `miriam:send-medication-reminders` and `miriam:send-reminders`, the only two that matter ([:162-180](app/Services/System/SystemHealthService.php:162)).

So the failure mode is: reminders silently stop, and the page you would check to find out reports green.

### 12.6 · The reminder engine is mid-refactor and currently failing its own tests

The working tree contains an uncommitted +430-line rewrite of `MiriamReminderService` implementing escalation, max-pokes and deduplication. It is good design. It also currently fails **26 of its own tests**, including "multi line message creates multiple items" (creates 1 instead of 2), "no reminder or calendar event is created in the past" (creates 1 instead of 0), and six cases where an expected Slack call never fires.

You cannot build a daily habit on a reminder engine whose test suite is red.

### 12.7 · The system does not separate recommendations from completed actions — because nothing is ever completed

Every agent is `creates_actions_automatically: false` — correct and safe. But `AgentOutputReviewController::approve()` **only flips a status field**. No task is created, no decision recorded, no work scheduled. `sendToToday()` sets a timestamp so the item appears in Today's "Waiting on me", where the primary action is "Approve", which flips the status again.

The loop is closed and empty. Nothing you approve ever becomes work.

### 12.8 · Empty states describe the screen rather than the next move

Today's "No fires right now" suggests *"Review the Today by product cards and choose one high-leverage task"* — and those cards link to `tasks.index?search=Miriam/Friday`, `?search=Personal/Health`, `?search=Finance/Life admin`. The search runs `title LIKE %Miriam/Friday%`. **These return nothing.**

### 12.9 · The product does not know its own name

`APP_NAME=Friday`. The browser tab says "Friday". The Inertia fallback name is `'Friday'` ([app.jsx:8](resources/js/app.jsx:8)). The sidebar header says "Miriam" over the subtitle **"Friday workspace"** ([AuthenticatedLayout.jsx:135](resources/js/Layouts/AuthenticatedLayout.jsx:135)). Slack briefings are titled "Friday Daily Briefing". Notifications are `TaskFlowNotification` and link with "Open in Friday". Commands are `taskflow:*`. The repository directory is `taskflow` and the remote is `friday.git`.

This is minor mechanically and significant psychologically: the tool has not been finished into being one product.

### What is *not* a cause

To be fair to the work already done: **persistence is not the problem.** Tasks, reminders, medication, Bible progress, calendar mappings and audit trails all save correctly and are transaction-wrapped. Dedupe keys are unique and enforced at the database level. **Security-through-obscurity is not being relied on**; signature verification and encryption at rest are done properly. And **no feature fabricates data** — every Today panel reads live records. The problem is not that Miriam lies; it is that Miriam is honest about too many unfinished things at once, in the wrong order, on the wrong clock.

---

## 12A. Completion Scoring — weighting and calculation

### Daily-Use Readiness — **32 / 100**

Weighted by how much each step contributes to a dependable daily loop.

| Component | Weight | Score | Basis | Contribution |
|---|---|---|---|---|
| Capture | 25% | 40 | Slack path works end-to-end; in-app path dead-ends; ambiguous times silently guessed | 10.0 |
| Inbox / triage | 15% | 0 | Does not exist | 0.0 |
| Tasks | 20% | 60 | CRUD + completion solid; no reminder on create, no pagination, no buckets, no delete | 12.0 |
| Today | 20% | 40 | Real data, but wrong timezone 4 h/day, triple-listed items, dead links, forced navigation on completion | 8.0 |
| Reminders / follow-ups | 15% | 30 | Well-designed engine, 26 failing tests, no in-app view, unverified scheduler | 4.5 |
| Slack | 5% | 50 | One endpoint works, one returns 500 | 2.5 |
| **Total** | **100%** | | | **37.0 → 32** |

Adjusted down 5 points because the timezone defect and the missing Inbox are *cross-cutting* — they degrade components already scored individually, and the loop is only as reliable as its weakest link.

### Vision Completion — **34 / 100**

Weighted toward the pillars the vision emphasises.

| Domain | Weight | Score | Basis | Contribution |
|---|---|---|---|---|
| Capture & classification | 12% | 45 | Slack real; classification rule-based; AI cold | 5.4 |
| Task / project management | 12% | 70 | Genuinely complete for a single user | 8.4 |
| Today command center | 12% | 50 | Exists, real data, wrong shape and clock | 6.0 |
| Reminders & follow-ups | 10% | 55 | Strong design, unverified state | 5.5 |
| Slack conversation layer | 10% | 40 | Half the vocabulary is in a 500-ing controller | 4.0 |
| AI assistant / brain | 10% | 15 | Well-architected, entirely disabled; assistant is rule-based | 1.5 |
| Development & agent OS | 12% | 10 | 9 missing classes; unroutable; 93 failing tests | 1.2 |
| Operations Center / maps | 5% | 45 | 3 of 4 maps, read-only, localStorage | 2.3 |
| Business command center | 8% | 3 | No client, pipeline, invoice, revenue, renewal model anywhere | 0.2 |
| Personal command center | 5% | 70 | Medication + Bible + health genuinely strong | 3.5 |
| External integrations | 2% | 30 | Calendar complete but unconfigured; Gmail absent | 0.6 |
| Knowledge / mission control | 2% | 20 | Silos, no unified activity layer, task-only search | 0.4 |
| **Total** | **100%** | | | **39.0 → 34** |

Adjusted down 5 points because "Miriam runs the company" requires the business and development pillars, which score 3 and 10 respectively — a weighted average understates how much the *whole* is blocked by two near-zero pillars.

### Operational Reliability — **28 / 100**

| Dimension | Weight | Score | Basis | Contribution |
|---|---|---|---|---|
| Persistence correctness | 15% | 85 | Transactions, unique dedupe keys, encryption at rest | 12.8 |
| Automated test health | 20% | 25 | 136 / 505 failing (26.9%); CI never runs on `main` | 5.0 |
| Route / endpoint health | 15% | 40 | 1 route permanently 500s; 1 nav page missing; 1 controller unrouted | 6.0 |
| Time correctness | 15% | 10 | Three timezone regimes; wrong day 4 h/day; no per-user timezone | 1.5 |
| Scheduler / queue reliability | 15% | 15 | Unverified, no overlap guard, health check misreports "passed" | 2.3 |
| Permissions & isolation | 10% | 30 | One P0 IDOR, three P1 fail-open gates | 3.0 |
| Idempotency & dedupe | 5% | 75 | Excellent in medication and capture; unlocked read-then-write in reminders | 3.8 |
| Error visibility | 5% | 15 | No error monitoring; failures recorded to tables with no UI | 0.8 |
| **Total** | **100%** | | | **35.2 → 28** |

Adjusted down because reliability is multiplicative, not additive: correct persistence provides little value when the delivery mechanism above it is unverified and the clock beneath it is wrong.

**Credit policy applied throughout:** a feature scored above 50 only where its primary workflow was verified end-to-end by test or trace. A screen, model, route, migration, or menu entry alone scored ≤ 15. Anything requiring developer intervention (running a command, editing config, typing a URL) was excluded from Daily-Use Readiness entirely.

---

## 13. Minimum Usable Miriam

The smallest safe version that lets you start using Miriam **tomorrow**. Nothing here is speculative — every item repairs or exposes something that already exists.

### Keep visible (7 sidebar items, 2 groups)

**Command**
1. **Today** — reduced to three sections: *Do this now*, *Waiting on me*, *Overdue & blocked*. A capture bar at the top.
2. **Inbox** — new; lists `tasks.section = 'inbox'` plus reminders with `status = 'awaiting_confirmation'`, each with one-click Convert / Schedule / Archive.
3. **Tasks** — list view with the existing Upcoming / Overdue / Completed / All tabs, plus pagination.

**Life & Work**
4. **Reminders** — new; a read/act view over `miriam_reminders` so reminders are visible outside Slack.
5. **Health** — already built; just add it to the nav.
6. **Spiritual** — already built; move up from "More".
7. **Settings** — Integrations + System Health only.

### Hide or label "Planned"

| Item | Action | Why |
|---|---|---|
| Prioritization | **Remove from nav** | Page does not exist |
| Agent Orchestrator, Task Capture Agent, Agents | Move under "Labs (Planned)" | Rule-based generators; approval leads nowhere |
| Operations Center | Move under "Reference" | Documentation, not control |
| Dashboard | **Remove** | Duplicates Today |
| Assistant | Label "Rule-based · AI not configured" | Currently implies AI |
| Decisions / Risks / Approvals / Waiting / Blockers | Collapse into one **"Review"** page with tabs | Five near-identical pages |
| Notes, Templates, Custom Fields, Automations, Reports, Planner, Projects, Portfolios, Goals, Areas, Calendar, Workload, Task Review | Move to "More" | Real, but not part of the daily loop |
| Codex panel on Today | **Hide until the module boots** | Advertises a dead pipeline |

### Must be repaired before daily use

1. **Set the application timezone** (`config/app.php` → `Asia/Dubai`, or add a per-user timezone and resolve it centrally). Nothing else on Today is trustworthy until this is done.
2. **Build the Inbox** — one page, one query (`section = 'inbox'`), three buttons.
3. **Make capture convert** — pass the proposal into `/tasks/create` as prefill, or create the task directly with a confirmation.
4. **Remove or fix `POST /webhooks/slack/events`** — a permanently 500-ing public route.
5. **Remove the "Prioritization" nav item** (or build the page).
6. **Fix `TaskController::store()`** to call `syncAfterTaskSaved` so new tasks get reminders.
7. **Fix `TaskController::complete()`** to return `back()` so Today stops ejecting you.
8. **Fix P0-1** (bulk-apply IDOR) — or remove the `/prioritization-review/apply` route while the page is gone.
9. **Get the reminder test suite green** — 26 failures on the module that has to fire every day.
10. **Verify `schedule:run` and `queue:work` are actually running**, and make System Health tell the truth about both.

### Can stay manual initially

- Project/client registry (hardcoded alias map is fine for now)
- Prioritization buckets (use priority + due date)
- Weekly review (do it in the Inbox)
- Calendar sync (Slack + in-app reminders are enough)
- Cost tracking (nothing is spending yet)

### Must be connected

- Scheduler → reminders (the whole reminder value proposition)
- Queue worker → medication reminders
- Slack → capture (already connected; keep it)

### Can safely be postponed

Development/Agent OS, Codex control, business command center, Gmail, Mission Control, Command Map, backend layout persistence, real AI. **None of these is needed for a reliable daily loop**, and three of them are the reason the app currently looks unfinished.

---

## 14. Prioritized Remediation Backlog

### P0 — Prevents safe use, or risks data/security

**P0-1 · Fix the application timezone**
*Problem:* `config/app.php` sets `UTC` while reminders use `Asia/Dubai`. Every task date computation is wrong from 00:00–04:00 local.
*Evidence:* [config/app.php:68](config/app.php:68); [Task.php:198-211](app/Models/Task.php:198); `Asia/Dubai` hardcoded in 5 services.
*Consequence:* Today, overdue, due-today and the daily briefing are wrong for four hours every day.
*Correction:* Set `'timezone' => 'Asia/Dubai'`, or add `users.timezone` and route all task date logic through a single resolver. Audit every `now()` in date-comparison context.
*Dependencies:* none · *Effort:* **M**
*Acceptance:* At 01:00 Dubai, a task due that calendar date appears under "Due today" and a task due the previous date appears under "Overdue".
*Tests:* Feature tests with `CarbonImmutable::setTestNow` at 23:00, 00:30 and 04:30 Dubai asserting bucket membership.

**P0-2 · Remove or restore the broken Slack webhook route**
*Problem:* `POST /webhooks/slack/events` returns HTTP 500 on every request (9 missing classes).
*Evidence:* [routes/web.php:63](routes/web.php:63); runtime-verified in `DailyExecutionTest` / `AiBrainSlackTest`.
*Consequence:* All Slack `done/move/note/block/skip` commands dead; Slack retries hammer the endpoint; with `APP_DEBUG=true` a full stack trace is returned.
*Correction:* Decide explicitly — either delete the route and controller, or restore the nine classes. Do not leave it routed.
*Dependencies:* none · *Effort:* **S** (remove) / **XL** (restore)
*Acceptance:* No route in `route:list` resolves to a controller with an unresolvable dependency.
*Tests:* A boot test that instantiates every routed controller through the container.

**P0-3 · Fix the unscoped bulk task update (IDOR)**
*Problem:* `/prioritization-review/apply` mass-updates any task by id with no ownership check.
*Evidence:* [PrioritizationReviewController.php:33-70](app/Http/Controllers/PrioritizationReviewController.php:33); `PrioritizationReviewService::apply()`.
*Consequence:* Any authenticated user can archive or complete every task in the database.
*Correction:* Scope `task_ids` to accessible workspaces; `Gate::authorize('update', $task)` per task; write an `AuditLog` entry.
*Dependencies:* none · *Effort:* **S**
*Acceptance:* A second user's task id in `task_ids` is rejected (403) or silently excluded, and the response count reflects only permitted tasks.
*Tests:* Feature test with two users asserting the foreign task is unchanged.

**P0-4 · Build the Inbox**
*Problem:* Captures write `section = 'inbox'`; nothing reads it. The nav "Inbox" is the notifications page.
*Evidence:* [MiriamSlackThoughtCaptureService.php:313](app/Services/Miriam/MiriamSlackThoughtCaptureService.php:313) + zero consumers repository-wide.
*Consequence:* Every captured thought is invisible. The lifecycle has no second step.
*Correction:* New `/inbox` route + page listing `tasks` where `section = 'inbox'` and `status = 'todo'`, plus `miriam_reminders` with `status = 'awaiting_confirmation'`. Per row: Convert to task/reminder/note/decision, Schedule, Archive. Repoint the nav item.
*Dependencies:* none · *Effort:* **M**
*Acceptance:* A Slack capture appears in `/inbox` within one page load and can be cleared to a scheduled task in one click.
*Tests:* Feature test: capture → assert present in `/inbox` payload → convert → assert `section` cleared and due date set.

**P0-5 · Make capture convert to a record**
*Problem:* Task Capture proposals link to a blank form.
*Evidence:* [TaskCapture/Index.jsx:219](resources/js/Pages/Agents/TaskCapture/Index.jsx:219).
*Consequence:* Every classified proposal must be retyped by hand.
*Correction:* Add `POST /agents/task-capture/outputs/{output}/create-task` that builds the task from `generated_task_title`, `priority`, `due_label`, `detected_projects`, links it back to the `AgentOutput`, and redirects to the task. Keep it explicit — never automatic.
*Dependencies:* P0-4 (shared conversion service) · *Effort:* **M**
*Acceptance:* Run the agent on "Follow up with May on Friday about SayaraForce invoices" → one click → task exists with that title, Friday's date, and the SayaraForce project attached.
*Tests:* Feature test asserting the created task's fields match the proposal.

**P0-6 · Verify and monitor scheduler + queue**
*Problem:* Reminder delivery depends on `schedule:run` and `queue:work`; neither is verified, and System Health reports "passed" regardless.
*Evidence:* [routes/console.php:11-17](routes/console.php:11); [SystemHealthService.php:148-180](app/Services/System/SystemHealthService.php:148).
*Consequence:* Reminders can stop silently and indefinitely.
*Correction:* Write a heartbeat (cache key stamped by a scheduled command); have System Health fail when the heartbeat is stale or when `jobs`/`failed_jobs` back up; add `miriam:send-reminders` and `miriam:send-medication-reminders` to the required-command list; add `withoutOverlapping()` to both `everyMinute` entries.
*Dependencies:* none · *Effort:* **M**
*Acceptance:* Stopping the scheduler turns the System Health page red within 5 minutes.
*Tests:* Unit test on the heartbeat staleness check; feature test asserting a failed status when the key is absent.

**P0-7 · Restore the reminder test suite to green**
*Problem:* 26 `MiriamReminderTest` failures on the working tree, including "creates 1 instead of 2", "creates 1 instead of 0", and six missing Slack calls.
*Evidence:* Full test run.
*Consequence:* The engine that must fire every day is unverified.
*Correction:* Decide per failure whether the test or the refactor is correct; update both deliberately. Do not mass-update assertions to match current output.
*Dependencies:* none · *Effort:* **L**
*Acceptance:* `php artisan test --filter=MiriamReminderTest` passes with no assertion weakened without a written rationale.
*Tests:* The existing suite, corrected.

### P1 — Prevents dependable daily use

**P1-1 · Remove the "Prioritization" nav item (or build the page).** Missing `PrioritizationReview/Index.jsx` → blank screen. · *Effort:* **S** (remove) / **M** (build). *Acceptance:* every nav item renders a page. *Tests:* a test asserting every `Inertia::render` target resolves to an existing file.

**P1-2 · Add `/health` to navigation.** Medication is a core daily function with no menu entry. · *Effort:* **S**. *Acceptance:* reachable in one click from any page.

**P1-3 · Fix `TaskController::store()` to sync reminders.** New tasks with due dates get no reminder; edited tasks do. · [TaskController.php:142-161](app/Http/Controllers/TaskController.php:142) · *Effort:* **S**. *Acceptance:* creating a task with a due date produces a `miriam_reminders` row. *Tests:* feature test on create.

**P1-4 · Fix `TaskController::complete()` redirect.** Returns `redirect()->route('tasks.show')`, ejecting the user from Today. · [:279](app/Http/Controllers/TaskController.php:279) · *Effort:* **S**. *Acceptance:* completing from Today stays on Today with scroll preserved.

**P1-5 · De-duplicate Today.** One task can render in three panels; remove `LegacyTaskList`. · *Effort:* **M**. *Acceptance:* no task id appears twice on one render. *Tests:* assert unique ids across panels.

**P1-6 · Fix Today's product-card links.** `?search=Miriam/Friday` matches nothing. · [TodayCommandCenterService.php:345](app/Services/TodayCommandCenterService.php:345) · *Effort:* **S**. *Acceptance:* every card link returns ≥ the count it displays.

**P1-7 · Add authorization to `/settings/ai`.** Zero checks on a global API key. · *Effort:* **S**. *Acceptance:* a non-owner receives 403 on GET and PATCH. *Tests:* feature test per role.

**P1-8 · Scope Command Center dropdown options.** All areas/portfolios/projects/200 tasks leak to every user. · [CommandCenterController.php:57-62](app/Http/Controllers/CommandCenterController.php:57) · *Effort:* **S**. *Acceptance:* payload contains only accessible records. *Tests:* two-user feature test.

**P1-9 · Fail closed on the Slack channel gate.** · [SlackEventsController.php:215-224](app/Http/Controllers/SlackEventsController.php:215) · *Effort:* **S**. *Acceptance:* with no channel configured, events from unknown channels are ignored and logged.

**P1-10 · Authorize Slack medication actions.** No allowed-user check, no dose ownership check. · *Effort:* **S**. *Acceptance:* another Slack user's action on your dose is refused and audited.

**P1-11 · Move `env()` calls into config.** Two calls break under `config:cache`. · *Effort:* **S**. *Acceptance:* behaviour identical with and without cached config. *Tests:* feature test run with config cached.

**P1-12 · Paginate the task list.** Four unbounded queries with 7 eager loads, executed twice per view (a mount-time `useEffect` re-fetches). · [TaskController.php:34-59](app/Http/Controllers/TaskController.php:34); [Tasks/Index.jsx:18-23](resources/js/Pages/Tasks/Index.jsx:18) · *Effort:* **M**. *Acceptance:* payload bounded regardless of task count; one request per navigation.

**P1-13 · Build an in-app Reminders view.** Reminders exist only in Slack and the mobile API. · *Effort:* **M**. *Acceptance:* pending, snoozed and exhausted reminders are visible and actionable in the web app.

**P1-14 · Surface reminder and Slack delivery failures.** `slack_reminder_failed` events are recorded but never shown. · *Effort:* **M**. *Acceptance:* failed deliveries appear on System Health with a timestamp and reason.

**P1-15 · Make agent approval produce an outcome.** Approve currently only flips a status. · [AgentOutputReviewController.php](app/Http/Controllers/Agents/AgentOutputReviewController.php) · *Effort:* **M**. *Acceptance:* approving an output creates the corresponding record and links back to the output.

**P1-16 · Scope development jobs on Today by user.** · [TodayCommandCenterService.php:436-461](app/Services/TodayCommandCenterService.php:436) · *Effort:* **S**.

**P1-17 · Resolve ambiguous capture times deterministically.** With AI off, `hour < 8 → +12` silently guesses. · [MiriamSlackThoughtCaptureService.php:904](app/Services/Miriam/MiriamSlackThoughtCaptureService.php:904) · *Effort:* **M**. *Acceptance:* "remind me at 9" asks AM or PM instead of guessing. *Tests:* parser tests for 7, 9, 11 with and without meridiem.

### P2 — Required for the broader command-center workflow

**P2-1 · Collapse navigation to the 5-group model** (Command · Workspaces · Review · Capture · More). · *Effort:* **M**
**P2-2 · Resolve the five duplicated concepts** — pick `task_type` or standalone models for Waiting/Decision/Blocker/Risk/Approval and migrate. · *Effort:* **L**
**P2-3 · Retire `/dashboard`** in favour of Today. · *Effort:* **S**
**P2-4 · Rename Friday/TaskFlow → Miriam** across `APP_NAME`, `TaskFlowNotification`, `taskflow:*` commands, titles, Slack copy. · *Effort:* **M** (mechanical but wide)
**P2-5 · Cache the Bible summary on Today** — currently loads 90 days × ~13 chapters on every home-screen render. · *Effort:* **S**
**P2-6 · Add a `due_date` index on `tasks`** — heavily filtered, unindexed. · *Effort:* **S**
**P2-7 · Add rate limiting + token TTL to the mobile API.** · *Effort:* **S**
**P2-8 · Add error monitoring** (`withExceptions` is empty). · *Effort:* **S**
**P2-9 · Redact message text from Slack logs.** · *Effort:* **S**
**P2-10 · Universal search** across tasks, notes, decisions, reminders (currently tasks only). · *Effort:* **M**
**P2-11 · Configure Google Calendar** (code is complete). · *Effort:* **S**
**P2-12 · Configure OpenAI and verify model ids** before enabling. · *Effort:* **S**
**P2-13 · Add PII redaction before any AI call.** · *Effort:* **M**
**P2-14 · Retention policy for the 7 unbounded audit tables.** · *Effort:* **M**
**P2-15 · Point CI at `main`.** · *Effort:* **S**
**P2-16 · Split `SlackWebhookController` (1,500 lines) and `OperationsGraph.jsx` (1,820 lines).** · *Effort:* **L**
**P2-17 · Fix the Tasks header row** (`grid hidden` — headers never render). · *Effort:* **S**
**P2-18 · Fix `tomorrowDate()`** in Today (uses `toISOString()`, UTC). · *Effort:* **S**

### P3 — Valuable future enhancement

Business command center (clients, pipeline, renewals, invoices, revenue) · Gmail integration · Mission Control / unified activity layer with decision→task→delivery traceability · Command Map · Server-side graph layout persistence · Restore or rebuild the Development/Agent OS · Real coding-agent control with process supervision · Token and cost tracking · Scheduled generation of recurring occurrences · Task dependencies · Per-user timezone selection UI · Playwright suite in CI.

---

## 15. Recommended Implementation Order

Ordered by dependency and by how quickly each step makes the next day usable — deliberately **not** sidebar order.

**Stage 0 — Stop the bleeding (half a day)**
1. P0-2 remove/quarantine the 500-ing Slack webhook route
2. P1-1 remove the "Prioritization" nav item
3. P0-3 fix the bulk-apply IDOR (or drop the route with the page)
*Rationale: three deletions eliminate a permanent 500, a blank screen, and the only P0 security hole. Nothing depends on them.*

**Stage 1 — Make time correct (1 day)**
4. P0-1 set the application timezone
*Rationale: every subsequent judgement about Today, overdue and reminders is meaningless until the clock is right. Do this before touching Today.*

**Stage 2 — Close the capture loop (2–3 days)**
5. P0-4 build the Inbox
6. P0-5 make capture convert (shares the conversion service with the Inbox)
7. P1-3 sync reminders on task create
*Rationale: this is the loop. Capture → Inbox → Task → Reminder. After this stage Miriam is usable for the first time.*

**Stage 3 — Make delivery trustworthy (2 days)**
8. P0-6 scheduler + queue heartbeat and honest health checks
9. P0-7 restore the reminder test suite
10. P1-14 surface delivery failures
*Rationale: a loop you cannot trust to fire is not a habit. Heartbeat first so the test work is validated against real behaviour.*

**Stage 4 — Make Today the home screen (2 days)**
11. P1-4 fix the completion redirect
12. P1-5 de-duplicate panels
13. P1-6 fix product-card links
14. Hide the Codex panel until its module boots
15. Add the capture bar to Today
*Rationale: only worth doing after Stages 1–3, or you polish a screen showing the wrong day from an unreliable pipeline.*

**Stage 5 — Reveal what already works (half a day)**
16. P1-2 add Health to the nav
17. Move Spiritual up from "More"
18. P1-13 build the in-app Reminders view
*Rationale: cheapest capability gain in the whole plan — three finished modules the user cannot currently reach.*

**Stage 6 — Close the remaining security gaps (1 day)**
19. P1-7 AI settings authorization
20. P1-8 scope Command Center options
21. P1-9 fail closed on the Slack channel gate
22. P1-10 authorize medication actions
23. P1-11 move `env()` into config
*Rationale: none blocks single-user daily use, all block hosting it anywhere.*

**Stage 7 — Simplify the surface (3–4 days)**
24. P2-1 collapse navigation to 5 groups
25. P2-3 retire `/dashboard`
26. P2-2 resolve the five duplicated concepts
27. P1-15 make agent approval produce an outcome
*Rationale: do this once the daily loop is proven, so the simplification is informed by what you actually use.*

**Stage 8 — Performance and identity (2 days)**
28. P1-12 paginate tasks; P2-5 cache the Bible summary; P2-6 add the `due_date` index
29. P2-4 complete the Friday → Miriam rename
30. P2-15 point CI at `main`

**Stage 9 — Decide the Development/Agent OS**
31. Choose deliberately: restore the nine missing classes, or delete the module, its 12 models, 6 tables, tests and runner directory.
*Rationale: it is currently the largest source of both failing tests and misleading UI. Leaving it half-present is the worst option. Do not attempt this before Stage 3 — it will consume all available attention.*

---

## 16. Evidence Index

### Missing classes (9) — referenced but not present

| Class | Referenced by |
|---|---|
| `App\Services\MiriamPromptQueueService` | `DevelopmentManagerController.php`, `SlackWebhookController.php` |
| `App\Services\MiriamRunnerMonitoringService` | `DevelopmentManagerController.php`, `SlackWebhookController.php` |
| `App\Services\MiriamAppRegistryService` | `DevelopmentManagerController.php`, `SlackWebhookController.php` |
| `App\Services\MiriamReleasePackageService` | `DevelopmentManagerController.php`, `SlackWebhookController.php`, `MiriamDevelopmentManagerTest.php` |
| `App\Services\MiriamCodexOutputIngestionService` | `SlackWebhookController.php` |
| `App\Services\MiriamSprintPlanService` | `SlackWebhookController.php` |
| `App\Services\Slack\MiriamSlackConversationService` | `SlackWebhookController.php` |
| `App\Models\MiriamReleasePackage` | `DevelopmentManagerController.php`, `SlackWebhookController.php`, `MiriamDevelopmentManagerTest.php` |
| `App\Models\MiriamSlackPendingConfirmation` | `SlackWebhookController.php`, `MiriamDevelopmentManagerTest.php` |

### Missing frontend page (1)
`resources/js/Pages/PrioritizationReview/Index.jsx` — rendered by [PrioritizationReviewController.php:19](app/Http/Controllers/PrioritizationReviewController.php:19)

### Unrouted controller (1)
`app/Http/Controllers/DevelopmentManagerController.php` (329 lines) — referenced nowhere in `routes/`

### Orphan tables (model missing)
`miriam_release_packages`, `miriam_release_approvals`, `miriam_app_validation_profiles`

### Missing console commands (referenced by tests)
`miriam:apps:seed-defaults`, `miriam:sprint-plan`, `miriam:runner-monitor`, `miriam:dev:create-test-failure`

### Key routes

| Route | File:line | Note |
|---|---|---|
| `POST /webhooks/slack/events` | [routes/web.php:63](routes/web.php:63) | **HTTP 500 — broken** |
| `POST /slack/events` | [routes/web.php:64](routes/web.php:64) | Working capture endpoint |
| `POST /slack/medication/actions` | [routes/web.php:65](routes/web.php:65) | Working; no user authorization |
| `GET /today` | [routes/web.php:96](routes/web.php:96) | Home screen |
| `GET /tasks` | [routes/web.php:185](routes/web.php:185) | Unpaginated |
| `PATCH /prioritization-review/apply` | [routes/web.php:161](routes/web.php:161) | **P0 IDOR** |
| `GET /prioritization-review` | [routes/web.php:160](routes/web.php:160) | **Page missing** |
| `GET /health` | [routes/web.php:84](routes/web.php:84) | Not in navigation |
| `GET /operations-center` | [routes/web.php:71](routes/web.php:71) | Read-only, 3 GET routes only |
| `GET /settings/ai` | [routes/web.php:135](routes/web.php:135) | **No authorization** |

### Key controllers

`TaskController.php` (653) · `SlackWebhookController.php` (1,500 — broken) · `SlackEventsController.php` (261) · `TodayController.php` (122) · `SlackMedicationActionController.php` (154) · `DevelopmentManagerController.php` (329 — unrouted) · `CommandCenterController.php` (141 — abstract base for 5 pages) · `PrioritizationReviewController.php` (70) · `Settings/AiSettingsController.php` (130) · `Agents/AgentOrchestratorController.php` (183) · `Agents/AgentOutputReviewController.php` (54) · `Agents/TaskCaptureAgentController.php` (120) · `SpiritualController.php` (~290) · `Settings/IntegrationSettingsController.php` (144) · `Mobile/MiriamMobileController.php`

### Key services

`MiriamReminderService.php` (1,096 — 26 failing tests) · `MiriamSlackThoughtCaptureService.php` (956 — untracked) · `OperationsCenterGraphService.php` (752 — untracked) · `TodayCommandCenterService.php` (607) · `Health/MedicationReminderService.php` (~880 — strongest module) · `Miriam/MiriamBrainService.php` (448 — disabled) · `Calendar/CalendarSyncService.php` (~330) · `System/SystemHealthService.php` (~280 — misreports queue/scheduler) · `Agents/AgentOrchestratorService.php` (~420) · `Tasks/RecurringTaskService.php` (88) · `TaskReview/PrioritizationReviewService.php` · `Spiritual/SpiritualReadingSummaryService.php` · `Ai/AiAssistantService.php` (rule-based)

### Key models
`Task.php` (212) · `MiriamReminder.php` · `MedicationDoseLog.php` · `MiriamDevelopmentJob.php` · `AgentOutput.php` · `AgentRun.php` · `CalendarConnection.php` (encrypted casts) · `AiSetting.php` (encrypted key) · `MiriamMobileToken.php` (hashed) · `User.php` · `Workspace.php` — 77 models total

### Key tables
92 created across 58 migrations. Core: `tasks`, `miriam_reminders`, `miriam_reminder_events`, `medication_dose_logs`, `medication_reminder_events`, `agent_runs`, `agent_outputs`, `agent_run_logs`, `miriam_development_jobs`, `calendar_connections`, `calendar_event_mappings`, `bible_reading_plan_days`, `bible_reading_progress`, `audit_logs`, `task_activities`, `notifications`, `jobs`, `failed_jobs`.

### Jobs, commands, notifications
Jobs: `SendMedicationReminderJob` (the only one).
Commands (19): `miriam:send-reminders`, `miriam:send-medication-reminders`, `miriam:run-automations`, `miriam:sync-google-calendar`, `taskflow:send-daily-briefing`, `taskflow:send-evening-checkin`, `taskflow:send-task-reminders`, `miriam:health-check`, `taskflow:ai-ask`, `miriam:import-bible-json`, and others.
Notifications: `TaskFlowNotification` (the only one).

### Frontend
67 pages. Notable: [Today/Index.jsx](resources/js/Pages/Today/Index.jsx) (494) · [Tasks/Index.jsx](resources/js/Pages/Tasks/Index.jsx) (140) · [Agents/TaskCapture/Index.jsx](resources/js/Pages/Agents/TaskCapture/Index.jsx) (227) · [Components/OperationsCenter/OperationsGraph.jsx](resources/js/Components/OperationsCenter/OperationsGraph.jsx) (1,820) · [Layouts/AuthenticatedLayout.jsx](resources/js/Layouts/AuthenticatedLayout.jsx) (239) · [Components/AssistantPanel.jsx](resources/js/Components/AssistantPanel.jsx) · [app.jsx](resources/js/app.jsx)

### Tests
38 Feature + 1 Unit + 11 Playwright specs. Failing: `MiriamDevelopmentManagerTest`, `MiriamReminderTest`, `AiBrainSlackTest`, `DailyExecutionTest`.

### Configuration
[config/app.php:68](config/app.php:68) (timezone) · [config/services.php](config/services.php) (Slack/OpenAI/Google/AI) · [config/queue.php:16](config/queue.php:16) · [bootstrap/app.php](bootstrap/app.php) · [routes/console.php](routes/console.php) · [phpunit.xml](phpunit.xml) · [.github/workflows/tests.yml](.github/workflows/tests.yml) · `.env` / `.env.example` (keys only; no values were read into this report)

---

## 17. Unverified Areas and Open Questions

### Could not be verified safely

| Area | Why | What would verify it |
|---|---|---|
| Scheduler is actually running | No cron artifact in the repo; Windows Task Scheduler is outside it | `schedule:list` on the host + a heartbeat check |
| Queue worker is running | Same | `queue:work` process check + `jobs` table depth |
| Whether the MySQL DB has Bible content seeded | Running the seeder is prohibited | Read-only row count on `bible_verses` |
| Whether real Slack messages are delivered | Sending is prohibited | Slack API log inspection by the owner |
| Google Calendar token refresh behaviour | No credentials; calling the API is prohibited | Connect a test account and let a token expire |
| OpenAI model identifiers (`gpt-5.4-mini`, `gpt-5.4-nano`, `gpt-5.4`) | Calling paid APIs to test connectivity is prohibited | Check the provider's model list before enabling |
| Playwright e2e results | Requires a live server, seeded data and credentials; would create records | Run against a disposable environment |
| `MiriamReminderTest` status at HEAD (vs the working tree) | Would require stashing or checking out, which is prohibited | Run the suite in a separate clone at `29466579` |
| Real-world performance of `/tasks` and `/today` | No production data volume available | Query log against a realistic dataset |
| Whether `tools/miriam-runner` ever functioned | Running it is prohibited; its dependencies are missing | Git history archaeology |
| Mobile Expo app (`mobile/miriam-app`) | Outside this repository's build; not in scope | Separate audit |

### Open questions for the owner

1. **Were the nine missing classes deleted, or never committed?** Two stashes exist ("WIP before Agent OS import", "WIP Android generated files before Agent OS import"). If the classes are in a stash or an unpushed branch, the Development OS is recoverable cheaply. If not, restoring it is XL work. **This single answer changes the entire Stage-9 decision.**
2. **Is the `MiriamReminderService` refactor finished?** 430 uncommitted lines with 26 failing tests. Are the tests stale relative to an intentional redesign, or did the refactor regress? The behavioural failures (1 item instead of 2; 1 reminder instead of 0) suggest at least some are real.
3. **Is Miriam intended to stay single-user?** Several defects (P1-1, P1-2, P1-8, the `resolveUser()` fallback, the global AI settings row) are harmless single-user and serious multi-user. The correct fix differs.
4. **Which timezone is authoritative?** `Asia/Dubai` everywhere, or per-user? The former is a one-line change; the latter is a schema change plus an audit of every `now()`.
5. **Which of the five duplicated concepts should survive** — `Task.task_type` or the standalone models? Both are populated; neither is authoritative.
6. **Is Gmail actually wanted?** No code exists. It appears in the vision but nowhere in the repository.
7. **What is the intended relationship between the two agent systems** — the 8 rule-based orchestrator agents and the (broken) Codex/runner pipeline? They share no models and no concepts.
8. **Should the rename to Miriam happen now or after the daily loop works?** It touches `APP_NAME`, the notification class, 8 console commands, Slack copy, the repository directory and the git remote — mechanically simple, broad in blast radius.

---

*End of audit. No code was modified. The working tree is byte-identical to its state at the start, apart from `public/build`, which is gitignored and was regenerated by the production build check.*
