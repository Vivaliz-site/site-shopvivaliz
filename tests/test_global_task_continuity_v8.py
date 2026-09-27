from __future__ import annotations

import json
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

from scripts import agent_task_state as state
from scripts import task_continuation_watchdog as watchdog
from scripts import task_resume_dispatcher as dispatcher


GOVERNED = {
    "Vivaliz-site/site-shopvivaliz",
    "Vivaliz-site/-shopvivaliz-pipeline",
    "Vivaliz-site/amazon-returns-safet",
    "Vivaliz-site/ml-pricing-api",
    "Vivaliz-site/mercadolivre-returns-recovery",
    "Vivaliz-site/shopvivaliz-m365",
    "Vivaliz-site/buscador",
    "fredmourao-ai/mei-mg-email",
    "fredmourao-ai/solange-rolla-consultorio",
}


class GlobalTaskContinuityV8Tests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.runtime = Path(self.temp.name)
        self.original_runtime = state.RUNTIME_DIR
        state.RUNTIME_DIR = self.runtime

    def tearDown(self) -> None:
        state.RUNTIME_DIR = self.original_runtime
        self.temp.cleanup()

    def test_checkpoint_persists_repository_identity(self) -> None:
        payload = state.start_task(
            "cross-repo-task",
            "finish the governed repository task",
            "claude",
            "Vivaliz-site/amazon-returns-safet",
        )
        self.assertEqual(payload["repository"], "Vivaliz-site/amazon-returns-safet")
        stored = json.loads((self.runtime / "cross-repo-task.json").read_text())
        self.assertEqual(stored["repository"], "Vivaliz-site/amazon-returns-safet")

    def test_checkpoint_rejects_unqualified_repository_identity(self) -> None:
        with self.assertRaises(state.TaskStateError):
            state.start_task("bad-repo", "x", "gpt", "not-a-repository")

    def test_watchdog_preserves_repository_in_resume_request(self) -> None:
        state.start_task(
            "cross-repo-watchdog",
            "recover me",
            "gpt",
            "fredmourao-ai/mei-mg-email",
        )
        state.record_progress("cross-repo-watchdog", next_action="run the next safe step")
        path = self.runtime / "cross-repo-watchdog.json"
        payload = json.loads(path.read_text())
        payload["updated_at"] = (
            datetime.now(timezone.utc) - timedelta(seconds=600)
        ).replace(microsecond=0).isoformat().replace("+00:00", "Z")
        path.write_text(json.dumps(payload))
        result = watchdog.run_once(stale_seconds=120, runtime_dir=self.runtime)
        self.assertEqual(result["dispatched"], 1)
        request = watchdog.read_requests(self.runtime)[0]
        self.assertEqual(request["repository"], "fredmourao-ai/mei-mg-email")

    def test_dispatcher_allowlist_is_exactly_the_governed_set(self) -> None:
        self.assertEqual(dispatcher.ALLOWED_REPOSITORIES, GOVERNED)
        for repository in GOVERNED:
            self.assertEqual(dispatcher._safe_repository(repository), repository)
        with self.assertRaises(ValueError):
            dispatcher._safe_repository("someone/unmanaged-repo")

    def test_dispatcher_rejects_cross_repository_request_mismatch(self) -> None:
        checkpoint = {
            "task_id": "same-task",
            "repository": "Vivaliz-site/buscador",
            "status": "RUNNING",
            "updated_at": "2026-09-27T12:00:00Z",
            "next_action": "continue",
        }
        request = {
            "task_id": "same-task",
            "repository": "Vivaliz-site/amazon-returns-safet",
            "status": "queued",
            "checkpoint_updated_at": checkpoint["updated_at"],
            "next_action": checkpoint["next_action"],
            "fingerprint": "abc",
        }
        self.assertFalse(dispatcher._request_matches_state(request, checkpoint))

    def test_dispatcher_uses_authenticated_gh_clone_and_canonical_executor(self) -> None:
        source = (Path(__file__).resolve().parents[1] / "scripts" / "task_resume_dispatcher.py").read_text()
        self.assertIn('"repo",\n            "clone"', source)
        self.assertIn('project_dir / "scripts" / "autonomous-provider-failover.sh"', source)
        self.assertNotIn("DEFAULT_REPOSITORY_URL", source)

        failover = (Path(__file__).resolve().parents[1] / "scripts" / "autonomous-provider-failover.sh").read_text()
        self.assertIn("SCRIPT_DIR=", failover)
        self.assertIn('python3 "$SCRIPT_DIR/run_background_gemini.py"', failover)

    def test_production_e2e_accepts_and_reports_repository(self) -> None:
        root = Path(__file__).resolve().parents[1]
        probe = (root / "scripts" / "task_continuity_e2e.py").read_text()
        workflow = (root / ".github" / "workflows" / "task-continuity-production-e2e.yml").read_text()
        self.assertIn("--repository", probe)
        self.assertIn('"repository": repository', probe)
        self.assertIn("TARGET_REPOSITORY", workflow)
        self.assertIn("--repository", workflow)


if __name__ == "__main__":
    unittest.main()
