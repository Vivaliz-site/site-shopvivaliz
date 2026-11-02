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

    def test_live_workflow_scheduled_sweep_rotates_and_paginates(self):
        workflow = (Path(__file__).resolve().parents[1] /
                    ".github/workflows/pr-completion-enforcer.yml").read_text(encoding="utf-8")
        self.assertIn("gh api --paginate", workflow, "do not miss PRs after page 1")
        self.assertIn("trusted/scripts/pr_completion_scheduler.py", workflow,
                      "scheduler cannot remain uncalled/dead code")
        self.assertIn("schedule_fair_slot", workflow, "each 15-minute sweep must rotate")
        self.assertIn("--batch 1", workflow, "retain bounded one-PR schedule budget")
        self.assertIn("test \"$(hostname -s)\" = 'marina-a1-4c24g'", workflow)

    def test_owner_only_security_grants_are_skipped_before_finalizer(self):
        root = Path(__file__).resolve().parents[1]
        workflow = (root / ".github/workflows/pr-completion-enforcer.yml").read_text()
        finalizer = (root / "scripts/merge_green_pr_via_vm.sh").read_text()
        for path in ("security/owner-approvals/*", "security/requests/*",
                     ".github/CODEOWNERS", "scripts/validate-owner-pr-grant.py"):
            self.assertIn(path, workflow)
            self.assertIn(path, finalizer)
        self.assertIn("owner_review_only=true", workflow)
        self.assertIn("owner-protected PR must not be autonomously merged", finalizer)

    def test_batch_one_rotation_reaches_all_not_just_oldest(self):
        rows = [pr(x) for x in (25, 50, 75, 100)]
        seen = [mod.select_prs(rows, REPO, slot, 1)[0] for slot in range(4)]
        self.assertEqual(seen, [25, 50, 75, 100])

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
