"""Regression tests for exact, atomic ChatGPT conversation route recovery."""
from __future__ import annotations

import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from scripts import agent_task_state as state
from scripts.continuity import route_reconciler
from scripts.task_resume_queue import checkpoint_fingerprint

CID1 = "6ac0f8b7-f2f0-83e9-95c5-54be614b9dee"
CID2 = "6ac0f8b7-f2f0-83e9-95c5-54be614b9def"


class ConversationRouteRecoveryTests(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.runtime = Path(self.tmp.name)
        self.patcher = patch.object(state, "RUNTIME_DIR", self.runtime)
        self.patcher.start()
        self.env = patch.dict(os.environ, {
            "SHOPVIVALIZ_AGENT_TASK_STATE_DIR": str(self.runtime),
        })
        self.env.start()

    def tearDown(self) -> None:
        self.env.stop()
        self.patcher.stop()
        self.tmp.cleanup()

    def _start(self, task: str = "route-proof"):
        return state.start_task(task, "Resume the original task", "chatgpt", "Vivaliz-site/site-shopvivaliz")

    def _proof(self, task: str, payload: dict, cid: str, session: str = "dev", fingerprint: str | None = None) -> None:
        row = {
            "task_id": task, "worker_status": "PROGRESS_CONFIRMED",
            "fingerprint": fingerprint or checkpoint_fingerprint(payload),
            "conversation_id": cid,
        }
        if session:
            row["browser_session"] = session
        with (self.runtime / "_chatgpt-continuity-nudges.jsonl").open("a", encoding="utf-8") as out:
            out.write(json.dumps(row) + "\n")

    def test_atomic_route_binding_is_idempotent_and_not_task_progress(self) -> None:
        started = self._start()
        fingerprint = checkpoint_fingerprint(started)
        bound = state.bind_route("route-proof", conversation_id=CID1, browser_session="dev",
                                 expected_checkpoint_version=started["checkpoint_version"])
        self.assertEqual(bound["conversation_id"], CID1)
        self.assertEqual(bound["browser_session"], "dev")
        self.assertEqual(bound["updated_at"], started["updated_at"])
        self.assertEqual(checkpoint_fingerprint(bound), fingerprint)
        self.assertEqual(bound["checkpoint_version"], started["checkpoint_version"] + 1)
        repeated = state.bind_route("route-proof", conversation_id=CID1, browser_session="dev")
        self.assertEqual(repeated["checkpoint_version"], bound["checkpoint_version"])
        self.assertEqual(len(repeated["history"]), len(bound["history"]))

    def test_invalid_and_conflicting_route_never_mutates(self) -> None:
        original = self._start()
        with self.assertRaises(state.TaskStateError):
            state.bind_route("route-proof", conversation_id=CID1, browser_session="fred")
        with self.assertRaises(state.TaskStateError):
            state.bind_route("route-proof", conversation_id=CID1, browser_session="dev",
                             expected_checkpoint_version=999)
        self.assertEqual(state.load_task("route-proof"), original)
        state.bind_route("route-proof", conversation_id=CID1, browser_session="dev")
        with self.assertRaises(state.TaskStateError):
            state.bind_route("route-proof", conversation_id=CID2, browser_session="dev")
        with self.assertRaises(state.TaskStateError):
            state.bind_route("route-proof", conversation_id=CID1, browser_session="atendimento")
        self.assertEqual(state.load_task("route-proof")["conversation_id"], CID1)

    def test_start_inherits_only_explicit_foreground_route_environment(self) -> None:
        with patch.dict(os.environ, {
            "SHOPVIVALIZ_TASK_CONVERSATION_ID": CID1,
            "SHOPVIVALIZ_TASK_BROWSER_SESSION": "atendimento",
            "SHOPVIVALIZ_TASK_ROUTE_TASK_ID": "env-route",
        }):
            result = self._start("env-route")
        self.assertEqual(result["conversation_id"], CID1)
        self.assertEqual(result["browser_session"], "atendimento")

    def test_stale_foreground_environment_cannot_bind_another_task(self) -> None:
        with patch.dict(os.environ, {
            "SHOPVIVALIZ_TASK_CONVERSATION_ID": CID1,
            "SHOPVIVALIZ_TASK_BROWSER_SESSION": "dev",
            "SHOPVIVALIZ_TASK_ROUTE_TASK_ID": "another-task",
        }):
            with self.assertRaises(state.TaskStateError):
                self._start("env-route")
        self.assertFalse((self.runtime / "env-route.json").exists())

    def test_reconcile_only_exact_confirmed_matching_fingerprint(self) -> None:
        task = "route-proof"
        original = self._start(task)
        self._proof(task, original, CID1, "dev")
        summary = route_reconciler.reconcile(self.runtime)
        self.assertEqual(summary["bound"], 1)
        self.assertEqual(summary["failed"], 0)
        self.assertEqual(state.load_task(task)["conversation_id"], CID1)
        self.assertEqual(state.load_task(task)["browser_session"], "dev")
        self.assertEqual(route_reconciler.reconcile(self.runtime)["bound"], 0)

    def test_stale_receipt_cannot_bind_new_checkpoint(self) -> None:
        original = self._start()
        self._proof("route-proof", original, CID1, "dev", fingerprint="stale-fingerprint")
        summary = route_reconciler.reconcile(self.runtime)
        self.assertEqual(summary["bound"], 0)
        self.assertEqual(summary["skipped_no_proof"], 1)
        self.assertNotIn("conversation_id", state.load_task("route-proof"))

    def test_ambiguous_receipts_fail_closed(self) -> None:
        original = self._start()
        self._proof("route-proof", original, CID1, "dev")
        self._proof("route-proof", original, CID2, "dev")
        summary = route_reconciler.reconcile(self.runtime)
        self.assertEqual(summary["bound"], 0)
        self.assertEqual(summary["ambiguous"], 1)
        self.assertNotIn("conversation_id", state.load_task("route-proof"))

    def test_unattested_session_cannot_be_guessed(self) -> None:
        original = self._start()
        self._proof("route-proof", original, CID1, session="")
        summary = route_reconciler.reconcile(self.runtime)
        self.assertEqual(summary["bound"], 0)
        self.assertNotIn("browser_session", state.load_task("route-proof"))

    def test_partial_route_repaired_without_changing_fingerprint(self) -> None:
        original = self._start()
        partial = state.bind_conversation("route-proof", conversation_id=CID1)
        self.assertEqual(partial["updated_at"], original["updated_at"])
        self._proof("route-proof", partial, CID1, "dev")
        summary = route_reconciler.reconcile(self.runtime)
        self.assertEqual(summary["bound"], 1)
        self.assertEqual(state.load_task("route-proof")["browser_session"], "dev")


if __name__ == "__main__":
    unittest.main()
