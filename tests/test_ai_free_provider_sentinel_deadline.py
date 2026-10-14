"""A hung provider scan must never masquerade as a fresh healthy check."""
import importlib.util
import json
from pathlib import Path
import tempfile
import time
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("guard_deadline", ROOT / "scripts/ai-free-provider-sentinel.py")
assert spec and spec.loader
s = importlib.util.module_from_spec(spec)
spec.loader.exec_module(s)


class DeadlineTests(unittest.TestCase):
    def prior(self, root: Path):
        p = root / "state.json"
        state = {
            "last_checked_epoch": 101,
            "last_status": "HEALTHY",
            "last_issue_codes": [],
            "codex_counters": {"saved": [777, 5]},
            "codex_fingerprints": {"saved": "readonly"},
            "claude_scan_since_epoch": 99,
            "notified_fingerprint": "",
            "notified_epoch": 0,
        }
        s.store_state(p, state)
        return state

    def test_forced_timeout_alerts_without_advancing_counters(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            before = self.prior(root)
            with patch.object(s, "run", side_effect=s.SentinelRunDeadline("fixture")), patch.object(s, "github_comment") as notify:
                issues, _ = s.run_guarded(state_dir=root, do_notify=False, budget_seconds=1)
            latest = s.load_state(root / "state.json")
            self.assertIn("SENTINEL_RUN_TIME_BUDGET_EXCEEDED", issues)
            self.assertEqual(latest["last_status"], "ALERT")
            self.assertEqual(latest["last_checked_epoch"], before["last_checked_epoch"])
            self.assertEqual(latest["codex_counters"], before["codex_counters"])
            self.assertEqual(latest["codex_fingerprints"], before["codex_fingerprints"])
            self.assertEqual(latest["claude_scan_since_epoch"], before["claude_scan_since_epoch"])
            self.assertGreater(latest["last_failed_epoch"], before["last_checked_epoch"])
            notify.assert_not_called()

    def test_actual_wall_timer_kills_stuck_python_probe(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            self.prior(root)
            before = time.monotonic()
            with patch.object(s, "run", side_effect=lambda **_kw: time.sleep(2)):
                issues, _ = s.run_guarded(state_dir=root, do_notify=False, budget_seconds=0.04)
            self.assertLess(time.monotonic() - before, 0.8)
            self.assertIn("SENTINEL_RUN_TIME_BUDGET_EXCEEDED", issues)

    def test_external_kill_or_exception_never_leaves_previous_healthy(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            self.prior(root)
            with patch.object(s, "run", side_effect=RuntimeError("simulated probe failure")):
                with self.assertRaises(RuntimeError):
                    s.run_guarded(state_dir=root, do_notify=False, budget_seconds=1)
            state = s.load_state(root / "state.json")
            self.assertEqual(state["last_status"], "CHECKING")
            self.assertIn("SENTINEL_CHECK_IN_PROGRESS", state["last_issue_codes"])
            self.assertEqual(state["last_checked_epoch"], 101)

    def test_success_proves_checking_first_then_healthy(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            self.prior(root)
            def healthy_run(*, state_dir: Path, do_notify: bool):
                p = state_dir / "state.json"
                status = s.load_state(p)
                self.assertEqual(status["last_status"], "CHECKING")
                self.assertEqual(status["last_checked_epoch"], 101)
                status["last_status"] = "HEALTHY"
                status["last_issue_codes"] = []
                status["last_checked_epoch"] = int(time.time())
                s.store_state(p, status)
                return [], "ok"
            with patch.object(s, "run", side_effect=healthy_run):
                issues, message = s.run_guarded(state_dir=root, do_notify=False, budget_seconds=1)
            self.assertEqual(issues, [])
            self.assertEqual(message, "ok")
            self.assertEqual(s.load_state(root / "state.json")["last_status"], "HEALTHY")

    def test_deadline_alert_attempt_is_notified_once_only_if_successful(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            self.prior(root)
            with patch.object(s, "run", side_effect=s.SentinelRunDeadline("fixture")), patch.object(s, "github_comment", return_value=True) as notify:
                s.run_guarded(state_dir=root, do_notify=True, budget_seconds=1)
                self.assertEqual(notify.call_count, 1)
            latest = s.load_state(root / "state.json")
            self.assertTrue(latest["notified_fingerprint"])
            self.assertGreater(latest["notified_epoch"], 101)

if __name__ == "__main__":
    unittest.main()
