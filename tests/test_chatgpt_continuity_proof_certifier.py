import json
import tempfile
import unittest
from pathlib import Path
from scripts import chatgpt_continuity_proof_certifier as certifier
from scripts import agent_task_state as task_state


class ProofCertifierTest(unittest.TestCase):
    def test_false_terminal_is_rejected_and_exact_proof_is_accepted(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            task = "probe"
            cid = "11111111-2222-3333-4444-555555555555"
            state = {
                "task_id": task,
                "repository": "Vivaliz-site/site-shopvivaliz",
                "status": "CONCLUIDO",
                "verification": "continuity_e2e_pass",
                "conversation_id": cid,
                "history": [
                    {"at": "2026-10-02T08:00:00Z", "event": "progress", "next_action": "continue bound probe"},
                    {"at": "2026-10-02T08:03:00Z", "event": "completed"},
                ],
            }
            (root / f"{task}.json").write_text(json.dumps(state), encoding="utf-8")
            self.assertFalse(certifier.certify(root, task)["ok"])

            fp = certifier._fingerprint(state["repository"], task, state["history"][0]["at"], state["history"][0]["next_action"])
            row = {
                "task_id": task,
                "fingerprint": fp,
                "worker_status": "PROGRESS_CONFIRMED",
                "worker_status_observed_at": "2026-10-02T08:02:00Z",
                "conversation_id": cid,
                "send_attempt_count": 1,
            }
            (root / "_chatgpt-continuity-nudges.jsonl").write_text(json.dumps(row) + "\n", encoding="utf-8")
            self.assertTrue(certifier.certify(root, task)["ok"])


class CentralProofGateTest(unittest.TestCase):
    def test_continuity_e2e_ready_fails_closed_without_bound_browser_proof(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            original_runtime = task_state.RUNTIME_DIR
            task_state.RUNTIME_DIR = root
            try:
                task = "proof-gate"
                cid = "11111111-2222-3333-4444-555555555555"
                task_state.start_task(task, "verify continuity proof", "test")
                bound = task_state.bind_conversation(task, conversation_id=cid)

                with self.assertRaisesRegex(task_state.TaskStateError, "requires bound browser"):
                    task_state.mark_ready(
                        task,
                        evidence=["detached executor reached terminal"],
                        verification="continuity_e2e_pass",
                    )

                fp = certifier._fingerprint(
                    bound["repository"],
                    task,
                    bound["updated_at"],
                    bound["next_action"],
                )
                row = {
                    "task_id": task,
                    "fingerprint": fp,
                    "worker_status": "PROGRESS_CONFIRMED",
                    "worker_status_observed_at": "2026-10-02T08:45:00Z",
                    "conversation_id": cid,
                    "send_attempt_count": 1,
                }
                (root / certifier.NUDGE_LEDGER).write_text(
                    json.dumps(row) + "\n",
                    encoding="utf-8",
                )
                ready = task_state.mark_ready(
                    task,
                    evidence=["bound browser progress confirmed"],
                    verification="continuity_e2e_pass",
                )
                self.assertEqual(ready["status"], "READY_TO_COMPLETE")
            finally:
                task_state.RUNTIME_DIR = original_runtime


if __name__ == "__main__":
    unittest.main()
