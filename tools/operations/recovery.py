"""Inert, guarded MySQL backup and isolated recovery CLI. No scheduling or upload."""
import argparse
import configparser
import datetime as dt
import hashlib
import json
import os
import pathlib
import re
import shutil
import subprocess
import tarfile
import tempfile
import threading
import time

MAX_BYTES = 16 * 1024 ** 3
MAX_FILES = 100000
MARKER = "jarvis-disposable-restore-v1"
EXCLUDED = {".git", "vendor", "node_modules", "backups", "test-results", "playwright-report"}


class RecoveryError(Exception):
    pass


def require(condition, message):
    if not condition:
        raise RecoveryError(message)


def regular(path, private=False):
    path = pathlib.Path(path).absolute()
    require(path.is_file() and not path.is_symlink(), "Required regular file unavailable.")
    require(not any(parent.is_symlink() for parent in path.parents), "Symlink paths are forbidden.")
    if private and os.name != "nt":
        stat = path.stat()
        require(stat.st_uid == os.geteuid() and stat.st_mode & 0o077 == 0,
                "Credential/key file must be owner-only.")
    return path.resolve()


def directory(path, private=False):
    path = pathlib.Path(path).absolute()
    require(path.is_dir() and not path.is_symlink(), "Required directory unavailable.")
    require(not any(parent.is_symlink() for parent in path.parents), "Symlink paths are forbidden.")
    if private and os.name != "nt":
        stat = path.stat()
        require(stat.st_uid == os.geteuid() and stat.st_mode & 0o077 == 0, "Private owner-only directory required.")
    return path.resolve()


