import json
import os
import pathlib
import subprocess
import sys
import tempfile
import time
import unittest

REPO = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO / "tools/testing"))
from disposable_mysql import disposable_mysql


@unittest.skipUnless(os.getenv("JARVIS_TEST_MYSQL_BIN"), "Explicit disposable MySQL binaries required.")
class CalendarConcurrency(unittest.TestCase):
    def test_two_clients_share_one_calendar_lease_and_create_one_mapping(self):
        parent = pathlib.Path(tempfile.gettempdir()) / "jarvis-phase1-calendar-tests"
        with disposable_mysql(os.environ["JARVIS_TEST_MYSQL_BIN"], parent) as instance:
            subprocess.run([instance["mysql"], "--defaults-file=" + str(instance["client"]), "--protocol=TCP",
                            "-e", "CREATE DATABASE jarvis_fixture_calendar"], capture_output=True, check=True)
            args = ["php", str(REPO / "tests/Support/calendar-sync-client.php"),
                    str(instance["root"]), str(instance["port"])]
            setup = subprocess.run(args + ["setup"], capture_output=True, check=True, timeout=60)
            connection_id = str(json.loads(setup.stdout)["connection_id"])
            holder = subprocess.Popen(args + ["hold", connection_id], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            try:
                deadline = time.monotonic() + 30
                while not (instance["root"] / "holder-entered").exists():
                    self.assertIsNone(holder.poll(), "Fixture holder exited before acquiring lease.")
                    self.assertLess(time.monotonic(), deadline)
                    time.sleep(0.05)
                contender = subprocess.run(args + ["contend", connection_id], capture_output=True, check=True, timeout=15)
                result = json.loads(contender.stdout)
                self.assertEqual(result["outcome"], "skipped")
                self.assertEqual(result["error_code"], "sync_in_progress")
                self.assertEqual(result["attempted"], 0)
                (instance["root"] / "release-holder").write_text("fixture")
                output, error = holder.communicate(timeout=30)
                self.assertEqual(holder.returncode, 0, error.decode(errors="replace"))
                self.assertEqual(json.loads(output)["outcome"], "success")
                rows = subprocess.run([instance["mysql"], "--defaults-file=" + str(instance["client"]), "--protocol=TCP",
                    "--batch", "--skip-column-names", "-e", "SELECT COUNT(*) FROM jarvis_fixture_calendar.calendar_event_mappings"],
                    capture_output=True, check=True).stdout.strip()
                self.assertEqual(rows, b"1")
            finally:
                (instance["root"] / "release-holder").write_text("fixture")
                if holder.poll() is None:
                    holder.terminate()
                    holder.wait(timeout=10)


if __name__ == "__main__":
    unittest.main()
