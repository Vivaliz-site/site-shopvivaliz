import json
import tempfile
import unittest
from pathlib import Path
from scripts import chatgpt_continuity_proof_certifier as certifier


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


if __name__ == "__main__":
    unittest.main()
