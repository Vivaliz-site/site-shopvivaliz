from __future__ import annotations

import json
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

from scripts import agent_task_state as state


class TaskContinuationWatchdogTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.runtime = Path(self.temp.name)
        self.original_runtime = state.RUNTIME_DIR
        state.RUNTIME_DIR = self.runtime

    def tearDown(self) -> None:
        state.RUNTIME_DIR = self.original_runtime
        self.temp.cleanup()

    def _age_task(self, task_id: str, *, seconds: int) -> None:
        path = self.runtime / f"{task_id}.json"
        payload = json.loads(path.read_text(encoding="utf-8"))
        payload["updated_at"] = (
            datetime.now(timezone.utc) - timedelta(seconds=seconds)
        ).replace(microsecond=0).isoformat().replace("+00:00", "Z")
        path.write_text(json.dumps(payload), encoding="utf-8")

    def test_stale_running_task_dispatches_once_per_checkpoint_revision(self) -> None:
        from scripts import task_continuation_watchdog as watchdog

        state.start_task("task-a", "continuar ate concluir", "gpt")
        state.record_progress(
            "task-a",
            next_action="executar a proxima validacao",
            evidence="checkpoint persistido",
        )
        self._age_task("task-a", seconds=600)

        first = watchdog.run_once(stale_seconds=120, runtime_dir=self.runtime)
        self.assertEqual(first["dispatched"], 1)
        self.assertEqual(first["eligible"], 1)

        requests = watchdog.read_requests(self.runtime)
        self.assertEqual(len(requests), 1)
        self.assertEqual(requests[0]["task_id"], "task-a")
        self.assertEqual(requests[0]["agent_id"], "gpt")
        self.assertEqual(requests[0]["next_action"], "executar a proxima validacao")
        self.assertEqual(requests[0]["status"], "queued")

        second = watchdog.run_once(stale_seconds=120, runtime_dir=self.runtime)
        self.assertEqual(second["dispatched"], 0)
        self.assertEqual(len(watchdog.read_requests(self.runtime)), 1)

        state.record_progress(
            "task-a",
            next_action="executar validacao final",
            evidence="novo checkpoint",
        )
        self._age_task("task-a", seconds=600)
        third = watchdog.run_once(stale_seconds=120, runtime_dir=self.runtime)
        self.assertEqual(third["dispatched"], 1)
        self.assertEqual(len(watchdog.read_requests(self.runtime)), 2)

    def test_fresh_or_terminal_tasks_do_not_dispatch(self) -> None:
        from scripts import task_continuation_watchdog as watchdog

        state.start_task("fresh", "tarefa fresca", "gpt")
        state.record_progress("fresh", next_action="continuar agora")

        state.start_task("done", "tarefa pronta", "gpt")
        state.mark_ready(
            "done",
            evidence=["teste PASS"],
            verification="objetivo validado",
        )
        state.complete_task("done")
        self._age_task("done", seconds=600)

        result = watchdog.run_once(stale_seconds=120, runtime_dir=self.runtime)
        self.assertEqual(result["eligible"], 0)
        self.assertEqual(result["dispatched"], 0)

    def test_watchdog_is_deterministic_and_does_not_invoke_paid_ai(self) -> None:
        root = Path(__file__).resolve().parents[1]
        script = (root / "scripts" / "task_continuation_watchdog.py").read_text(encoding="utf-8")
        lowered = script.lower()
        self.assertNotIn("anthropics/claude-code-action", lowered)
        self.assertNotIn("api.openai.com", lowered)
        self.assertNotIn("codex exec", lowered)
        self.assertNotIn("subprocess.run", lowered)

    def test_autonomous_loop_runs_watchdog_before_operations_worker(self) -> None:
        root = Path(__file__).resolve().parents[1]
        loop = (root / "scripts" / "autonomous-agent-loop.sh").read_text(encoding="utf-8")
        watchdog_pos = loop.index("task_continuation_watchdog.py")
        worker_pos = loop.index("agent-operations-worker.py")
        self.assertLess(watchdog_pos, worker_pos)


if __name__ == "__main__":
    unittest.main()
