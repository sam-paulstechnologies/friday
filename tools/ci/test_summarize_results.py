import json
import pathlib
import subprocess
import sys
import tempfile
import unittest


class SummaryTests(unittest.TestCase):
    def test_private_output_is_removed_without_hiding_failure_counts(self):
        with tempfile.TemporaryDirectory(prefix="jarvis-ci-summary-") as folder:
            root = pathlib.Path(folder)
            source = root / "input.xml"
            source.write_text('<testsuites><testsuite><testcase class="Tests\\SafeTest" name="test_failure with data synthetic-secret">'
                              '<failure type="AssertionError">synthetic-secret</failure><system-out>synthetic-secret</system-out>'
                              '</testcase><testcase name="test_pass"/></testsuite></testsuites>')
            subprocess.run([sys.executable, str(pathlib.Path(__file__).with_name("summarize_results.py")),
                            str(source), str(root / "published")], check=True, capture_output=True)
            summary = json.loads((root / "published/summary.json").read_text())
            self.assertEqual(summary["counts"], {"failure": 1, "passed": 1})
            self.assertEqual(summary["failures"][0]["test"], "test_failure")
            for file in (root / "published").iterdir():
                self.assertNotIn("synthetic-secret", file.read_text())
