"""Security regression: an empty GitHub files page fails closed unless the PR itself reports zero changes."""
import importlib.util
from pathlib import Path
import unittest

PATH = Path(__file__).resolve().parents[1] / "scripts/quality/verify_owner_protected_pr_review.py"
SPEC = importlib.util.spec_from_file_location("review_gate", PATH)
GATE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(GATE)

HEAD = "a" * 40


class ZeroFilesGateTests(unittest.TestCase):
    def test_merge_only_pr_with_zero_changes_passes(self):
        self.assertEqual(GATE.decide([], [], HEAD, 0), (True, "non_protected_pr"))

    def test_empty_page_without_zero_changed_files_fails_closed(self):
        self.assertEqual(GATE.decide([], [], HEAD)[0], False)
        self.assertEqual(GATE.decide([], [], HEAD, None)[0], False)
        self.assertEqual(GATE.decide([], [], HEAD, 3), (False, "pr_files_unavailable_or_incomplete"))

    def test_invalid_head_still_fails_for_zero_files(self):
        self.assertEqual(GATE.decide([], [], "x", 0), (False, "invalid_head_sha"))
