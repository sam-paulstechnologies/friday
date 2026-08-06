# Miriam — Asana-inspired UX redesign

**Date:** 2026-08-05
**Type:** Implementation. Nothing committed, pushed, deployed, or configured.
**Predecessors:** [Whole-app audit](MIRIAM_WHOLE_APP_AUDIT_2026-08-05.md) · [Phase 1 daily loop](MIRIAM_MINIMUM_USABLE_PHASE_1_2026-08-05.md) — neither overwritten.

---

## 1. Executive summary

Miriam had a working daily loop after Phase 1, but only through Slack, and it looked like eleven modules that happened to share a database. This phase rebuilt the interface as one product and closed the last gap in the loop: **the web can now capture.**

**What was redesigned:** the whole authenticated shell (collapsible sidebar, top bar, mobile drawer plus bottom tab bar, global Quick Add), a real design-token system, and the three screens the day runs on — Today, Inbox, My Tasks — plus a new Reminders page, a task detail drawer, and server-side pagination.

**Why the previous UX was difficult:** 27 flat sidebar items with no hierarchy; "Inbox" pointed at notifications; the only way to write something down was a 15-field form; Today read as an analytics dashboard; the task list shipped every task to the browser and its column headers never rendered; and parsed captures dead-ended on a blank form.

**New information architecture:** Command (Today · Inbox · My Tasks) → Workspaces → Review → Personal → More → Settings. Advanced and partial modules are collapsed under *More* with truthful badges.

**Does the core loop work?** Yes, and now without Slack. Verified end to end by automated test and by driving a real browser: capture → Inbox → review → one task → Today → complete → Completed → reopen, with the original wording preserved throughout.

**The most important thing found in this phase was not a UI problem.** `tasks.section` is not a workflow field: it holds **424 user-authored project-phase labels** ("Phase 4 — Sales Kit", "Launch Checklist", "Product UAT"). Phase 1's transition service wrote canonical workflow values into that same column, so the first "Move to Today" on any of those tasks would have silently destroyed the operator's label. The two concepts are now separate columns. Details in §11.

**What remains incomplete:** Board and Calendar views are not built (and are therefore not shown); Projects, Health, Bible and the advanced modules inherit the new shell and tokens but keep their existing page composition; 114 pre-existing test failures in two deferred modules are unchanged.

---

## 2. Baseline

| Item | Value |
|---|---|
| Branch | `main` |
| HEAD | `29466579b5cffa8dec9c4b129379c12567f2a06e` |
| Working tree at start | Dirty — 22 modified, 20 untracked (all Phase 1 work) |
| Stack | Laravel 12 · Inertia 2 · React 18 · Vite 6 · Tailwind 3 · Headless UI 2 |
| Full suite at start | 114 failed, 430 passed |
| Routes at start | 200 |

Every pre-existing modified and untracked path was preserved. Nothing was stashed, reset, cleaned or discarded; `git add` was never run; both pre-existing stashes are untouched.

### Claimed context, verified against the repository

| Claim | Verdict |
|---|---|
| Slack Thought Capture implemented | **True** — preserved; its suite still passes |
| Reminder Poker implemented (finite escalation) | **True** — preserved; surfaced in the new Reminders page |
| Today and canonical transitions exist | **True** — verified, then extended |
| Inbox exists but has no web feeder | **True** — only `MiriamSlackThoughtCaptureService` wrote `section='inbox'`. Fixed |
| Task Capture Agent links to a blank form | **True** — `TaskCapture/Index.jsx:219` linked to `tasks.create`. Fixed |
| `section` exposed as unrestricted text | **True** — and worse than described (§11). Fixed |
| Task-list header has `grid` and `hidden` | **True** — `Tasks/Index.jsx:67`. Fixed (row rewritten) |
| No pagination | **True** — 0 occurrences of `paginate`. Fixed |
| Mount-time refetch | **True** — `Tasks/Index.jsx:18`. Removed |

---

## 3. Design direction