def digest(path):
    result = hashlib.sha256()
    with open(path, "rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            result.update(block)
    return result.hexdigest()


def credentials(path, isolated=False):
    path = regular(path, private=True)
    text = path.read_text()
    require("!include" not in text.lower(), "Included client configuration is forbidden.")
    config = configparser.ConfigParser(interpolation=None)
    config.read_string(text)
    require(config.sections() == ["client"], "Only a client section is supported.")
    client = config["client"]
    require(set(client).issubset({"host", "port", "user", "password"}), "Unexpected client configuration.")
    host = client.get("host", "")
    port = client.get("port", "")
    require(port.isdigit() and 1024 <= int(port) <= 65535, "An explicit TCP port is required.")
    require(bool(client.get("user")), "An explicit database user is required.")
    require(bool(host) and re.fullmatch(r"[A-Za-z0-9.:-]+", host) is not None, "An explicit safe host is required.")
    if isolated:
        require(host in {"127.0.0.1", "localhost"}, "Restore is restricted to loopback.")
    return path, host, port


def command(binary, client, *args, input_data=None):
    path, host, port = client
    # Credentials remain in a private file, never command arguments or output.
    result = subprocess.run([binary, "--defaults-file=" + str(path), "--protocol=TCP",
                             "--host=" + host, "--port=" + port, *args],
                            input=input_data, capture_output=True, timeout=120)
    require(result.returncode == 0, "Database operation failed; private diagnostics withheld.")
    return result.stdout


def crypto(key, source, target, decrypt=False):
    key = regular(key, private=True)
    require(key.stat().st_size >= 16, "Encryption key material is too short.")
    target = pathlib.Path(target)
    temp_parent = pathlib.Path(tempfile.gettempdir()).resolve()
    home = pathlib.Path(tempfile.mkdtemp(prefix="jg", dir=temp_parent)).resolve()
    os.chmod(home, 0o700)
    def gpg_path(path):
        path = pathlib.Path(path).resolve()
        # Git for Windows GPG is an MSYS binary; its agent requires POSIX drive paths.
        if os.name == "nt" and str(shutil.which("gpg")).replace("\\", "/").lower().endswith("/usr/bin/gpg.exe"):
            return "/" + path.drive[0].lower() + path.as_posix()[2:]
        return str(path)
    try:
        args = ["gpg", "--no-options", "--homedir", gpg_path(home), "--batch", "--yes",
                "--pinentry-mode", "loopback", "--no-symkey-cache", "--passphrase-file", gpg_path(key)]
        if decrypt:
            with subprocess.Popen(args + ["--decrypt", gpg_path(source)], stdout=subprocess.PIPE,
                                  stderr=subprocess.DEVNULL) as process:
                deadline = threading.Timer(120, process.kill)
                deadline.daemon = True
                deadline.start()
                try:
                    total = 0
                    with target.open("xb") as output:
                        while block := process.stdout.read(1024 * 1024):
                            total += len(block)
                            if total > MAX_BYTES:
                                process.kill()
                                raise RecoveryError("Decrypted archive exceeds size limit.")
                            output.write(block)
                    require(process.wait(timeout=120) == 0, "Backup decryption/integrity check failed.")
                finally:
                    deadline.cancel()
        else:
            result = subprocess.run(args + ["--compress-algo", "none", "--symmetric", "--cipher-algo",
                                            "AES256", "--output", gpg_path(target), gpg_path(source)],
                                    stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=120)
            require(result.returncode == 0, "Backup encryption failed.")
    finally:
        # Only the random directory created by this invocation is removed.
        require(home.resolve().parent == temp_parent and home.name.startswith("jg"),
                "Unsafe crypto cleanup target.")
        # Stop only this isolated keyring's agent so its files can be removed.
        try:
            subprocess.run(["gpgconf", "--homedir", gpg_path(home), "--kill", "gpg-agent"],
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=15)
        finally:
            shutil.rmtree(home)


def safe_members(archive):
    members = archive.getmembers()
    require(len(members) <= MAX_FILES and sum(member.size for member in members) <= MAX_BYTES,
            "Archive resource limit exceeded.")
    names = set()
    for member in members:
        name = member.name
        path = pathlib.PurePosixPath(name)
        require(member.isfile() and name not in names and not path.is_absolute()
                and ".." not in path.parts and "\\" not in name and ":" not in name
                and str(path) == name and all(part not in ("", ".") for part in path.parts),
                "Unsafe archive member.")
        names.add(name)
    return members


def inspect_archive(archive_path):
    with tarfile.open(archive_path, "r:") as archive:
        members = safe_members(archive)
        require("manifest.json" in {member.name for member in members}, "Internal manifest missing.")
        manifest_member = archive.getmember("manifest.json")
        require(manifest_member.size <= 8 * 1024 * 1024, "Manifest exceeds size limit.")
        manifest = json.load(archive.extractfile(manifest_member))
        require(manifest.get("format") == 1, "Unsupported backup format.")
        expected = manifest.get("files", {})
        require(set(expected) == {member.name for member in members} - {"manifest.json"},
                "Archive inventory mismatch.")
        for member in members:
            if member.name == "manifest.json":
                continue
            result = hashlib.sha256()
            with archive.extractfile(member) as stream:
                for block in iter(lambda: stream.read(1024 * 1024), b""):
                    result.update(block)
            require(expected[member.name] == {"sha256": result.hexdigest(), "size": member.size},
                    "Archive member checksum mismatch.")
        require("database.sql" in expected and "app/.env" in expected
                and "app/composer.lock" in expected and "app/package-lock.json" in expected,
                "Required recovery material missing.")
        return manifest


def backup(args):
    app = directory(args.app_dir)
    output = pathlib.Path(args.output_dir).absolute()
    parent = directory(output.parent, private=True)
    output = parent / output.name
    require(not output.exists() and not output.is_symlink(), "Backup output already exists.")
    require(not output.is_relative_to(app), "Backup output must be outside the application.")
    key = regular(args.key_file, private=True)
    require(not key.is_relative_to(app) and not key.is_relative_to(output),
            "Encryption key must be retained separately.")
    require(re.fullmatch("[0-9a-f]{40}", args.release_sha) is not None, "An explicit release SHA is required.")
    require(re.fullmatch("[A-Za-z0-9_]+", args.database) is not None, "Unsafe database name.")
    client = credentials(args.client_file)
    if not args.allow_live_source:
        require(args.database.startswith("jarvis_fixture_") and client[1] in {"127.0.0.1", "localhost"},
                "Live sources require explicit separate operator authorization.")
    else:
        require(os.name != "nt", "Live-source tooling is supported only on a controlled Linux host.")
    for name in (".env", "composer.lock", "package-lock.json"):
        regular(app / name)
    require(any(line.strip().startswith("APP_KEY=") and line.strip().partition("=")[2].strip().strip("'\"")
                for line in (app / ".env").read_text().splitlines()), "Application key recovery material missing.")
    with tempfile.TemporaryDirectory(prefix="jarvis-backup-", dir=parent) as temp:
        temp = pathlib.Path(temp)
        os.chmod(temp, 0o700)
        dump = temp / "database.sql"
        # Stream rather than buffering a production snapshot in memory.
        with dump.open("xb") as stream:
            proc = subprocess.Popen([args.mysqldump, "--defaults-file=" + str(client[0]), "--protocol=TCP",
                                   "--host=" + client[1], "--port=" + client[2], "--single-transaction",
                                   "--skip-lock-tables", "--routines", "--events", "--triggers",
                                   "--hex-blob", "--no-tablespaces", "--set-gtid-purged=OFF", args.database],
                                  stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
            deadline = threading.Timer(600, proc.kill)
            deadline.daemon = True
            deadline.start()
            try:
                total = 0
                while block := proc.stdout.read(1024 * 1024):
                    total += len(block)
                    require(total <= MAX_BYTES, "Database snapshot exceeds size limit.")
                    stream.write(block)
                require(proc.wait(timeout=600) == 0 and total > 0, "Snapshot failed; no completed backup produced.")
            finally:
                deadline.cancel()
                proc.stdout.close()
                if proc.poll() is None:
                    proc.kill()
                    proc.wait()
        sources = {"database.sql": dump}
        for root, dirs, files in os.walk(app, followlinks=False):
            root = pathlib.Path(root)
            for entry in dirs + files:
                require(not (root / entry).is_symlink(), "Symlinks must be resolved by an operator before backup.")
            dirs[:] = [name for name in dirs if name not in EXCLUDED
                       and (root / name).relative_to(app).as_posix() not in
                       {"storage/logs", "storage/framework", "bootstrap/cache", "public/build"}]
            for name in files:
                if name in EXCLUDED:
                    continue
                file = regular(root / name)
                sources["app/" + file.relative_to(app).as_posix()] = file
        require(len(sources) < MAX_FILES and sum(file.stat().st_size for file in sources.values()) <= MAX_BYTES,
                "Snapshot resource limit exceeded.")
        manifest = {"format": 1, "created_at": dt.datetime.now(dt.timezone.utc).isoformat(),
                    "release_sha": args.release_sha, "database": args.database,
                    "files": {name: {"sha256": digest(file), "size": file.stat().st_size}
                              for name, file in sources.items()}}
        internal = temp / "manifest.json"
        internal.write_text(json.dumps(manifest, sort_keys=True))
        archive_path = temp / "snapshot.tar"
        with tarfile.open(archive_path, "w") as archive:
            for name, file in sources.items():
                require(not file.samefile(key), "Encryption key must not be included through a hard link.")
                archive.add(file, arcname=name, recursive=False)
            archive.add(internal, arcname="manifest.json", recursive=False)
        inspect_archive(archive_path)
        require(archive_path.stat().st_size <= MAX_BYTES, "Snapshot archive exceeds size limit.")
        encrypted = temp / "snapshot.tar.gpg"
        crypto(key, archive_path, encrypted)
        stage = temp / "completed"
        stage.mkdir(mode=0o700)
        encrypted.rename(stage / encrypted.name)
        (stage / "manifest.json").write_text(json.dumps({
            "format": 1, "created_at": manifest["created_at"], "release_sha": args.release_sha,
            "artifact": "snapshot.tar.gpg", "sha256": digest(stage / encrypted.name)}, indent=2))
        # Publish only after successful encryption. Never overwrite.
        require(not output.exists(), "Backup destination appeared during creation.")
        stage.rename(output)
    return {"status": "backup_created", "sha256": digest(output / "snapshot.tar.gpg")}


def verified_archive(args, temp):
    bundle = directory(args.bundle)
    manifest = json.loads(regular(bundle / "manifest.json").read_text())
    artifact = regular(bundle / "snapshot.tar.gpg")
    require(manifest.get("format") == 1 and manifest.get("artifact") == artifact.name,
            "Unsupported artifact manifest.")
    require(artifact.stat().st_size <= MAX_BYTES and digest(artifact) == manifest.get("sha256"),
            "Encrypted artifact checksum mismatch.")
    archive = pathlib.Path(temp) / "verified.tar"
    crypto(args.key_file, artifact, archive, decrypt=True)
    internal = inspect_archive(archive)
    require(internal["created_at"] == manifest["created_at"] and internal["release_sha"] == manifest["release_sha"],
            "External manifest is inconsistent with authenticated archive.")
    created = dt.datetime.fromisoformat(internal["created_at"])
    require(created.tzinfo is not None, "Backup timestamp must contain a timezone.")
    age = dt.datetime.now(dt.timezone.utc) - created
    require(dt.timedelta(0) <= age <= dt.timedelta(hours=args.max_age_hours),
            "Backup is stale or has a future timestamp.")
    return archive, internal, manifest["sha256"]


def verify(args):
    with tempfile.TemporaryDirectory(prefix="jarvis-verify-") as temp:
        _, manifest, checksum = verified_archive(args, temp)
        return {"status": "integrity_verified", "sha256": checksum, "release_sha": manifest["release_sha"],
                "files": len(manifest["files"]), "production_recovery": "unverified"}


def restore(args):
    started = time.monotonic()
    root = directory(args.target_root, private=True)
    require(len(root.parts) >= 3 and root.name.startswith("jarvis-restore-"), "Target root must be a dedicated jarvis-restore-* directory.")
    require(regular(root / ".jarvis-isolated-restore").read_text().strip() == MARKER,
            "Disposable target marker is required.")
    require(args.confirm_isolated == MARKER, "Explicit isolated-target confirmation is required.")
    require(re.fullmatch("jarvis_restore_[a-z0-9_]+", args.database) is not None,
            "Restore database must have the jarvis_restore_ prefix.")
    app = root / "app"
    require(not app.exists() and not app.is_symlink(), "Restore application target already exists.")
    client = credentials(args.client_file, isolated=True)
    key = regular(args.key_file, private=True)
    require(not key.is_relative_to(app), "Decryption key cannot be restored into application files.")
    facts = command(args.mysql, client, "--batch", "--skip-column-names", "-e",
                    "SELECT @@datadir, @@event_scheduler").decode().strip().split("\t")
    require(len(facts) == 2 and facts[1] in {"OFF", "DISABLED"}, "Isolated server must have events disabled.")
    data_dir = pathlib.Path(facts[0]).resolve()
    require(data_dir.is_relative_to(root) and data_dir != root, "Server datadir is outside the disposable target.")
    databases = command(args.mysql, client, "--batch", "--skip-column-names", "-e",
                        "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA").decode().splitlines()
    require(set(databases).issubset({"mysql", "sys", "information_schema", "performance_schema"}),
            "Restore requires an empty dedicated MySQL instance.")
    with tempfile.TemporaryDirectory(prefix="jarvis-restore-", dir=root) as temp:
        archive_path, manifest, checksum = verified_archive(args, temp)
        command(args.mysql, client, "-e", "CREATE DATABASE `" + args.database + "`")
        with archive_path.open("rb"):
            with tarfile.open(archive_path, "r:") as archive:
                sql = archive.extractfile("database.sql")
                proc = subprocess.Popen([args.mysql, "--defaults-file=" + str(client[0]), "--protocol=TCP",
                                         "--host=" + client[1], "--port=" + client[2], args.database],
                                        stdin=subprocess.PIPE, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
                try:
                    shutil.copyfileobj(sql, proc.stdin)
                    proc.stdin.close()
                    require(proc.wait(timeout=600) == 0, "Isolated SQL import failed.")
                finally:
                    sql.close()
                    if proc.poll() is None:
                        proc.kill()
                        proc.wait()
                app.mkdir(mode=0o700)
                for member in safe_members(archive):
                    if not member.name.startswith("app/"):
                        continue
                    destination = root / member.name
                    destination.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
                    with archive.extractfile(member) as source, destination.open("xb") as output:
                        shutil.copyfileobj(source, output)
                    os.chmod(destination, 0o600)
    evidence = {"status": "isolated_import_verified", "sha256": checksum,
                "release_sha": manifest["release_sha"], "duration_seconds": round(time.monotonic() - started, 2),
                "application_boot": "unverified", "encrypted_record_decryption": "unverified",
                "offsite_retention": "unverified", "production_recovery": "unverified"}
    (root / "restore-evidence.json").write_text(json.dumps(evidence, indent=2))
    return evidence


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="operation", required=True)
    create = sub.add_parser("backup")
    for name in ("app-dir", "output-dir", "database", "client-file", "key-file", "release-sha"):
        create.add_argument("--" + name, required=True)
    create.add_argument("--mysqldump", default="mysqldump")
    create.add_argument("--allow-live-source", action="store_true")
    for name in ("verify", "restore"):
        child = sub.add_parser(name)
        child.add_argument("--bundle", required=True)
        child.add_argument("--key-file", required=True)
        child.add_argument("--max-age-hours", type=int, default=24)
        if name == "restore":
            for option in ("target-root", "database", "client-file", "confirm-isolated"):
                child.add_argument("--" + option, required=True)
            child.add_argument("--mysql", default="mysql")
    args = parser.parse_args()
    try:
        result = globals()[args.operation](args)
        print(json.dumps(result, sort_keys=True))
    except (RecoveryError, OSError, ValueError, TypeError, AttributeError, configparser.Error, tarfile.TarError,
            subprocess.SubprocessError, KeyError) as error:
        # Never echo subprocess diagnostics, SQL, credentials, paths or JSON contents.
        message = str(error) if isinstance(error, RecoveryError) else "Recovery operation failed safely."
        print(json.dumps({"status": "failed", "reason": message}))
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
