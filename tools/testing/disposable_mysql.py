"""Owned, empty MySQL instance for synthetic tests. Never attaches to existing servers."""
import contextlib
import os
import pathlib
import socket
import subprocess
import tempfile
import time


class OwnedDisposableDirectory(tempfile.TemporaryDirectory):
    def cleanup(self):
        root = pathlib.Path(self.name).resolve()
        parent = pathlib.Path(self._task_parent).resolve()
        if root.parent != parent or not root.name.startswith("jarvis-restore-"):
            raise RuntimeError("Unsafe disposable cleanup target.")
        # Windows scanners can hold files briefly after mysqld has fully exited.
        for attempt in range(40):
            try:
                super().cleanup()
                return
            except PermissionError:
                if attempt == 39:
                    raise
                time.sleep(0.25)


@contextlib.contextmanager
def disposable_mysql(binary_directory, parent):
    binary_directory = pathlib.Path(binary_directory)
    suffix = ".exe" if os.name == "nt" else ""
    binaries = {name: str(binary_directory / (name + suffix)) for name in ("mysqld", "mysql", "mysqldump", "mysqladmin")}
    if not all(pathlib.Path(path).is_file() for path in binaries.values()):
        raise RuntimeError("Disposable MySQL binaries unavailable.")
    parent = pathlib.Path(parent).resolve()
    parent.mkdir(parents=True, exist_ok=True)
    temporary = OwnedDisposableDirectory(prefix="jarvis-restore-", dir=parent)
    temporary._task_parent = parent
    with temporary as folder:
        root = pathlib.Path(folder).resolve()
        if root.parent != parent or not root.name.startswith("jarvis-restore-"):
            raise RuntimeError("Unsafe disposable instance path.")
        data = root / "mysql-data"
        data.mkdir()
        (root / ".jarvis-isolated-restore").write_text("jarvis-disposable-restore-v1")
        initialized = subprocess.run([binaries["mysqld"], "--no-defaults", "--initialize-insecure",
                                      "--basedir=" + str(binary_directory.parent), "--datadir=" + str(data)],
                                     capture_output=True, timeout=120)
        if initialized.returncode:
            raise RuntimeError("Disposable MySQL initialization failed.")
        with socket.socket() as reserved:
            reserved.bind(("127.0.0.1", 0))
            port = reserved.getsockname()[1]
        client = root / "client.cnf"
        client.write_text("[client]\nhost=127.0.0.1\nport=" + str(port) + "\nuser=root\npassword=\n")
        os.chmod(client, 0o600)
        with (root / "mysql.log").open("wb") as log:
            server = subprocess.Popen([binaries["mysqld"], "--no-defaults", "--basedir=" + str(binary_directory.parent),
                                       "--datadir=" + str(data), "--port=" + str(port), "--bind-address=127.0.0.1",
                                       "--mysqlx=OFF", "--event-scheduler=OFF", "--skip-log-bin",
                                       "--log-error=" + str(root / "mysql-error.log")],
                                      stdout=log, stderr=log)
            try:
                for _ in range(100):
                    if server.poll() is not None:
                        raise RuntimeError("Disposable MySQL stopped before readiness.")
                    ping = subprocess.run([binaries["mysqladmin"], "--defaults-file=" + str(client),
                                           "--protocol=TCP", "ping"], capture_output=True, timeout=5)
                    if ping.returncode == 0:
                        break
                    time.sleep(0.2)
                else:
                    raise RuntimeError("Disposable MySQL readiness timeout.")
                yield {"root": root, "client": client, "port": port, **binaries}
            finally:
                if server.poll() is None:
                    subprocess.run([binaries["mysqladmin"], "--defaults-file=" + str(client), "--protocol=TCP", "shutdown"],
                                   capture_output=True, timeout=20)
                if server.poll() is None:
                    server.terminate()
                server.wait(timeout=20)
                if os.name == "nt":
                    time.sleep(0.5)  # Allow Windows handles/scanners to release the stopped datadir.
        # TemporaryDirectory deletes only this owned, verified child path.