**Asana-inspired principles used:** persistent collapsible left navigation; compact top bar; globally available quick-add; list-first task management with grouped sections and scannable rows; inline completion control; task detail in a drawer; progressive disclosure; restrained visual noise; clear empty states.

**Deliberately not copied:** no Asana logo, illustrations, icon set, colour values, wording or page compositions. The accent is an indigo-violet (`--m-brand-500: 90 92 214`) chosen to be clearly distinct from Asana's coral trade dress. The icon set is hand-authored single-path SVGs. Section vocabulary is Miriam's own (Today / This week / Later / Waiting / Delegated / Anytime).

**Design system.** Tokens live as CSS custom properties in `resources/css/app.css` and are exposed through `tailwind.config.js`, so a token changes in one place: surfaces (`canvas`, `surface`, `surface-sunken`, `line`, `line-strong`), ink (`ink`, `ink-muted`, `ink-subtle`, `ink-inverse`), one brand ramp, and four semantic families (`urgent`, `warn`, `good`, `info`). Geometry, sidebar widths and z-index layers are tokens too. Class recipes live in `Components/Kit/primitives.js`; components import them rather than hand-writing strings.

**Accessibility strategy:** one global `:focus-visible` ring; every icon-only control takes a mandatory `label`; status is always carried by text as well as colour ("Overdue · 2026-08-03", not just red); Headless UI supplies focus trapping and Escape handling for the drawer, modal and menus; a skip link; `prefers-reduced-motion` honoured globally; completion controls are `role="checkbox"` with `aria-checked`.

**Responsive strategy:** desktop gets a persistent sidebar that collapses to a 60px rail; tablet keeps the sidebar but drops to single-column panels; phones get a navigation drawer plus a five-slot bottom tab bar with Quick Add as a centre button, and the task-detail drawer becomes a full-height sheet. No core workflow scrolls horizontally at any width.

---

## 4. Navigation before and after

| Before (27 flat items, 3 groups) | After |
|---|---|
| **Work →** Today | **Command →** Today |
| **Work →** "Inbox" (actually Notifications) | **Command →** Inbox (real captures) · Notifications moved to *More* under its own name |
| **Work →** Tasks | **Command →** My Tasks |
| **Work →** Projects | **Workspaces →** Projects |
| — | **Workspaces →** Products (Portfolios) |
| — (Reminders had no page at all) | **Review →** Reminders |
| **More →** Waiting For, Decisions, Risks, Approvals | **Review →** Waiting for, Approvals, Decisions, Blockers |
| *(Blockers was in no menu)* | **Review →** Blockers |
| *(Health was in no menu)* | **Personal →** Health |
| **More →** Spiritual | **Personal →** Bible reading |
| **Work →** Planner | **Personal →** Planner |
| **Work →** Operations Center | **More →** Operations map · `Preview` |
| **Work →** Agents, Agent Orchestrator, Task Capture Agent (3 peers) | **More →** Agents · `Preview` (orchestrator and capture agent reachable from it) |
| **Work →** Assistant | **More →** Assistant · `Manual` |
| **Work →** Dashboard | **More →** Legacy dashboard · `Preview` |
| **Work →** Reports | **More →** Reports |
| **More →** Prioritization (page did not exist) | **Removed in Phase 1** |
| **More →** Task Review | Folded into My Tasks views |
| **Settings →** AI Brain | **Settings →** AI brain · `Not connected` |
| Hardcoded client-name "Workstreams" shortcuts | **Removed** — personal data in source |

Navigation is filtered through `route().has()` at render time, so an unregistered route can never appear. Badges are real counts (`inbox.open_count`, `notifications.unread_count`).

---

## 5. Core workflow

```
Quick Capture (web or Slack)
      ↓  one Task, workflow_state = inbox, original wording verbatim
   Inbox  ──  list + review pane, no retyping
      ↓  convert (idempotent) or move straight to a bucket
    Task  ──  workflow_state = today | this_week | later | waiting | delegated | tasks
      ↓
   Today  ──  priority work, deduplicated, complete in place
      ↓  complete
Completed ──  My Tasks → Completed view
      ↓  reopen
  Active  ──  never returns to Inbox or Dismissed
```

