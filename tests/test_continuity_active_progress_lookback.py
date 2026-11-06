"""Regression: long-running checkpoints remain eligible after recent verified progress."""
import json
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

from scripts import gemini_24x7_controller as controller
from scripts import task_continuation_watchdog as watchdog
from scripts import task_resume_dispatcher as dispatcher
from scripts import chatgpt_continuity_nudge_dispatcher as nudge
from scripts.continuity import route_reconciler
from scripts.task_resume_queue import checkpoint_fingerprint


class ActiveCheckpointProgressTests(unittest.TestCase):
    @staticmethod
    def checkpoint(*, age_days=25, progress_days=0):
        now = datetime.now(timezone.utc)
        return {
            "task_id": "regression-long-lived-active",
            "agent_id": "chatgpt-test",
            "repository": "Vivaliz-site/site-shopvivaliz",
            "status": "RUNNING",
            "created_at": (now - timedelta(days=age_days)).isoformat(),
            "updated_at": (now - timedelta(days=progress_days)).isoformat(),
            "next_action": "Continue authorized audited task",
        }

    def test_controller_scans_old_created_recently_progressed(self):
        state = self.checkpoint()
        with tempfile.TemporaryDirectory() as tmp:
            Path(tmp, state["task_id"] + ".json").write_text(json.dumps(state))
            result = controller._completion_sweep(Path(tmp), durable_handoff_enabled=True)
        self.assertEqual(result["active"], 1)
        self.assertEqual(result["active_outside_lookback"], 0)
        self.assertEqual(result["unbound_active"], 1)

    def test_watchdog_does_not_discard_recent_progress_or_invent_route(self):
        state = self.checkpoint()
        with tempfile.TemporaryDirectory() as tmp:
            Path(tmp, state["task_id"] + ".json").write_text(json.dumps(state))
            result = watchdog.run_once(runtime_dir=Path(tmp), proactive=True)
        self.assertGreaterEqual(result["scanned"], 1)
        self.assertEqual(result["skipped_outside_lookback"], 0)
        self.assertEqual(result["skipped_unbound_origin"], 1)
        self.assertEqual(result["dispatched"], 0)

    def test_route_reconciler_inspects_but_does_not_guess_proof(self):
        state = self.checkpoint()
        with tempfile.TemporaryDirectory() as tmp:
            Path(tmp, state["task_id"] + ".json").write_text(json.dumps(state))
            result = route_reconciler.reconcile(Path(tmp))
        self.assertEqual(result["skipped_stale"], 0)
        self.assertEqual(result["skipped_no_proof"], 1)
        self.assertEqual(result["bound"], 0)

    def test_dispatcher_accepts_matching_recent_progress(self):
        state = self.checkpoint()
        request = {
            "task_id": state["task_id"], "repository": state["repository"],
            "checkpoint_updated_at": state["updated_at"],
            "next_action": state["next_action"], "fingerprint": "verified",
        }
        self.assertTrue(dispatcher._request_matches_state(request, state))

    def test_nudge_accepts_matching_recent_progress(self):
        state = self.checkpoint()
        request = {"task_id": state["task_id"], "repository": state["repository"],
                   "fingerprint": checkpoint_fingerprint(state)}
        with tempfile.TemporaryDirectory() as tmp:
            Path(tmp, state["task_id"] + ".json").write_text(json.dumps(state))
            self.assertTrue(nudge._request_matches_current_checkpoint(Path(tmp), request))

    def test_watchdog_skips_old_checkpoint_without_recent_progress(self):
        state = self.checkpoint(progress_days=24)
        with tempfile.TemporaryDirectory() as tmp:
            Path(tmp, state["task_id"] + ".json").write_text(json.dumps(state))
            result = watchdog.run_once(runtime_dir=Path(tmp), proactive=True)
        self.assertEqual(result["scanned"], 0)
        self.assertEqual(result["skipped_outside_lookback"], 1)
        self.assertEqual(result["dispatched"], 0)

    def test_stale_without_progress_still_reported(self):
        state = self.checkpoint(progress_days=24)
        with tempfile.TemporaryDirectory() as tmp:
            Path(tmp, state["task_id"] + ".json").write_text(json.dumps(state))
            result = controller._completion_sweep(Path(tmp), durable_handoff_enabled=True)
        self.assertEqual(result["active_outside_lookback"], 1)
        self.assertEqual(result["active"], 0)


if __name__ == "__main__":
    unittest.main()
