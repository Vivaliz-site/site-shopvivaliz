"""Regression checks for the fail-closed merge queue continuity suite selector."""
import os
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = (ROOT / "scripts/repository-governance-validate.sh").read_text()
FUNCTION = SCRIPT.split("continuity_suites_needed() {", 1)[1].split("\n}\n", 1)[0]


class MergeGroupContinuityScope(unittest.TestCase):
    def check_scope(self, event, base, changed, valid=True):
        # Mock git only inside the isolated subprocess; no repository mutations.
        stub = """git() {
          case "$1" in
            cat-file) [ "$MOCK_VALID" = yes ] ;;
            diff) printf "%s\\n" "$MOCK_CHANGED" ;;
            merge-base) echo abcdef ;;
          esac
        }
        """
        cmd = stub + "continuity_suites_needed() {" + FUNCTION + "\n}\ncontinuity_suites_needed"
        env = dict(os.environ, GITHUB_EVENT_NAME=event,
                   MERGE_GROUP_BASE_SHA=base, MOCK_CHANGED=changed,
                   MOCK_VALID="yes" if valid else "no")
        return subprocess.run(["bash", "-c", cmd], cwd=ROOT, env=env, capture_output=True).returncode

    def test_unrelated_queue_diff_skips_expensive_suites(self):
        self.assertNotEqual(self.check_scope("merge_group", "a" * 40, "api/cart/add.php"), 0)

    def test_related_queue_diff_runs_suites(self):
        self.assertEqual(self.check_scope("merge_group", "a" * 40, "scripts/chatgpt-continuity/bridge.py"), 0)

    def test_gate_change_runs_suites(self):
        self.assertEqual(self.check_scope("merge_group", "a" * 40, "scripts/repository-governance-validate.sh"), 0)

    def test_missing_base_fails_closed(self):
        self.assertEqual(self.check_scope("merge_group", "", "api/cart/add.php"), 0)

    def test_unverifiable_base_fails_closed(self):
        self.assertEqual(self.check_scope("merge_group", "a" * 40, "api/cart/add.php", False), 0)

    def test_push_keeps_full_validation(self):
        self.assertEqual(self.check_scope("push", "a" * 40, "api/cart/add.php"), 0)


if __name__ == "__main__":
    unittest.main()
