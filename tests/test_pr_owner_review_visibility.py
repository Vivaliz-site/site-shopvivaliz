"""Regression: PR visibility never implies requesting every human approval."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/pr-conflict-auto-healer.yml"
REVIEW_GATE = ROOT / ".github/workflows/mandatory-validation-gate.yml"


class ReviewVisibilityTests(unittest.TestCase):
    def test_conflict_preflight_is_read_only_and_does_not_spam_human(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        preflight = text.split("  preflight:", 1)[1].split("  heal:", 1)[0]
        self.assertIn("pull-requests: read", preflight)
        self.assertNotIn("pull-requests: write", preflight)
        self.assertNotIn("requested_reviewers", preflight)
        self.assertNotIn("reviewers[]=shopvivaliz-human", preflight)
        self.assertNotIn("Request human owner review on every ready PR", text)

    def test_conflict_healer_continues_only_after_mergeability_detection(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("Detect whether Oracle conflict healing is actually needed", text)
        self.assertIn("should_heal", text)
        self.assertIn("needs: preflight", text)
        self.assertIn("needs.preflight.outputs.should_heal", text)

    def test_review_event_still_checks_approval_without_restarting_code(self):
        gate = REVIEW_GATE.read_text(encoding="utf-8")
        self.assertIn("python3 scripts/enforce-autonomy-policy.py", gate)
        self.assertIn("pull_request_review:", gate)
        self.assertIn("cancel-in-progress: \${{ github.event_name != 'pull_request_review' }}", gate)


if __name__ == "__main__":
    unittest.main()