Both entry points write the same record through the same services. The capture text is stored on `source_metadata.original_text` **and** the task description, and survives every transition (`forceFill` of explicit fields only).

---

## 6. Quick Capture architecture

**Web** — `POST /capture` → `WebCaptureService::capture()`:

- The operator's exact string is persisted before anything is interpreted. Classification runs **outside** the write, so a parser exception cannot roll back the thought.
- On classification failure the capture is still saved and flagged `needs_review`; the Inbox shows it as *Needs clarification*. No false success is reported.
- Idempotency: `source_dedupe_key = web:sha1(user|text|client_token)`. The form mints a token per submission, so a double click, a replayed POST or a refresh resolve to the same capture. Without a token it falls back to a per-minute bucket.
- Only valid parsed values are used; a project attaches only when the parser resolved a real record the operator can reach.
- The input clears **only after** the server confirms persistence. Failure keeps the text on screen with an error.
- Two explicit choices: **Capture** (→ Inbox) and **Add to Today** (→ `workflow_state = today` through the canonical transition service). Today is never inferred from urgent-sounding wording — asserted by test.
- No reminder is created by a web capture.

**Slack** — unchanged. `MiriamSlackThoughtCaptureService` still creates an `awaiting_confirmation` reminder for dated captures and a task for undated ones; the only change is that the task's bucket is written to `workflow_state` instead of `section`.

**Deduplication summary:** Slack event id (per endpoint, unique index) · Slack capture `source_dedupe_key` · web client token · conversion short-circuits when the capture already has a task.

**Conversion linkage:** `tasks.source_metadata` carries `original_text`, `capture_reminder_id`, `converted_at`, `converted_by_user_id`, `converted_via`; `miriam_reminders.task_id` points back.

---

## 7. Task workflow

**Canonical buckets** (`Task::WORKFLOW_STATES`, stored in `tasks.workflow_state`): `inbox`, `today`, `this_week`, `this_month`, `later`, `waiting`, `delegated`, `tasks`, `dismissed`. `Task::WORKFLOW_LABELS` supplies the user-facing wording — the interface never shows a raw value. `ASSIGNABLE_WORKFLOW_STATES` excludes `inbox` and `dismissed`, which are only reachable through capture and dismissal.

**Validation:** `workflow_state` is `Rule::in(Task::ASSIGNABLE_WORKFLOW_STATES)` on the task form; arbitrary strings are rejected (tested). The form control is a `<select>`. `section` remains free text because it is free text by nature, but the input is now a datalist combobox of existing labels rather than a bare box.

**Transitions** go through `TaskTransitionService` only. Rejected: reopening something not closed; re-bucketing completed or archived work without reopening; completing an archived task. Every change is one transaction, centrally authorized, and writes a `TaskActivity` plus an `AuditLog` under the pre-existing vocabulary.

**Task detail** opens as a drawer from Today, My Tasks, Inbox and search, loaded from `GET /tasks/{task}/panel` — a policy-guarded JSON endpoint, so the drawer cannot leak a record the full page would refuse. Primary facts first; subtasks, comments, activity and capture provenance behind disclosures. Provenance renders as sentences, never raw JSON.

---

## 8. UI components

**New — `resources/js/Components/Kit/`:** `Icon` (28 hand-authored paths), `Button`, `LinkButton`, `IconButton`, `Badge`, `PreviewBadge`, `ConnectionStatus`, `Panel`, `PanelHeader`, `PageHeader`, `Breadcrumbs`, `ViewTabs`, `EmptyState`, `ErrorState`, `Skeleton`, `LoadingState`, `Drawer`, `Modal`, `ConfirmationDialog`, `OverflowMenu`, `Field`, `TextField`, `TextArea`, `SelectField`, `SearchInput`, `FilterBar`, `Alert`, plus `primitives.js` (`cx`, `buttonClass`, `fieldClass`, `panelClass`, `badgeTones`).

