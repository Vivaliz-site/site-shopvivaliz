import importlib.util
from pathlib import Path
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / "scripts/pr_completion_scheduler.py"
spec = importlib.util.spec_from_file_location("pr_schedule", SCRIPT)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
REPO = "Vivaliz-site/site-shopvivaliz"


def pr(n, **kw):
    row = {"number": n, "state": "open", "draft": False,
           "head": {"repo": {"full_name": REPO}},
           "base": {"ref": "main"}, "labels": []}
    row.update(kw)
    return row


class SchedulerTests(unittest.TestCase):
    def test_every_eligible_pr_gets_a_turn_even_with_stuck_low_number(self):
        rows = [pr(x) for x in range(1, 71)]
        seen = []
        for slot in range(18):
            batch = mod.select_prs(rows, REPO, slot, 4)
            self.assertEqual(len(set(batch)), len(batch))
            seen += batch
        self.assertEqual(set(seen), set(range(1, 71)))

    def test_held_or_draft_prs_are_not_mutated(self):
        rows = [pr(1), pr(2, draft=True), pr(3, labels=[{"name": "blocked-external"}]),
                pr(4, labels=[{"name": "no-auto-merge"}]),
                pr(5, head={"repo": {"full_name": "elsewhere/fork"}})]
        self.assertEqual(mod.select_prs(rows, REPO, 0, 4), [1])

    def test_no_prs_graceful_and_bad_inputs_fail_closed(self):
        self.assertEqual(mod.select_prs([], REPO, 0, 4), [])
        with self.assertRaises(ValueError):
            mod.select_prs({}, REPO, 0, 4)
        with self.assertRaises(ValueError):
            mod.select_prs([], REPO, -1, 4)
        with self.assertRaises(ValueError):
            mod.select_prs([], REPO, 0, 0)
        with self.assertRaises(ValueError):
            mod.select_prs([], REPO, 0, 100)

    def test_duplicate_input_and_partial_final_batch_are_safe(self):
        rows = [pr(1), pr(1), pr(2)]
        self.assertEqual(mod.select_prs(rows, REPO, 17, 4), [1, 2])


if __name__ == "__main__":
    unittest.main(verbosity=2)
