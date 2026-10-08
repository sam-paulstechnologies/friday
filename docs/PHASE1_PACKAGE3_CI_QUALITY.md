# Phase 1 CI gates and current failure inventory

Branch: codex/jarvis-p3-ci-quality; base 78527ef4a00ab8bd906f0514bbe86ff5a0b0aa87.

C1 is implemented: main/feature pushes, all PRs and merge groups trigger validation. Immutable action commits were resolved from upstream tag refs. Read-only contents permission, timeouts, independent PHP 8.2/8.3/8.4 results, frontend build, disposable MySQL 8.0 compatibility, seeded desktop/mobile E2E and sanitized JUnit/JSON artifacts are included. The new Tests workflow has no deployment job, provider credential or privileged pull_request_target event; pre-existing Laravel housekeeping workflows are separate.

The stable check is **JARVIS required checks**. It runs with always()/needs and passes only when exactly php, mysql, frontend and e2e each actually succeeded. Failure, cancellation, skipped/missing jobs and unknown results fail. Configure main protection to require this check, PR review and no bypass through a separately approved repository-policy change. Current authenticated API inspection: no main protection (404), rulesets empty; existing account has administrator permission. Live policy was not changed.

Test bootstrap forces synthetic APP_KEY, test-only configuration-cache path, SQLite :memory: (or explicitly selected CI loopback jarvis_ci MySQL), array mail/cache/session and no provider credentials. HTTP fakes reject stray requests. E2E uses a separate synthetic SQLite file, non-reused loopback server on 8765, disabled providers and synthetic owner account. Seeding refuses an existing DB; use a fresh worktree for a fresh database. E2E output lives below test-results/playwright so it cannot delete the seed DB or PHP results. Authentication cannot silently skip.

Latest main run verified with authenticated job/log APIs: https://github.com/sam-paulstechnologies/friday/actions/runs/37412716675, SHA 78527ef4a00ab8bd906f0514bbe86ff5a0b0aa87. PHP 8.3 failed at Execute tests; 8.2/8.4 cancelled by fail-fast. The PHP 8.3 log reports 575 tests, 1578 assertions, 87 errors and 152 failures. Missing Vite manifests are directly present in the log.

After frontend build, local PHP 8.3 verification consistently reports 575 tests, 3244 assertions: **460 passed, 87 errors, 28 assertion failures, 0 skipped**. C2 is not complete. The 115 failures are inventoried individually in PHASE1_CI_FAILURE_INVENTORY_2026-10-08.json. No tests were excluded or suppressed.

* 95 Development Manager failures: 57 missing MiriamPromptQueueService and 3 missing MiriamRunnerMonitoringService bindings; 19 absent-command errors; 10 failed endpoint/contract assertions; 2 missing routes, 2 ErrorExceptions, 1 model-not-found and 1 Error. These preserved development-domain contracts are blocked on a separately scoped restoration/contract decision. This package does not enable the manager or create fake services.
* 20 reminder failures: 18 assertions and 2 missing-model errors involving clarification, parsing/time defaults, multiline capture, tool-routing, Slack response and Calendar behavior. Existing contracts are kept as valid unresolved regression candidates; the audit/design baseline does not establish which may be obsolete. Review intended behavior before changing assertions. The UI E2E assertions proved obsolete by current controller/components were corrected, retaining equivalent explicit coverage.

Local commands/results:

* npm run build: passed.
* php vendor/bin/phpunit --log-junit test-results/p3-final.xml: 460/575 passed, 115 failed/error, exit nonzero.
* python tools/ci/summarize_results.py test-results/p3-final.xml test-results/published: correct counts; strips raw exception/body/log text and data-provider arguments.
* php tools/testing/seed-e2e.php; synthetic PLAYWRIGHT_USER_*; npx playwright test: **26 passed, 0 failed/skipped**, desktop Chromium and Pixel 5.
* python -m unittest discover -s tools/ci -p 'test_*.py': **5 passed**, including non-success/missing-job contract cases and credential-redaction/count preservation.
* Pint on changed PHP, Symfony YAML parse (5 job definitions), git diff --check: passed.

Earlier E2E run was interrupted after default Playwright output cleanup removed its synthetic seed and concurrent results; output isolation fixed that environment issue. Subsequent complete runs reported 20/26 and 24/26 passes while ambiguous selectors and state-dependent notifications were diagnosed; final 26/26 passed. Drawer screenshot showed visible usable links despite its zero-size outer wrapper; assertions now check links and closure. Assistant coverage verifies the HTTP response and rendered disabled-provider message. Notification coverage verifies unread state and mark-all-read using synthetic records.

Local PHP 8.2/8.4 and Linux MySQL 8.0 were not available as isolated local profiles; their remote jobs remain required. No all-green CI claim, main merge, production migration, provider call or production/staging change. C1 is reviewable; deployment remains blocked by C2, repository protection activation, integrated checks and recovery/security/provider release gates.

Contract reference: https://docs.github.com/en/pull-requests/how-tos/merge-and-close-pull-requests/troubleshooting-required-status-checks .

Additional authenticated GitHub verification after draft PR creation: Tests workflow ID 278415792 is **disabled_inactivity**. No new Tests runs/checks were created for the package branches. The successful pull_request_target runs are the pre-existing Laravel housekeeping workflow (uneditable / draft and uneditable / uneditable), not quality validation. Therefore remote PHP matrix, MySQL compatibility, E2E and aggregate behavior remain UNVERIFIED. Re-enable Tests through a separately reviewed repository activation, then require the aggregate on main. No workflow activation or live repository policy change was performed in this development-only phase.