**New — `Components/Shell/`:** `Sidebar`, `SidebarGroup`, `SidebarItem`, `TopBar`, `QuickCapture`, `navigation.js`.
**New — `Components/Tasks/`:** `TaskRow`, `TaskStatusControl`, `TaskSection`, `TaskList`, `TaskDetailDrawer`, `useTaskCompletion`.
**New — `Layouts/AppShell.jsx`** with the mobile tab bar and Quick Add modal.

**Significantly changed:** `Layouts/AuthenticatedLayout.jsx` is now a thin wrapper over `AppShell`, which is why all ~60 pages inherit the new shell. `Components/Ui.jsx` (legacy, imported by ~40 pages) was retuned onto the design tokens so those pages share the same surfaces, ink, focus ring and semantic colours.

---

## 9. Pages redesigned

| Page | Previous problem | New design | Functional | Responsive |
|---|---|---|---|---|
| **Today** | 7 metric cards + 8 panels; same task in 3 panels; product cards linking to searches that matched nothing; Codex panel implying a live pipeline | Greeting + operational date, Quick Capture, Inbox nudge, deduplicated Priority work, Follow-up, Reminders, Medication, Bible; Codex reduced to one honest line | Complete | Verified 390 / 768 / 1280 / 1440 |
| **Inbox** | Existed but had no web feeder; card list only | Split pane: capture list beside a review pane showing original wording, what Miriam read, uncertainty warning, and fast actions | Complete | Stacks below `lg`; no overflow |
| **Inbox → Review** | — | Fully pre-filled form; original wording shown above and never editable | Complete | Single column ≤ `sm` |
| **My Tasks** | Unbounded queries, refetch on every keystroke, invisible column headers, no pagination | 10 server-paginated views as tabs, filters in the URL, scannable rows, drawer, pagination | Complete | Row metadata wraps; no table on mobile |
| **Reminders** | No web page at all | New. Grouped Due / Needs attention / Snoozed / Scheduled / Finished, with a finite-poker progress indicator and truthful Slack delivery status | Read-only by design | Rows stack below `lg` |
| **Task Capture Agent** | "Review as task" → blank form | "Send to Inbox to review" → shared capture pipeline | Complete | Inherits shell |
| **Task form** | `section` free text driving behaviour | Canonical Bucket `<select>` + section datalist, both explained | Complete | Existing grid |
| **All other pages** (~60) | Inconsistent shells | Inherit AppShell, navigation, Quick Add, flash handling and tokens | Unchanged behaviour | Shell-level only |

---

## 10. Routes

**Added (4)**

| Method | URI | Name |
|---|---|---|
| POST | `/capture` | `capture.store` |
| GET | `/reminders` | `reminders.index` |
| GET | `/tasks/{task}/panel` | `tasks.panel` |
| POST | `/agents/task-capture/outputs/{output}/capture` | `agents.task-capture.capture` |

