import unittest

from scripts import ci_suite_relevance as rel


class SuiteRelevanceTests(unittest.TestCase):
    def test_suite_runs_when_its_own_test_file_changes(self):
        run, reason = rel.decide("python3 -m unittest tests.test_task_resume_queue -v",
                                 ["tests/test_task_resume_queue.py"])
        self.assertTrue(run, reason)

    def test_suite_runs_when_module_it_tests_changes(self):
        run, _ = rel.decide("python3 -m unittest tests.test_task_resume_queue -v",
                            ["scripts/task_resume_queue.py"])
        self.assertTrue(run)

    def test_unrelated_app_change_skips_suite(self):
        run, reason = rel.decide("python3 -m unittest tests.test_task_resume_queue -v",
                                 ["api/cart/add.php"])
        self.assertFalse(run, reason)

    def test_gate_core_change_runs_every_suite(self):
        run, reason = rel.decide("python3 -m unittest tests.test_task_resume_queue -v",
                                 [".github/workflows/x.yml"])
        self.assertTrue(run, reason)

    def test_missing_diff_runs_suite(self):
        run, _ = rel.decide("python3 -m unittest tests.test_task_resume_queue -v", [])
        self.assertTrue(run)

    def test_opaque_command_runs(self):
        run, reason = rel.decide("bash -n something-unknown", ["api/cart/add.php"])
        self.assertTrue(run, reason)

    def test_short_stem_does_not_match_unrelated_suite(self):
        run, _ = rel.decide("python3 -m unittest tests.test_task_resume_queue -v", ["api/a.php"])
        self.assertFalse(run)


if __name__ == "__main__":
    unittest.main()
