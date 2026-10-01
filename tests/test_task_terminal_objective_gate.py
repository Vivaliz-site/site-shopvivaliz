from __future__ import annotations

import hashlib
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

import agent_task_state as state  # noqa: E402


def fingerprint(payload: dict) -> str:
    basis = "\n".join(
        [
            str(payload.get("repository", state.DEFAULT_REPOSITORY)).strip(),
            str(payload.get("task_id", "")).strip(),
            str(payload.get("updated_at", "")).strip(),
            str(payload.get("next_action", "")).strip(),
        ]
    )
    return hashlib.sha256(basis.encode("utf-8")).hexdigest()


class ObjectiveTerminalGateTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.original_runtime = state.RUNTIME_DIR
        state.RUNTIME_DIR = Path(self.temp.name)

    def tearDown(self) -> None:
        state.RUNTIME_DIR = self.original_runtime
        self.temp.cleanup()

    def _set_checks(self, task_id: str, checks: list[list[str]]) -> None:
        path = state.RUNTIME_DIR / f"{task_id}.json"
        payload = json.loads(path.read_text(encoding="utf-8"))
        payload["completion_checks"] = checks
        path.write_text(json.dumps(payload), encoding="utf-8")

    def _resume_env(self, current_fingerprint: str):
        return mock.patch.dict(
            os.environ,
            {
                "SHOPVIVALIZ_RESUME_BACKGROUND": "1",
                "SHOPVIVALIZ_RESUME_FINGERPRINT": current_fingerprint,
            },
            clear=False,
        )

    def test_stale_detached_resume_cannot_mark_ready_after_checkpoint_changes(self) -> None:
        original = state.start_task("stale-ready", "prove stale resume fencing", "gpt")
        old_fingerprint = fingerprint(original)
        state.record_progress(
            "stale-ready",
            next_action="newer work superseded old executor",
            evidence="checkpoint advanced after detached executor started",
        )

        with self._resume_env(old_fingerprint):
            with self.assertRaisesRegex(state.TaskStateError, "stale detached resume"):
                state.mark_ready(
                    "stale-ready",
                    evidence=["old executor claims done"],
                    verification="must be rejected",
                )

        self.assertEqual(state.load_task("stale-ready")["status"], "RUNNING")

    def test_stale_detached_resume_cannot_complete_ready_state_created_elsewhere(self) -> None:
        original = state.start_task("stale-complete", "fence terminal transition", "gpt")
        old_fingerprint = fingerprint(original)
        state.mark_ready(
            "stale-complete",
            evidence=["interactive verifier completed newer work"],
            verification="interactive verification",
        )

        with self._resume_env(old_fingerprint):
            with self.assertRaisesRegex(state.TaskStateError, "stale detached resume"):
                state.complete_task("stale-complete")

        self.assertEqual(state.load_task("stale-complete")["status"], "READY_TO_COMPLETE")

    def test_current_detached_resume_can_ready_then_complete_with_provenance(self) -> None:
        original = state.start_task("current-resume", "allow current resume", "gpt")
        current_fingerprint = fingerprint(original)

        with self._resume_env(current_fingerprint):
            ready = state.mark_ready(
                "current-resume",
                evidence=["fresh objective evidence"],
                verification="fresh verification",
            )
            self.assertEqual(ready["status"], "READY_TO_COMPLETE")
            self.assertEqual(ready["ready_resume_fingerprint"], current_fingerprint)

            completed = state.complete_task("current-resume")

        self.assertEqual(completed["status"], "CONCLUIDO")

    def test_failing_completion_check_keeps_task_running(self) -> None:
        state.start_task("check-fails", "enforce objective completion check", "gpt")
        self._set_checks(
            "check-fails",
            [[sys.executable, "-c", "raise SystemExit(7)"]],
        )

        with self.assertRaisesRegex(state.TaskStateError, "completion check 1 failed"):
            state.mark_ready(
                "check-fails",
                evidence=["prose alone is insufficient"],
                verification="must not bypass failing check",
            )

        payload = state.load_task("check-fails")
        self.assertEqual(payload["status"], "RUNNING")
        self.assertNotIn("completion_checks_receipt", payload)

    def test_passing_completion_check_is_receipted_and_reverified_on_complete(self) -> None:
        state.start_task("check-passes", "require objective completion proof", "gpt")
        self._set_checks(
            "check-passes",
            [[sys.executable, "-c", "raise SystemExit(0)"]],
        )

        ready = state.mark_ready(
            "check-passes",
            evidence=["objective check passed"],
            verification="completion check executed",
        )
        receipt = ready.get("completion_checks_receipt") or {}
        self.assertEqual(receipt.get("count"), 1)
        self.assertTrue(receipt.get("digest"))
        self.assertTrue(receipt.get("passed_at"))

        completed = state.complete_task("check-passes")
        self.assertEqual(completed["status"], "CONCLUIDO")
        final_receipt = completed.get("completion_checks_receipt") or {}
        self.assertEqual(final_receipt.get("count"), 1)
        self.assertTrue(final_receipt.get("passed_at"))


if __name__ == "__main__":
    unittest.main()
