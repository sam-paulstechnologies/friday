# Miriam / Friday UAT Checklist

Use this checklist after pulling the latest `main`, running normal migrations, building assets, and confirming `php artisan miriam:health-check` has no failed checks.

## Access And Roles

- Login as an owner, admin, member, and viewer test user.
- Confirm owner/admin can open Workspace Settings.
- Confirm member/viewer cannot manage workspace roles.
- Confirm viewer can view allowed project/task data but cannot write comments, complete tasks, or change roles.
- Confirm cross-workspace data is not visible in task, project, planner, reports, assistant, or settings screens.

## Dashboard

- Open Dashboard.
- Confirm Today's Focus shows top tasks, overdue count, reading item when present, and missed-yesterday warning when applicable.
- Confirm leadership, planning, command center, and area health sections load.
- Confirm Dashboard links to My Day, Planner, Reports, Goals, Portfolios, Automations, and Assistant work.

## My Day

- Confirm overdue, missed yesterday, due today, scheduled today, reading, completed today, upcoming, no-date, and blocked/waiting sections render clearly.
- Mark an active task complete and confirm it moves out of active work.
- Move a missed/overdue task to today and tomorrow.
- Snooze a task.
- Add a note from My Day and confirm it appears on task detail.

## Tasks

- Create a task in an accessible workspace/project.
- Edit title, status, priority, due date, labels, recurrence, and custom fields.
- Create a subtask and complete/reopen it.
- Add and remove labels.
- Archive and restore a task.
- Confirm completed tasks are grouped separately from active tasks.
- Confirm unauthorized users cannot view, edit, complete, archive, restore, or download attachments for inaccessible tasks.

## Projects

- Create a project.
- Edit project metadata, owner, dates, status, health, area, and portfolio.
- Add and remove project members from workspace users only.
- Confirm non-workspace users cannot be added.
- Open list, board, and timeline views.
- Archive and restore a project.
- Confirm project activity shows recent task/member/project changes.

## Inbox And Collaboration

- Assign a task and confirm the assignee receives a notification.
- Add a task comment and confirm relevant participants are notified.
- Mention a workspace user in a comment and confirm a mention notification.
- Complete a task and confirm useful completion notification behavior.
- Open Inbox, mark one notification read, then mark all read.
- Confirm users see only their own notifications.

## Planner

- Open Planner.
- Confirm Calendar, Week, Timeline, and Workload tabs load.
- Confirm active work appears above completed work in week view.
- Move planner tasks to today/tomorrow.
- Confirm Google Calendar external events appear when a mocked or configured connection exists.

## Goals, Portfolios, Reports

- Open Reports from sidebar.
- Open Goals and Portfolios from Reports.
- Create/edit a goal and key result.
- Link projects to goals where supported.
- Create/edit a portfolio and add/remove projects.
- Confirm report filters change metrics without leaking inaccessible data.
- Confirm workload reporting is scoped to accessible workspaces.

## Workspace Settings And Automations

- Open Workspace Settings as owner/admin.
- Add a member and change role.
- Confirm last owner cannot be removed or demoted.
- Open Automations.
- Confirm presets exist.
- Toggle a rule and confirm audit log entry.
- Run `php artisan miriam:run-automations` in a safe test workspace and confirm no duplicates are created.

## Google Calendar Foundation

- Open Settings > Integrations.
- Confirm disabled/unconfigured state is clear and safe.
- In a configured test environment, start connect flow.
- Confirm callback stores connection without exposing tokens.
- Run manual sync and confirm sync log/mapping.
- Confirm duplicate sync updates existing mapping instead of creating duplicates.

## AI Assistant Mock Mode

- Confirm AI is disabled by default unless configured.
- Enable/mock in a safe test environment.
- Ask "What should I focus on today?"
- Summarize an accessible project.
- Attempt to summarize an inaccessible project and confirm it is rejected.
- Create a task through the assistant confirmation flow as a permitted member.
- Confirm viewer cannot create tasks through assistant.
- Confirm no secrets or calendar tokens appear in assistant output.

## Mobile Layout Smoke Test

- Test Dashboard, My Day, Tasks, Projects, Planner, Reports, Inbox, Settings, and Assistant on a narrow viewport.
- Confirm sidebar opens/closes.
- Confirm key tables scroll horizontally instead of breaking layout.
- Confirm assistant panel is usable on mobile.

## System Health

- Open Settings > System Health as owner/admin.
- Confirm member/viewer cannot access it.
- Confirm no secrets, tokens, passwords, API keys, OAuth payloads, or `.env` values are displayed.
- Run `php artisan miriam:health-check` and resolve failed checks before production UAT.
