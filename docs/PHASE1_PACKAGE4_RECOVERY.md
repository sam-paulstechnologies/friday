# Phase 1 recovery tooling

Branch: codex/jarvis-p4-recovery. Base: 78527ef4a00ab8bd906f0514bbe86ff5a0b0aa87.

Files: tools/operations/{backup-friday.sh,verify-backup.sh,restore-friday-isolated.sh,recovery.py}, tools/testing/disposable_mysql.py, tests/operations/test_recovery.py, docs/PRODUCTION_RECOVERY_RUNBOOK.md and this record. All tooling is inert CLI code; the application has no new route, schedule or readiness claim.

Test command (synthetic only): set JARVIS_TEST_MYSQL_BIN to C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin and PATH to include Git usr/bin; python -m unittest discover -s tests/operations -v. Final results: 13 passed, 0 failed/errors/skipped. Real independent disposable MySQL instances and GPG encryption were used, not mocked SQL imports. Assertions include row counts, restored attachment/key material and Laravel encrypted fixture decryption.

python -m py_compile on the three Python files; bash -n on all wrappers; npm run build; git diff --check: passed. PHP application suite is not applicable to these CLI-only changes; the existing full-suite failures remain recorded in package 3.

Security review: explicit source and isolated target, loopback/datadir/empty-server/marker/confirmation proofs, encryption before publication, bounded sizes/processes, strict archive paths, no overwrite/drop, no plaintext credentials in CLI/logs and no implicit off-site upload. Windows live backup mode is refused; Linux owner-only checks require actual Linux acceptance before activation. APP_KEY is retained encrypted; the GPG key is kept outside the archive.

No production/staging script execution, backup upload, cron, secret provisioning, application readiness hook, deployment or migration. Live-source consistency, off-site storage, key custody, Linux/production-version restore and measured full application RPO/RTO remain unverified. Review the runbook before a separately authorized infrastructure package.
