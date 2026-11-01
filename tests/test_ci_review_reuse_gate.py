"""Fail-closed tests of SHA/base-bound gate reuse after human review."""
from __future__ import annotations
import importlib.util
from pathlib import Path
import copy
import unittest

ROOT = Path(__file__).resolve().parents[1]
FILE = ROOT / "scripts" / "ci_review_reuse_gate.py"
spec = importlib.util.spec_from_file_location("review_reuse_gate", FILE)
assert spec and spec.loader
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

HEAD = "a" * 40
BASE = "b" * 40
REFERENCE = {
    "workflow_runs": [{
        "id": 11,
        "name": "Mandatory Validation Gate",
        "path": ".github/workflows/mandatory-validation-gate.yml",
        "event": "pull_request", "status": "completed", "conclusion": "success",
        "head_sha": HEAD, "head_branch": "fix/abc",
        "pull_requests": [{"number": 123, "head": {"sha": HEAD}, "base": {"sha": BASE}}],
    }]
}


def allowed(document):
    return module.can_reuse(document, pr=123, head=HEAD, base=BASE,
                            head_ref="fix/abc", current_run=12)


class ReviewReuseTests(unittest.TestCase):
    def test_same_sha_base_and_pr_completed_success_is_reused(self):
        self.assertTrue(allowed(REFERENCE))

    def test_never_reuse_on_wrong_sha_base_or_pr(self):
        for path, bad in [
            (("head_sha",), "c" * 40),
            (("head_branch",), "fix/else"),
            (("pull_requests", 0, "number"), 124),
            (("pull_requests", 0, "head", "sha"), "c" * 40),
            (("pull_requests", 0, "base", "sha"), "c" * 40),
        ]:
            changed = copy.deepcopy(REFERENCE)
            node = changed["workflow_runs"][0]
            for key in path[:-1]:
                node = node[key]
            node[path[-1]] = bad
            with self.subTest(path=path):
                self.assertFalse(allowed(changed))

    def test_review_run_and_skipped_cancelled_running_are_not_reusable(self):
        for key, bad in [("event", "pull_request_review"), ("status", "in_progress"),
                         ("conclusion", "cancelled"), ("conclusion", "skipped"),
                         ("path", "other.yml"), ("name", "unrelated")]:
            changed = copy.deepcopy(REFERENCE)
            changed["workflow_runs"][0][key] = bad
            with self.subTest(key=key, bad=bad):
                self.assertFalse(allowed(changed))

    def test_future_or_self_run_cannot_be_reused(self):
        for run_id in (12, 13, -1, True):
            changed = copy.deepcopy(REFERENCE)
            changed["workflow_runs"][0]["id"] = run_id
            self.assertFalse(allowed(changed))

    def test_empty_invalid_or_unavailable_api_fails_closed(self):
        for payload in (None, {}, {"workflow_runs": None}, {"workflow_runs": {}},
                        {"workflow_runs": [None]}, {"workflow_runs": []}):
            self.assertFalse(allowed(payload))
        self.assertFalse(module.can_reuse(REFERENCE, pr=123, head="not-sha", base=BASE,
                                         head_ref="fix/abc", current_run=12))

    def test_reuse_requires_trusted_base_script_and_keeps_review_policy(self):
        workflow = (ROOT / ".github" / "workflows" / "mandatory-validation-gate.yml").read_text()
        self.assertIn("trusted-review/scripts/ci_review_reuse_gate.py", workflow)
        self.assertIn("ref: $"+"{{ github.event.pull_request.base.sha }}", workflow)
        self.assertIn("cancel-in-progress: $"+"{{ github.event_name != 'pull_request_review' }}", workflow)
        self.assertIn("review_code_validation_reuse", FILE.read_text())
        self.assertIn("python3 scripts/enforce-autonomy-policy.py", workflow)
        self.assertIn("Validate current PR history lineage", workflow)
        self.assertIn("Certify universal mandatory agent checklist gate", workflow)
        self.assertIn("steps.impact.outputs.mode != 'review-reuse'", workflow)


if __name__ == "__main__":
    unittest.main()
