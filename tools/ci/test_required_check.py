import unittest
from required_check import validate


class RequiredCheckTests(unittest.TestCase):
    def results(self):
        return {job: {"result": "success"} for job in ("php", "mysql", "frontend", "e2e")}

    def test_all_success_passes(self):
        self.assertTrue(validate(self.results()))

    def test_every_non_success_fails(self):
        for job in self.results():
            for status in ("failure", "cancelled", "skipped", "pending", None):
                with self.subTest(job=job, status=status):
                    results = self.results()
                    results[job]["result"] = status
                    self.assertFalse(validate(results))

    def test_missing_or_extra_job_fails(self):
        results = self.results()
        del results["mysql"]
        self.assertFalse(validate(results))
        results = self.results()
        results["unexpected"] = {"result": "success"}
        self.assertFalse(validate(results))

    def test_empty_fails(self):
        self.assertFalse(validate({}))


if __name__ == "__main__":
    unittest.main()
