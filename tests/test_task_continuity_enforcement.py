from __future__ import annotations

import copy
import importlib.util
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

import agent_task_state as state  # noqa: E402



def load_operations_worker():
    path = ROOT / "scripts" / "agent-operations-worker.py"
    spec = importlib.util.spec_from_file_location("agent_operations_worker_test", path)
    if spec is None or spec.loader is None:
        raise RuntimeError("cannot load agent operations worker")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class AgentTaskStateTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.original_runtime = state.RUNTIME_DIR
        state.RUNTIME_DIR = Path(self.temp.name)

    def tearDown(self) -> None:
        state.RUNTIME_DIR = self.original_runtime
        self.temp.cleanup()

    def test_running_task_cannot_be_completed_before_ready_gate(self) -> None:
        current = state.start_task("task-simple", "Corrigir uma tarefa simples", "gpt")
        self.assertEqual(current["status"], "RUNNING")
        self.assertFalse(state.is_terminal(current))
        self.assertTrue(current["next_action"])

        with self.assertRaises(state.TaskStateError):
            state.complete_task("task-simple")

        ready = state.mark_ready(
            "task-simple",
            evidence=["teste focal PASS"],
            verification="pedido original confrontado com estado final",
        )
        self.assertEqual(ready["status"], "READY_TO_COMPLETE")
        self.assertEqual(ready["next_action"], "")

        completed = state.complete_task("task-simple")
        self.assertEqual(completed["status"], "CONCLUIDO")
        self.assertTrue(state.is_terminal(completed))

    def test_block_requires_external_impediment_and_exhausted_alternatives(self) -> None:
        state.start_task("task-block", "Resolver ate bloqueio real", "gpt")

        with self.assertRaises(state.TaskStateError):
            state.block_task(
                "task-block",
                blocker={"external": False, "description": "ferramenta falhou"},
                evidence=["tool error"],
                alternatives=["retry com a mesma ferramenta", "rota alternativa"],
                resume_condition="ferramenta voltar",
            )

        with self.assertRaises(state.TaskStateError):
            state.block_task(
                "task-block",
                blocker={"external": True, "description": "servico externo indisponivel"},
                evidence=["status oficial indisponivel"],
                alternatives=["uma unica tentativa"],
                resume_condition="servico externo voltar",
            )

        blocked = state.block_task(
            "task-block",
            blocker={"external": True, "description": "servico externo indisponivel"},
            evidence=["status oficial indisponivel"],
            alternatives=["rota primaria falhou", "rota secundaria falhou"],
            resume_condition="servico externo voltar",
        )
        self.assertEqual(blocked["status"], "BLOCKED_EXTERNAL")
        self.assertTrue(state.is_terminal(blocked))

    def test_progress_keeps_task_non_terminal_and_persists_next_action(self) -> None:
        state.start_task("task-progress", "Continuar sem abandono", "claude")
        current = state.record_progress(
            "task-progress",
            next_action="executar a proxima validacao",
            evidence="diagnostico concluido",
        )
        self.assertEqual(current["status"], "RUNNING")
        self.assertEqual(current["next_action"], "executar a proxima validacao")
        self.assertFalse(state.is_terminal(current))


    def test_explicit_codex_authorization_is_persisted_in_durable_state(self) -> None:
        state.start_task("task-codex-auth", "Continuar com Codex autorizado", "gpt")
        current = state.authorize_executor(
            "task-codex-auth",
            executor="codex",
            evidence="usuario autorizou Codex explicitamente nesta conversa",
        )
        self.assertEqual(current["human_authorized_executors"], ["codex"])
        self.assertEqual(current["human_authorizations"][-1]["executor"], "codex")
        self.assertIn("usuario autorizou Codex", current["human_authorizations"][-1]["evidence"])
        self.assertEqual(current["history"][-1]["event"], "executor_authorized")

    def test_unknown_background_executor_cannot_be_human_authorized(self) -> None:
        state.start_task("task-bad-auth", "Nao ampliar autorizacao", "gpt")
        with self.assertRaises(state.TaskStateError):
            state.authorize_executor(
                "task-bad-auth",
                executor="anthropic",
                evidence="nao autorizado nesta conversa",
            )

    def test_runtime_state_uses_shared_directory_inside_immutable_deploy(self) -> None:
        deploy_root = Path("/home/ubuntu/shopvivaliz-deploy/releases/20260926-170000-abc")
        resolved = state.resolve_runtime_dir(deploy_root, configured="")
        self.assertEqual(
            resolved,
            Path("/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state"),
        )

        local_root = Path("/tmp/site-shopvivaliz")
        local = state.resolve_runtime_dir(local_root, configured="")
        self.assertEqual(local, local_root / "storage" / "private" / "agent-task-state")


