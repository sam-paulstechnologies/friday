# Friday recovery runbook — prepared, not activated

Date: 8 October 2026, Asia/Dubai. Phase 1 development authorization covers disposable fixtures only. No production backup was run, uploaded or scheduled.

## Release gate and prerequisites

Production recovery remains **UNVERIFIED**. A local integrity/import result cannot satisfy the deployment recovery gate. The owner must approve RPO/RTO and retention; proposed initial objectives are RPO <=24 hours, RTO <=4 hours, 7 daily / 4 weekly / 3 monthly copies plus a pre-release snapshot. Choose binlog/PITR separately if a 24-hour loss window is unacceptable.

Before separately authorized activation, provision a controlled Linux backup host, Python >=3.10, GPG/gpgconf, compatible MySQL clients, private staging/off-site storage and an independent isolated restore host. Verify Linux owner-only file/directory guards and a restore on the production-compatible MySQL version. Windows tests exercise Git-for-Windows GPG and disposable MySQL 8.4.3; they do not prove Linux ACLs or production MySQL 8.0 compatibility.

Create a narrowly scoped snapshot account for required SELECT/SHOW VIEW/TRIGGER/EVENT/SHOW_ROUTINE permissions as appropriate to the exact MySQL version and dump options; validate the grants on a fixture before live use. Store its client file and GPG passphrase file outside the application, with owner-only permissions (0600); use a private output parent (0700). Generate high-entropy encryption material through an approved secret-custody process. Keep a separately protected recovery copy with access audited; never put plaintext keys, client files, dumps or backups in GitHub/CI artifacts.

Retain .env APP_KEY and any previous encryption keys/secret-vault recovery exports. The encrypted snapshot includes application code, lock files, uploads and .env. It excludes Git metadata, dependencies, build/cache/session/log directories and backups. Restore dependencies/build from the reviewed lock files and release receipt later. Vendor dependencies, external object storage, system packages, Nginx/systemd/worker configuration, DNS and infrastructure are outside this bundle: inventory and retain protected recovery copies separately.

Verify the operator-supplied release SHA against the active immutable release/Git HEAD. Capture a release receipt, environment, schema version and file-store identity without secret values. Quiesce application writes or use an approved coordinated filesystem snapshot: MySQL single-transaction protects InnoDB consistency, while a file-by-file upload copy cannot prove database/attachment consistency under concurrent writes. Confirm all relevant tables are transactional.

## Prepared commands

Commands below are templates for a future approved operator run, not commands executed in Phase 1. Replace paths only after independently verifying resolved targets. Scripts have no default credentials, source database or application directory. They do not upload, install cron, enable workers or deploy.

Fixture backup (default mode accepts only loopback jarvis_fixture_* source databases):

```sh
tools/operations/backup-friday.sh +  --app-dir /srv/fixtures/friday +  --database jarvis_fixture_source +  --client-file /srv/private/fixture-client.cnf +  --key-file /srv/private/recovery-passphrase +  --output-dir /srv/private/backups/fixture-2026-10-08 +  --release-sha <verified-40-character-sha>
```

A live source additionally requires **--allow-live-source** and an explicit separate production authorization. That mode is refused on Windows. Verify actual host/database/release/source snapshot before any such run. The tool refuses existing output, missing app key/lock files, source symlinks, a key inside the application (including hard links), unsafe names and excessive resources. It streams a consistent dump, encrypts with GPG AES256 integrity protection, validates file hashes and publishes a completion manifest only after success. Database/GPG failure returns nonzero without raw diagnostics or secrets. Limits: 16 GiB archive, 100,000 files; larger installations need a reviewed streaming/chunking design.

```sh
tools/operations/verify-backup.sh +  --bundle /srv/private/backups/fixture-2026-10-08 +  --key-file /srv/private/recovery-passphrase +  --max-age-hours 24
```

