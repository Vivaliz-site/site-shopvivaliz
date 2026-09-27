from __future__ import annotations

import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
HELPER = ROOT / "scripts" / "gemini_task_state_intent.py"


def load_helper():
    if not HELPER.is_file():
        raise AssertionError("scripts/gemini_task_state_intent.py is missing")
    spec = importlib.util.spec_from_file_location("gemini_task_state_intent_test", HELPER)
    if spec is None or spec.loader is None:
        raise RuntimeError("cannot load Gemini task-state intent helper")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class FakeStateApi:
    def __init__(self) -> None:
        self.calls: list[tuple] = []

    def mark_ready(self, task_id: str, *, evidence, verification: str):
        self.calls.append(("ready", task_id, list(evidence), verification))
        return {"status": "READY_TO_COMPLETE"}

    def complete_task(self, task_id: str):
        self.calls.append(("complete", task_id))
        return {"status": "CONCLUIDO"}

    def record_progress(self, task_id: str, *, next_action: str, evidence=None):
        self.calls.append(("progress", task_id, next_action, evidence))
        return {"status": "RUNNING"}


class GeminiTaskStateIntentTests(unittest.TestCase):
    def test_extracts_only_explicit_prefixed_json_intent(self) -> None:
        mod = load_helper()
        output = (
            "analysis line\n"
            'SHOPVIVALIZ_TASK_STATE_INTENT={"action":"complete","evidence":"detached_executor_ran","verification":"continuity_e2e_pass"}\n'
        )
        intent = mod.extract_intent(output)
        self.assertEqual(intent["action"], "complete")
        self.assertEqual(intent["evidence"], "detached_executor_ran")
        self.assertEqual(intent["verification"], "continuity_e2e_pass")

    def test_complete_intent_uses_ready_then_complete_for_current_task_only(self) -> None:
        mod = load_helper()
        api = FakeStateApi()
        result = mod.apply_intent(
            {
                "action": "complete",
                "evidence": "detached_executor_ran",
                "verification": "continuity_e2e_pass",
            },
            task_id="continuity-e2e-20260927",
            state_api=api,
        )
        self.assertEqual(result["status"], "CONCLUIDO")
        self.assertEqual(
            api.calls,
            [
                (
                    "ready",
                    "continuity-e2e-20260927",
                    ["detached_executor_ran"],
                    "continuity_e2e_pass",
                ),
                ("complete", "continuity-e2e-20260927"),
            ],
        )

    def test_progress_intent_requires_concrete_next_action_and_evidence(self) -> None:
        mod = load_helper()
        api = FakeStateApi()
        result = mod.apply_intent(
            {
                "action": "progress",
                "next_action": "run the next bounded verification",
                "evidence": "executor inspected the current checkpoint",
            },
            task_id="task-123",
            state_api=api,
        )
        self.assertEqual(result["status"], "RUNNING")
        self.assertEqual(
            api.calls,
            [
                (
                    "progress",
                    "task-123",
                    "run the next bounded verification",
                    "executor inspected the current checkpoint",
                )
            ],
        )

    def test_rejects_unknown_actions_extra_fields_and_missing_evidence(self) -> None:
        mod = load_helper()
        api = FakeStateApi()
        with self.assertRaises(mod.IntentError):
            mod.apply_intent({"action": "block"}, task_id="task-1", state_api=api)
        with self.assertRaises(mod.IntentError):
            mod.apply_intent(
                {
                    "action": "complete",
                    "evidence": "ok",
                    "verification": "ok",
                    "task_id": "other-task",
                },
                task_id="task-1",
                state_api=api,
            )
        with self.assertRaises(mod.IntentError):
            mod.apply_intent(
                {"action": "complete", "evidence": "", "verification": "verified"},
                task_id="task-1",
                state_api=api,
            )


if __name__ == "__main__":
    unittest.main()
