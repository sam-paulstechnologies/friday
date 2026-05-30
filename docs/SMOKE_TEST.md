# Miriam / Friday Smoke Test

Run this after deployment or before serious UAT.

1. Login with an owner/admin test user.
2. Open Dashboard and confirm Today's Focus loads.
3. Open My Day and confirm active and completed tasks are separated.
4. Create a task in an accessible workspace/project.
5. Complete the task and confirm it leaves the active list.
6. Add a comment to the task.
7. Open Inbox and confirm a relevant notification appears when expected.
8. Open Planner and confirm Calendar, Week, Timeline, and Workload tabs render.
9. Open Reports and confirm metrics render.
10. Open Assistant and ask: "What should I focus on today?"
11. Open Settings > System Health as owner/admin.
12. Confirm a member/viewer cannot open Settings > System Health.
13. Confirm no secrets, API keys, OAuth tokens, refresh tokens, passwords, or `.env` values are visible anywhere.