Verification checks encrypted SHA256, decryptability/integrity, authenticated internal timestamps/release metadata, complete inventory, each file checksum, age/future-date and archive-path safety. A checksum alone is not authenticity; GPG integrity and the protected internal manifest are required. Capture only the returned checksum/result metadata. Replication/off-site retention is a separate approved operation; verify the received encrypted artifact after transfer before declaring that copy healthy.

## Isolated restore

Provision an **empty dedicated** MySQL instance bound to loopback, with events disabled. Its datadir must be under a private root named jarvis-restore-*; it must contain only MySQL system schemas before import. Create an owner-only file named .jarvis-isolated-restore in that root containing exactly jarvis-disposable-restore-v1. This marker and matching confirmation are deliberate operator safeguards, not an authentication mechanism.

The application root/app must not exist; the destination database must start jarvis_restore_. Read-only server facts prove datadir ownership and event-scheduler state before any CREATE/import. The tool rejects an existing application, nonempty server, external host, wrong prefix/marker, checksum/key/age failure, archive traversal, links/devices/duplicates and resource overflow. It never drops a database or overwrites an application. A failed import is a failed disposable target; inspect it privately and rebuild that isolated instance under a fresh authorization rather than retrying onto it.

```sh
tools/operations/restore-friday-isolated.sh +  --target-root /srv/isolated/jarvis-restore-2026-10-08 +  --database jarvis_restore_validation +  --client-file /srv/private/isolated-client.cnf +  --bundle /srv/private/backups/fixture-2026-10-08 +  --key-file /srv/private/recovery-passphrase +  --confirm-isolated jarvis-disposable-restore-v1 +  --max-age-hours 24
```

The output restore-evidence.json records checksum, release SHA, elapsed import time and explicit **unverified** fields for application boot, encrypted-record recovery, off-site retention and production recovery. It is not an approval to start the restored application.

## Required recovery sign-off

Keep the isolated environment behind an egress deny rule; no production domain/DNS, OAuth credentials, external mail/Slack/Telegram/AI requests, queue worker, scheduler or MySQL event execution. Before application boot, replace integration configuration with test adapters and redirect DB/cache/session/filesystem to isolated targets. Preserve the recovered encryption key solely for decryptability checks; never print decrypted contents.

1. Verify schema/migration inventory, per-table aggregate counts and representative relationships against the source snapshot receipt.
2. Verify every recovered upload hash and check representative attachment access using synthetic/operator-approved checks.
3. Decrypt representative encrypted Calendar/AI fields and report booleans/counts only.
4. Install reviewed locked dependencies, build frontend and verify application boot/authentication/routes with isolated credentials and blocked provider egress.
5. Record source backup age (RPO), full elapsed time through usable application (RTO), artifact checksum, release/schema identifiers, operator identity and sign-off date. Tool import duration alone is not RTO.
6. Verify an independent off-site copy, retention enforcement, monitoring on backup/restore failures and separate key custody. Exercise lost-host/lost-primary-key recovery.
7. Owner reviews evidence and separately approves any production recovery/deployment plan and rollback. An actual restore to production is outside this script and Phase 1 authorization.

## Validation completed in Phase 1

13 Python unittest cases pass, including real GPG backup/verify and independent disposable MySQL 8.4.3 source/target instances. A two-row synthetic table, encrypted Laravel record, .env/APP_KEY and attachment were recovered and verified. Guards cover missing/wrong key, expired artifact, bad checksum, partial dump, existing outputs/targets/nonempty server, external restore host, injected client options, unsafe database names, missing confirmation/marker and unsafe archive members.

Frontend build, Python compilation and Bash syntax checks pass. No application boot or real production-data recovery was attempted. Earlier test errors exposed MSYS agent path conversion and worktree-watcher handles on disposable datadirs; GPG uses an isolated temporary keyring and MySQL fixtures now live under a verified system-temporary parent. All owned test servers were stopped. Live production backups/off-site storage, Linux acceptance, monitoring, retention and recovery sign-off remain blocked prerequisites.
