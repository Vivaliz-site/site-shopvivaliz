from __future__ import annotations

import importlib.util
import json
import sys
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

from scripts import agent_task_state as state

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))


def load_operations_worker():
    path = ROOT / "scripts" / "agent-operations-worker.py"
    spec = importlib.util.spec_from_file_location("continuation_worker_test", path)
    if spec is None or spec.loader is None:
        raise RuntimeError("cannot load operations worker")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module



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

    def test_operations_worker_consumes_resume_request_once(self) -> None:
        from scripts import task_continuation_watchdog as watchdog

        state.start_task("task-worker", "retomar automaticamente", "gpt")
        state.record_progress(
            "task-worker",
            next_action="executar validacao pendente",
            evidence="checkpoint antes da interrupcao",
        )
        self._age_task("task-worker", seconds=600)
        watchdog.run_once(stale_seconds=120, runtime_dir=self.runtime)

        worker = load_operations_worker()
        worker.TASK_STATE_DIR = self.runtime
        worker.INTERVENTIONS_FILE = self.runtime / "_agent-interventions.jsonl"
        worker.push_step = lambda *args, **kwargs: None

        runtime_state = {"agents": {}}
        first = worker.enqueue_continuation_requests(runtime_state)
        self.assertEqual(first, 1)

        interventions = worker.read_jsonl(worker.INTERVENTIONS_FILE)
        self.assertEqual(len(interventions), 1)
        self.assertEqual(interventions[0]["kind"], "auto-resume")
        self.assertEqual(interventions[0]["source"], "task-continuation-watchdog")
        self.assertEqual(interventions[0]["task_id"], "task-worker")
        self.assertIn("executar validacao pendente", interventions[0]["message"])

        second = worker.enqueue_continuation_requests(runtime_state)
        self.assertEqual(second, 0)
        self.assertEqual(len(worker.read_jsonl(worker.INTERVENTIONS_FILE)), 1)

    def test_stale_task_always_requests_chatgpt_first_even_if_previous_agent_was_cli(self) -> None:
        from scripts import task_continuation_watchdog as watchdog

        state.start_task("task-chatgpt-first", "retomar no ChatGPT", "claude")
        state.record_progress(
            "task-chatgpt-first",
            next_action="validar o gate pendente",
            evidence="checkpoint salvo antes da interrupcao",
        )
        self._age_task("task-chatgpt-first", seconds=600)

        watchdog.run_once(stale_seconds=120, runtime_dir=self.runtime)
        request = watchdog.read_requests(self.runtime)[0]

        self.assertEqual(request["agent_id"], "gpt")
        self.assertEqual(request["preferred_executor"], "chatgpt_common")
        self.assertEqual(request["secondary_executor"], "chatgpt_work")
        self.assertEqual(request["final_fallback"], "cli")
        self.assertEqual(request["executor_order"], ["chatgpt_common", "chatgpt_work", "cli"])
        self.assertEqual(request["previous_agent_id"], "claude")
        self.assertEqual(request["fallback_policy"], "chatgpt_common_then_work_then_cli")

    def test_watchdog_preserves_explicit_codex_authorization(self) -> None:
        from scripts import task_continuation_watchdog as watchdog

        state.start_task("task-codex-resume", "Continuar com Codex apos queda", "gpt")
        state.authorize_executor(
            "task-codex-resume",
            executor="codex",
            evidence="usuario autorizou Codex explicitamente nesta conversa",
        )
        state.record_progress(
            "task-codex-resume",
            next_action="continuar execucao com Codex autorizado",
            evidence="checkpoint antes da interrupcao",
        )
        self._age_task("task-codex-resume", seconds=600)

        watchdog.run_once(stale_seconds=120, runtime_dir=self.runtime)
        request = watchdog.read_requests(self.runtime)[0]
        self.assertEqual(request["human_authorized_executors"], ["codex"])

    def test_worker_routes_auto_resume_to_gpt_even_when_request_contains_previous_cli_agent(self) -> None:
        from scripts import task_continuation_watchdog as watchdog

        state.start_task("task-route-gpt", "retomar no ChatGPT", "gemini")
        state.record_progress("task-route-gpt", next_action="continuar validacao")
        self._age_task("task-route-gpt", seconds=600)
        watchdog.run_once(stale_seconds=120, runtime_dir=self.runtime)

        worker = load_operations_worker()
        worker.TASK_STATE_DIR = self.runtime
        worker.INTERVENTIONS_FILE = self.runtime / "_agent-interventions.jsonl"
        worker.push_step = lambda *args, **kwargs: None

        self.assertEqual(worker.enqueue_continuation_requests({"agents": {}}), 1)
        intervention = worker.read_jsonl(worker.INTERVENTIONS_FILE)[0]
        self.assertEqual(intervention["agent_id"], "gpt")
        self.assertEqual(intervention["preferred_executor"], "chatgpt_common")
        self.assertEqual(intervention["secondary_executor"], "chatgpt_work")
        self.assertEqual(intervention["final_fallback"], "cli")
        self.assertEqual(intervention["executor_order"], ["chatgpt_common", "chatgpt_work", "cli"])
        self.assertEqual(intervention["fallback_policy"], "chatgpt_common_then_work_then_cli")
        self.assertIn("ChatGPT comum", intervention["message"])
        self.assertIn("ChatGPT Work", intervention["message"])
        self.assertIn("CLI", intervention["message"])

    def test_cli_fallback_is_rejected_without_prior_chatgpt_tiers_exhausted(self) -> None:
        root = Path(__file__).resolve().parents[1]
        fallback = (root / "scripts" / "autonomous-provider-failover.sh").read_text(encoding="utf-8")
        self.assertIn('SHOPVIVALIZ_RESUME_STAGE', fallback)
        self.assertIn('cli_last', fallback)
        self.assertIn('chatgpt_common', fallback)
        self.assertIn('chatgpt_work', fallback)
        self.assertIn('exit 75', fallback)

    def test_docs_define_chatgpt_common_then_work_then_cli_order(self) -> None:
        root = Path(__file__).resolve().parents[1]
        docs = (root / "docs" / "knowledge" / "task-continuity.md").read_text(encoding="utf-8")
        self.assertIn("CHATGPT_RESUME_ORDER_V5", docs)
        self.assertIn("ChatGPT comum", docs)
        self.assertIn("ChatGPT Work", docs)
        self.assertIn("CLI", docs)

        validator = (root / "scripts" / "validate-task-continuity-enforcement.py").read_text(encoding="utf-8")
        self.assertIn("CHATGPT_RESUME_ORDER_V5", validator)
        self.assertIn("chatgpt_common_then_work_then_cli", validator)

    def test_operations_worker_ignores_superseded_resume_request(self) -> None:
        from scripts import task_continuation_watchdog as watchdog

        state.start_task("task-moved", "checkpoint mudou", "gpt")
        state.record_progress("task-moved", next_action="acao antiga")
        self._age_task("task-moved", seconds=600)
        watchdog.run_once(stale_seconds=120, runtime_dir=self.runtime)

        state.record_progress("task-moved", next_action="acao nova")

        worker = load_operations_worker()
        worker.TASK_STATE_DIR = self.runtime
        worker.INTERVENTIONS_FILE = self.runtime / "_agent-interventions.jsonl"
        worker.push_step = lambda *args, **kwargs: None

        self.assertEqual(worker.enqueue_continuation_requests({"agents": {}}), 0)
        self.assertEqual(worker.read_jsonl(worker.INTERVENTIONS_FILE), [])


    def test_continuity_validator_requires_auto_resume_components(self) -> None:
        root = Path(__file__).resolve().parents[1]
        validator = (root / "scripts" / "validate-task-continuity-enforcement.py").read_text(encoding="utf-8")
        self.assertIn("task_continuation_watchdog.py", validator)
        self.assertIn("tests.test_task_continuation_watchdog", validator)

        docs = (root / "docs" / "knowledge" / "task-continuity.md").read_text(encoding="utf-8")
        self.assertIn("TASK_CONTINUITY_AUTO_RESUME_V4", docs)
        self.assertIn("task_continuation_watchdog.py", docs)
        self.assertIn("120", docs)


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