class OperationsWorkerContinuityTests(unittest.TestCase):
    def test_pending_task_gets_owner_even_when_docs_preflight_is_not_ready(self) -> None:
        worker = load_operations_worker()
        queue = {
            "queue": [
                {
                    "id": "task-preflight",
                    "title": "Tarefa simples",
                    "description": "Executar sem abandono",
                    "status": "pending",
                    "priority": "medium",
                }
            ]
        }
        saved = []
        worker.load_queue = lambda: queue
        worker.save_queue = lambda value, runtime_actor=None: saved.append(copy.deepcopy(value))
        worker.choose_agent = lambda task: "gpt"
        worker.docs_preflight = lambda agent_id, task: (False, "missing docs receipt")
        worker.push_step = lambda *args, **kwargs: None
        worker.persist_task_continuity = lambda *args, **kwargs: None

        assigned = worker.assign_pending_tasks({"agents": {}})

        self.assertEqual(queue["queue"][0]["assigned_to"], ["gpt"])
        self.assertEqual(queue["queue"][0]["execution_phase"], "docs_preflight")
        self.assertEqual(assigned[0]["agent_id"], "gpt")
        self.assertEqual(assigned[0]["phase"], "docs_preflight")
        self.assertTrue(saved, "ownership must be persisted even before docs receipt")


class TaskContinuityPolicyTests(unittest.TestCase):
    def test_normative_entrypoints_override_generic_pause_gates(self) -> None:
        marker = "TASK_CONTINUITY_ENFORCEMENT_V3"
        paths = (
            ROOT / "AGENTS.md",
            ROOT / "AI-TO-CLI-PROTOCOL.md",
            ROOT / "docs" / "knowledge" / "agent-rules.md",
            ROOT / "CLAUDE.md",
            ROOT / "GEMINI.md",
            ROOT / ".github" / "copilot-instructions.md",
        )
        for path in paths:
            text = path.read_text(encoding="utf-8")
            self.assertIn(marker, text, str(path))
            self.assertIn("BLOCKED_EXTERNAL", text, str(path))

    def test_governance_executes_continuity_validator(self) -> None:
        governance = (ROOT / "scripts" / "repository-governance-validate.sh").read_text(encoding="utf-8")
        self.assertIn("python3 scripts/validate-task-continuity-enforcement.py", governance)


    def test_operations_worker_persists_nonterminal_continuation(self) -> None:
        worker = (ROOT / "scripts" / "agent-operations-worker.py").read_text(encoding="utf-8")
        self.assertIn("from agent_task_state import", worker)
        self.assertIn("persist_task_continuity", worker)
        self.assertIn("next_action=", worker)


    def test_pr_feedback_workflows_cancel_superseded_runs(self) -> None:
        workflows = (
            ROOT / ".github" / "workflows" / "mandatory-validation-gate.yml",
            ROOT / ".github" / "workflows" / "history-integrity.yml",
            ROOT / ".github" / "workflows" / "agents-hourly-deep-audit.yml",
        )
        for workflow in workflows:
            text = workflow.read_text(encoding="utf-8")
            self.assertIn("concurrency:", text, str(workflow))
            self.assertIn("cancel-in-progress: true", text, str(workflow))



if __name__ == "__main__":
    unittest.main()
