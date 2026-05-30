# Playwright UAT Automation

Playwright is configured as the browser-based UAT smoke framework for Miriam / Friday. The suite is intentionally safe: it does not delete, truncate, reseed, or reset data. Tests that create records prefix them with `[E2E]` and leave them in place for auditability.

## Setup

Install dependencies:

```bash
npm install
npx playwright install --with-deps
```

On Windows, `--with-deps` may report that OS dependency installation is not needed or not supported. In that case, run:

```bash
npx playwright install
```

## Environment

Set these values in your shell or local `.env`. Do not commit real credentials.

```bash
PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000
PLAYWRIGHT_START_SERVER=true
PLAYWRIGHT_USER_EMAIL=
PLAYWRIGHT_USER_PASSWORD=
```

Notes:

- `PLAYWRIGHT_BASE_URL` defaults to `http://127.0.0.1:8000`.
- `PLAYWRIGHT_START_SERVER=true` lets Playwright start `php artisan serve`.
- Set `PLAYWRIGHT_START_SERVER=false` when testing an already-running app URL.
- Authenticated specs skip safely when email/password are missing.
- Passwords are never printed by the helper.

## Commands

Run all E2E tests:

```bash
npm run test:e2e
```

Run headed:

```bash
npm run test:e2e:headed
```

Open Playwright UI:

```bash
npm run test:e2e:ui
```

Debug:

```bash
npm run test:e2e:debug
```

Run a single spec:

```bash
npx playwright test tests/e2e/dashboard.spec.js
```

Run only the mobile smoke spec:

```bash
npx playwright test tests/e2e/mobile.spec.js
```

## Current Coverage

- Login page and credential-backed login
- Dashboard smoke test
- My Day smoke test
- Safe task create and complete flow
- Inbox page smoke test
- Planner tabs
- Reports page
- Workspace settings access behavior
- Assistant disabled/mock-safe response
- Mobile Dashboard to My Day navigation

## Not Automated Yet

- Full role matrix across owner/admin/member/viewer in one browser run
- Google OAuth live callback
- Real Slack delivery
- Paid AI provider calls
- Destructive delete/archive cleanup flows
- Visual regression comparisons

## CI Readiness

A future GitHub Actions job can:

1. Check out the repo.
2. Install PHP and Node dependencies.
3. Prepare a disposable database.
4. Run `php artisan migrate`.
5. Run `npm run build`.
6. Run `npx playwright install --with-deps chromium`.
7. Set disposable `PLAYWRIGHT_USER_EMAIL` and `PLAYWRIGHT_USER_PASSWORD` from CI secrets or a test fixture.
8. Run `npm run test:e2e`.

Do not use production credentials or production data in CI browser tests.
