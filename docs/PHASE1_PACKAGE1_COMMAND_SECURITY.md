# Phase 1 Command Center security

Branch: codex/jarvis-p1-command-security. Base: 78527ef4a00ab8bd906f0514bbe86ff5a0b0aa87.

All five resources use a shared access service. Options and eager-loaded links enforce workspace membership and existing view policies; writes require ownership plus writable workspace access. Foreign references and mixed hierarchies are rejected. Legacy inaccessible links are redacted and can be removed through an authorized repair. Viewer mutation, arbitrary terminal statuses and foreign-record enumeration are blocked. Areas remain shared read-only taxonomy; no schema change.

Verification in this worktree used synthetic .env.testing configuration, SQLite :memory:, array mail/session/cache, sync queue and no real provider credentials. PHP tests prohibit stray HTTP calls in the new regression class. npm run build passed. Pint passed on changed PHP files. The final selected suite passed 104 tests / 856 assertions, zero failures/errors/skips. CommandCenterIsolationTest contributes 43 new tests; the other suites are WorkspaceAccessControlTest, LifeOsCommandCenterTest and TaskTest.

Initial runs failed only because a fresh worktree had no Vite manifest (15/41, then 39/102 before build completion). After the independent build completed, those failures disappeared. They were not suppressed. JUnit/local logs are ignored under test-results/.

Commands: npm run build; php vendor/bin/pint <changed PHP paths>; php vendor/bin/phpunit --filter 'CommandCenterIsolationTest|WorkspaceAccessControlTest|LifeOsCommandCenterTest|TaskTest' --log-junit test-results/p1-final.xml.

Security review: no permission expansion, migration, live credential change, external message or production/staging operation. Policies outside the five Command Center resources are unchanged. Public enrollment/email-verification policy and wider private-project permission semantics remain separate decisions. Existing core task/Slack/Agent OS work is preserved.

Deployment readiness: isolated development verification passed; requires review, integration CI and explicit production approval. No deployment was performed.