**Changed:** `GET /tasks` now takes `view` and `workflow_state` and returns a paginator.
**Redirected:** none required — no user-facing path was renamed.
**Hidden from primary navigation:** Operations Center, Assistant, Agents, Agent Orchestrator, Task Capture Agent, Dashboard, AI Brain — all still routed and reachable under *More*/*Settings* with truthful badges.
**Removed:** none this phase (`prioritization-review.*` was removed in Phase 1).

Route total: **200 → 204.**

---

## 11. Data and schema

### The `section` collision — the significant finding

A read-only query against the live database showed `tasks.section` holds **29 distinct human-authored labels across 424 of 435 tasks**:

```
'Phase 0 — Tracker Cleanup & Scope Reset' => 3     'Launch Checklist'  => 78
'Phase 1 — Product Demo Safety'           => 34    'Product UAT'       => 20
'Phase 6 — Growth Campaigns'              => 48    'Legal & Operations'=> 20
… 23 more                                          NULL                => 11
```

Phase 1 introduced canonical workflow values into that same column. **Zero rows collided at the time of inspection**, but the first `Move to Today` on any of those 424 tasks would have overwritten the operator's phase label with the string `today`.

**Resolution:** the workflow bucket moved to its own column.

- Migration `2026_08_05_120000_add_workflow_state_to_tasks` adds `workflow_state` (string 32, nullable, indexed) and moves across **only exact matches** of the nine canonical strings, nulling `section` for those rows alone. Human labels are never matched loosely or guessed at. `down()` reverses it and only where it cannot clobber a label written since.
- `section` keeps its meaning: a grouping label inside a project, offered as a datalist of existing values.

**Invalid historical section values found: none** — all 424 are legitimate labels, and none collides with a canonical workflow value.

**Migration execution status:** created and executed **only** against the in-memory SQLite test database and a throwaway SQLite preview database in the scratch directory. **It has not been run against the MySQL database.** Run `php artisan migrate` when you are ready; it is additive and reversible.

No column was dropped or altered. No data was modified.

---

## 12. Tests and validation

### Added — `tests/Feature/MiriamRedesignTest.php` (22 tests, 301 assertions, all passing)

Quick Capture: creates one Inbox item with byte-exact original text · source recorded as `web_quick_capture` · triple submission creates one row · classifier failure keeps the capture and flags it *Needs clarification* · Add to Today is explicit · urgent wording alone does not reach Today · empty text rejected.

Inbox: web and Slack captures appear in one list with correct sources · an untriaged capture never appears in any My Tasks view · another user cannot view, convert or dismiss a capture.

Full web loop without Slack: capture → Inbox shows the original wording → correct the title → one task → Today → complete (returns to Today) → gone from active Today → Completed view → reopen to a valid active state → provenance intact.

Task Capture Agent: no action in the page points at `tasks.create` (asserted against the source) · a proposal converts through the shared pipeline · repeat conversion is idempotent · another user gets 403.

Canonical workflow: arbitrary `workflow_state` rejected · canonical accepted · a free-text section label survives a workflow transition untouched.

Authorization: the detail-panel endpoint enforces the policy · search and pagination never expose another user's tasks.

Navigation and performance: all 27 sidebar destinations resolve · Inbox and Notifications are separate components · the task list is paginated (60 rows → `per_page` 50, `total` 60) · the Reminders page reports finite-poker state and truthful Slack configuration.

### Changed tests — deliberate contract changes

- `TaskTest` (3 tests) — My Tasks returns one paginated view (`tasks.data`, `viewCounts`) instead of four eager groups (`taskGroups`, `taskCounts`). Assertions were rewritten to request each view; **no assertion was weakened**, and an `archived` view was added so archived work stays reachable.
- `MiriamDailyLoopTest`, `MiriamSlackThoughtCaptureTest` — the capture bucket moved from `section` to `workflow_state`. The Slack test now additionally asserts `section` is null, proving the two concepts no longer share a column.

### Results

| Check | Result |
|---|---|
| `MiriamRedesignTest` | **22 passed** (301 assertions) |
| `MiriamDailyLoopTest` | **39 passed** |
| `TaskTest` | **passed** |
| `MiriamSlackThoughtCaptureTest` (Slack capture preserved) | **passed** |
| `MedicationReminderTest` (safety preserved) | **passed** |
| `DailyExecutionTest`, `AiBrainSlackTest`, `OperationsCenterTest`, `SpiritualAndNotesTest` | **passed** |
| **Full suite** | **114 failed, 452 passed** (3,202 assertions, 88.65 s) — identical failure set to the 114 at start |
| Production build | **pass** — 9.95 s, no errors |
| Laravel Pint | **pass** after formatting |
| ESLint / TypeScript | not configured in this project |

### Browser validation (isolated environment)

An `artisan serve` instance was run against a **throwaway SQLite database in the scratch directory**, seeded with obviously-fake demo records. The real MySQL database was never served, migrated or written to, and no real data appeared on screen.

| Check | Result |
|---|---|
| Today, Inbox, My Tasks render with real data | Pass |
| Console errors | **None** on any page visited |
| Quick Capture end to end in the browser | Pass — success alert shown, input cleared only after persistence, Inbox badge went 2 → 3 without a page reload |
| Task rows | Accessible completion checkbox, named title button, named overflow menu |
| Mobile 390px | **0px** horizontal overflow; sidebar hidden; bottom tab bar with 52px targets |
| Inbox at 390px | 0px overflow; split pane stacks |
| Tablet 768px | 0px overflow |
| Desktop 1280 / 1440px | Sidebar, tabs, filters, rows all correct |
| Accessible names | Verified by DOM inspection (`innerText` / `aria-label`) for sidebar links and Quick Add — present and correct |

**Screenshots could not be captured** — the browser pane was not displayed, so no frames were composited. Visual verification is therefore by accessibility tree, rendered page text, DOM inspection and computed layout measurement, **not** by pixel screenshot. This distinction is deliberate: colour rendering, spacing rhythm and typographic balance are **unverified**.

One real bug was found by looking rather than testing: the Inbox review pane showed a "Resulting task" block for captures that had not been converted. Fixed — a task-sourced capture only reports a resulting task once its state is `converted`.

---

## 13. Remaining failures

All 114 are pre-existing and unchanged in count and cause.

**A · `MiriamDevelopmentManagerTest` — 95 · unrelated module, deferred.** 57 × missing `MiriamPromptQueueService`, 19 × unregistered `miriam:*` commands, 3 × missing `MiriamRunnerMonitoringService`, 8 × Development Manager Slack commands answered as unavailable, 3 × absent `/api/miriam/runner/*` routes, 2 × undefined `product-brain.*` routes, 2 × gitignored runner config file, 1 × missing `MiriamSlackPendingConfirmation`.

**B · `MiriamReminderTest` — 19 · obsolete expectations superseded by the working tree's own capture refactor.** They assert the old multi-item `captureSmartFromSlack` pipeline (a "Captured N items" summary, immediately-`pending` reminders, clarification records, calendar events at capture time) that `MiriamSlackThoughtCaptureService` replaced before this work began. None is on the daily-loop path, which is covered by `MiriamDailyLoopTest` and `MiriamRedesignTest`.

**Introduced regressions: none.** Verified by comparing the failing class/cause breakdown before and after.

---

## 14. Deferred work

Board and Calendar views (deliberately not shown rather than shown as decoration) · recurrence only regenerates on completion · the five duplicated concepts (Waiting/Decision/Blocker/Risk/Approval each exist as both a `task_type` and a standalone model) · acknowledging and snoozing reminders from the web · scheduler and queue heartbeat (audit P0-6 — still the largest risk to reminders firing at all) · `/settings/ai` and Command Center authorization (P1-7, P1-8) · Development/Agent OS restoration · Operations Center depth · task delete/bulk actions/sorting · Projects page composition · the Friday → Miriam rename · command palette (the shortcut scaffolding exists: `c` opens Quick Add, `/` focuses search).

---

## 15. Screenshots

None. The browser pane was not displayed during validation, so no frames could be composited. Section 12 states exactly what was and was not verified.

---

## 16. Rollback

No user data is at risk: the redesign is presentation plus one additive column.

- **UI only:** `git checkout -- resources/js resources/css tailwind.config.js` and delete `resources/js/Components/{Kit,Shell,Tasks}`, `resources/js/Layouts/AppShell.jsx`, `resources/js/Pages/Reminders`. The backend keeps working; `AuthenticatedLayout` returns to its previous form.
- **Schema:** `php artisan migrate:rollback --step=1` restores the canonical values into `section` and drops `workflow_state`, but **only where `section` is null**, so a label written since is never clobbered. Reverting the schema without also reverting `Task.php`, `TaskTransitionService`, `InboxService` and `WebCaptureService` will break the workflow — revert them together.
- **Quick Capture:** delete `CaptureController`, `WebCaptureService` and the `capture.store` route. Existing captures remain valid tasks.
- Nothing was committed, so every change is `git checkout --` away.

---

*Redesign phase only. No commit, push, deployment, `.env` change, destructive database command, real Slack/email/webhook delivery, real AI-agent execution, or paid API call occurred.*
