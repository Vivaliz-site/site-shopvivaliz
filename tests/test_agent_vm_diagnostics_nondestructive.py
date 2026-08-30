import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/agent-vm-readonly-diagnostics.yml"


class AgentVmDiagnosticsNondestructiveTests(unittest.TestCase):
    def test_remote_route_never_destructively_resets_repository(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertNotIn("git reset --hard", text)
        self.assertNotIn("git clean -", text)
        self.assertNotIn("git stash", text)

    def test_checkout_patch_fails_closed_unless_file_matches_origin_main(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("git rev-parse origin/main:checkout.php", text)
        self.assertIn("git hash-object checkout.php", text)
        self.assertIn("CHECKOUT_BASE_MISMATCH", text)


if __name__ == "__main__":
    unittest.main()
