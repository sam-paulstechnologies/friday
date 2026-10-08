import argparse
import copy
import datetime as dt
import importlib.util
import io
import json
import os
import pathlib
import subprocess
import sys
import tarfile
import tempfile
import unittest

REPO = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO / "tools/operations"))
sys.path.insert(0, str(REPO / "tools/testing"))
import recovery
from disposable_mysql import disposable_mysql


class RecoveryGuards(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory(prefix="jarvis-restore-guard-")
        self.root = pathlib.Path(self.folder.name)
        self.client = self.root / "client.cnf"
        self.client.write_text("[client]\nhost=127.0.0.1\nport=13306\nuser=synthetic\npassword=synthetic\n")
        os.chmod(self.client, 0o600)

    def tearDown(self):
        self.folder.cleanup()

    def archive(self, name, kind=tarfile.REGTYPE):
        path = self.root / "unsafe.tar"
        with tarfile.open(path, "w") as tar:
            member = tarfile.TarInfo(name)
            member.type = kind
            member.size = 1 if kind == tarfile.REGTYPE else 0
            member.linkname = "../outside"
            tar.addfile(member, io.BytesIO(b"x") if member.isfile() else None)
        return path

    def test_valid_private_client_configuration(self):
        self.assertEqual(recovery.credentials(self.client, isolated=True)[1:], ("127.0.0.1", "13306"))

    def test_external_restore_host_rejected(self):
        self.client.write_text(self.client.read_text().replace("127.0.0.1", "168.144.89.237"))
        with self.assertRaises(recovery.RecoveryError):
            recovery.credentials(self.client, isolated=True)

    def test_included_client_configuration_rejected(self):
        self.client.write_text("!include /private/client.cnf\n")
        with self.assertRaises(recovery.RecoveryError):
            recovery.credentials(self.client)

    def test_implicit_port_rejected(self):
        self.client.write_text("[client]\nhost=127.0.0.1\nuser=synthetic\n")
        with self.assertRaises(recovery.RecoveryError):
            recovery.credentials(self.client)

    def test_injected_client_options_rejected(self):
        self.client.write_text(self.client.read_text() + "init-command=DROP DATABASE private\n")
        with self.assertRaises(recovery.RecoveryError):
            recovery.credentials(self.client)

    def test_traversal_and_absolute_archive_members_rejected(self):
        for name in ("../outside", "/outside", "C:/outside", "app/../outside", "app\\outside"):
            with self.subTest(name=name), tarfile.open(self.archive(name), "r:") as tar:
                with self.assertRaises(recovery.RecoveryError):
                    recovery.safe_members(tar)

    def test_links_and_devices_rejected(self):
        for kind in (tarfile.SYMTYPE, tarfile.LNKTYPE, tarfile.CHRTYPE, tarfile.FIFOTYPE):
            with self.subTest(kind=kind), tarfile.open(self.archive("app/link", kind), "r:") as tar:
                with self.assertRaises(recovery.RecoveryError):
                    recovery.safe_members(tar)

    def test_duplicate_archive_paths_rejected(self):
        path = self.root / "duplicate.tar"
        with tarfile.open(path, "w") as tar:
            for _ in range(2):
                member = tarfile.TarInfo("app/file")
                member.size = 1
                tar.addfile(member, io.BytesIO(b"x"))
        with tarfile.open(path) as tar, self.assertRaises(recovery.RecoveryError):
            recovery.safe_members(tar)

    def test_missing_manifest_rejected(self):
        with self.assertRaises(recovery.RecoveryError):
            recovery.inspect_archive(self.archive("database.sql"))

    def test_restore_marker_required_before_any_database_operation(self):
        with self.assertRaises(recovery.RecoveryError):
            recovery.restore(argparse.Namespace(target_root=self.root))

    def test_unsafe_database_name_rejected_before_database_operation(self):
        (self.root / ".jarvis-isolated-restore").write_text(recovery.MARKER)
        with self.assertRaises(recovery.RecoveryError):
            recovery.restore(argparse.Namespace(target_root=self.root, confirm_isolated=recovery.MARKER, database="friday"))

    def test_restore_requires_explicit_confirmation(self):
        (self.root / ".jarvis-isolated-restore").write_text(recovery.MARKER)
        with self.assertRaises(recovery.RecoveryError):
            recovery.restore(argparse.Namespace(target_root=self.root, confirm_isolated="", database="jarvis_restore_test"))


@unittest.skipUnless(os.getenv("JARVIS_TEST_MYSQL_BIN"), "Explicit disposable MySQL binaries required.")
class MySqlRecovery(unittest.TestCase):
    def test_real_encrypted_backup_restore_and_failure_guards(self):
        workspace = pathlib.Path(tempfile.gettempdir()) / "jarvis-phase1-recovery-tests"
        workspace.mkdir(parents=True, exist_ok=True)
        with tempfile.TemporaryDirectory(prefix="jarvis-fixtures-", dir=workspace) as fixtures:
            fixtures = pathlib.Path(fixtures)
            key = fixtures / "key"
            key.write_text("synthetic-encryption-passphrase-for-tests-only")
            os.chmod(key, 0o600)
            app = fixtures / "source-app"
            (app / "storage/app/private").mkdir(parents=True)
            (app / ".env").write_text("APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=\n")
            (app / "composer.lock").write_text("{}")
            (app / "package-lock.json").write_text("{}")
            (app / "storage/app/private/attachment.txt").write_text("synthetic attachment")
            with disposable_mysql(os.environ["JARVIS_TEST_MYSQL_BIN"], workspace) as source:
                client = recovery.credentials(source["client"], isolated=True)
                encrypt = ['php', '-r',
                    'require $argv[1]; $e=new Illuminate\\Encryption\\Encrypter(str_repeat(chr(0),32),"AES-256-CBC"); echo $e->encryptString("synthetic-encrypted-record");',
                    str(REPO / "vendor/autoload.php")]
                cipher = subprocess.run(encrypt, capture_output=True, check=True).stdout.decode()
                recovery.command(source["mysql"], client, "-e",
                                 "CREATE DATABASE jarvis_fixture_source; CREATE TABLE jarvis_fixture_source.records(id INT PRIMARY KEY, value VARCHAR(2048));"
                                 "INSERT INTO jarvis_fixture_source.records VALUES (1,'" + cipher + "'),(2,'fixture');")
                bundle = fixtures / "bundle"
                args = argparse.Namespace(app_dir=app, output_dir=bundle, database="jarvis_fixture_source",
                                          client_file=source["client"], key_file=key, release_sha="a" * 40,
                                          allow_live_source=False, mysqldump=source["mysqldump"])
                result = recovery.backup(args)
                self.assertEqual(result["status"], "backup_created")
                verification = argparse.Namespace(bundle=bundle, key_file=key, max_age_hours=24)
                self.assertEqual(recovery.verify(verification)["status"], "integrity_verified")
                self.assertEqual(recovery.verify(verification)["production_recovery"], "unverified")
                missing = copy.copy(verification)
                missing.key_file = fixtures / "missing-key"
                with self.assertRaises(recovery.RecoveryError):
                    recovery.verify(missing)
                with self.assertRaises(recovery.RecoveryError):
                    recovery.backup(args)
                altered = copy.copy(args)
                altered.output_dir = fixtures / "failure"
                altered.mysqldump = source["mysqladmin"]  # Deliberate incompatible command.
                with self.assertRaises(recovery.RecoveryError):
                    recovery.backup(altered)
                self.assertFalse(altered.output_dir.exists())
                altered = copy.copy(args)
                altered.output_dir = app / "public/backup"
                with self.assertRaises(recovery.RecoveryError):
                    recovery.backup(altered)
                wrong_key = fixtures / "wrong-key"
                wrong_key.write_text("different-synthetic-encryption-passphrase")
                os.chmod(wrong_key, 0o600)
                invalid = copy.copy(verification)
                invalid.key_file = wrong_key
                with self.assertRaises(recovery.RecoveryError):
                    recovery.verify(invalid)
                invalid = copy.copy(verification)
                invalid.max_age_hours = 0
                with self.assertRaises(recovery.RecoveryError):
                    recovery.verify(invalid)
                manifest_path = bundle / "manifest.json"
                original = manifest_path.read_text()
                manifest = json.loads(original)
                manifest["sha256"] = "0" * 64
                manifest_path.write_text(json.dumps(manifest))
                with self.assertRaises(recovery.RecoveryError):
                    recovery.verify(verification)
                manifest_path.write_text(original)
                with disposable_mysql(os.environ["JARVIS_TEST_MYSQL_BIN"], workspace) as target:
                    restore_args = argparse.Namespace(target_root=target["root"], confirm_isolated=recovery.MARKER,
                        database="jarvis_restore_fixture", client_file=target["client"], key_file=key,
                        mysql=target["mysql"], bundle=bundle, max_age_hours=24)
                    result = recovery.restore(restore_args)
                    self.assertEqual(result["production_recovery"], "unverified")
                    target_client = recovery.credentials(target["client"], isolated=True)
                    count = recovery.command(target["mysql"], target_client, "--batch", "--skip-column-names",
                                             "-e", "SELECT COUNT(*) FROM jarvis_restore_fixture.records").strip()
                    self.assertEqual(count, b"2")
                    restored_cipher = recovery.command(target["mysql"], target_client, "--batch", "--skip-column-names",
                        "-e", "SELECT value FROM jarvis_restore_fixture.records WHERE id=1").strip()
                    decrypt = ['php', '-r',
                        'require $argv[1]; $e=new Illuminate\\Encryption\\Encrypter(str_repeat(chr(0),32),"AES-256-CBC"); echo $e->decryptString(stream_get_contents(STDIN));',
                        str(REPO / "vendor/autoload.php")]
                    self.assertEqual(subprocess.run(decrypt, input=restored_cipher, capture_output=True, check=True).stdout,
                                     b"synthetic-encrypted-record")
                    self.assertEqual((target["root"] / "app/storage/app/private/attachment.txt").read_text(), "synthetic attachment")
                    self.assertEqual((target["root"] / "app/.env").read_bytes(), (app / ".env").read_bytes())
                    with self.assertRaises(recovery.RecoveryError):
                        recovery.restore(restore_args)
                with self.assertRaises(recovery.RecoveryError):
                    restore_args.target_root = source["root"]
                    restore_args.client_file = source["client"]
                    recovery.restore(restore_args)  # Source contains an existing DB; cannot become restore target.


if __name__ == "__main__":
    unittest.main()
