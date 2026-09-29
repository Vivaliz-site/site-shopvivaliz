from __future__ import annotations

import copy
import importlib.util
import sys
import tempfile
import unittest
from unittest import mock
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

    def test_privileged_atomic_write_inherits_runtime_directory_owner(self) -> None:
        runtime = Path(self.temp.name)
        target = runtime / "root-created.json"
        parent = runtime.stat()
        chown_calls: list[tuple[Path, int, int]] = []

        def record_chown(path, uid, gid):
            chown_calls.append((Path(path), uid, gid))

        with (
            mock.patch.object(state.os, "geteuid", return_value=0),
            mock.patch.object(state.os, "chown", side_effect=record_chown),
        ):
            state._atomic_write(target, {"schema_version": state.SCHEMA_VERSION})

        self.assertEqual(len(chown_calls), 1)
        self.assertEqual((chown_calls[0][1], chown_calls[0][2]), (parent.st_uid, parent.st_gid))
        self.assertEqual(target.stat().st_mode & 0o777, 0o600)

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

    def test_chatgpt_freeze_task_requires_browser_progress_before_ready(self) -> None:
        task_id = "chatgpt-freeze-root-cause-20260929-g4"
        state.start_task(task_id, "Prove natural ChatGPT continuity after a freeze", "gpt")

        with self.assertRaisesRegex(state.TaskStateError, "PROGRESS_CONFIRMED"):
            state.mark_ready(
                task_id,
                evidence=["code and CI are green"],
                verification="implementation looks complete",
            )

        ledger = state.RUNTIME_DIR / "_chatgpt-continuity-nudges.jsonl"
        ledger.write_text(
            '{"task_id":"chatgpt-freeze-root-cause-20260929-g4","worker_status":"PROGRESS_CONFIRMED","worker_status_observed_at":"2026-09-29T01:30:00Z"}\n',
            encoding="utf-8",
        )
        ready = state.mark_ready(
            task_id,
            evidence=["browser worker observed assistant progress"],
            verification="natural freeze recovery verified",
        )
        self.assertEqual(ready["status"], "READY_TO_COMPLETE")

    def test_non_freeze_task_does_not_require_browser_progress_ledger(self) -> None:
        state.start_task("ordinary-task", "Finish ordinary work", "gpt")
        ready = state.mark_ready(
            "ordinary-task",
            evidence=["objective check passed"],
            verification="goal verified",
        )
        self.assertEqual(ready["status"], "READY_TO_COMPLETE")

    def test_completed_task_starts_explicit_successor_without_mutating_predecessor(self) -> None:
        state.start_task("task-v1", "Investigar falha original", "gpt")
        state.mark_ready(
            "task-v1",
            evidence=["evidencia original"],
            verification="estado original verificado",
        )
        completed = state.complete_task("task-v1")
        predecessor_updated_at = completed["updated_at"]

        successor = state.start_successor_task(
            "task-v2",
            predecessor_task_id="task-v1",
            goal="Continuar investigacao apos nova evidencia",
            agent_id="gpt",
        )

        self.assertEqual(successor["status"], "RUNNING")
        self.assertEqual(successor["predecessor_task_id"], "task-v1")
        self.assertEqual(successor["predecessor_status"], "CONCLUIDO")
        self.assertTrue(successor["next_action"])
        self.assertEqual(successor["history"][0]["event"], "started_successor")

        predecessor = state.load_task("task-v1")
        self.assertEqual(predecessor["status"], "CONCLUIDO")
        self.assertEqual(predecessor["updated_at"], predecessor_updated_at)

    def test_successor_rejects_noncompleted_predecessor_and_existing_target(self) -> None:
        state.start_task("task-running", "Ainda executando", "gpt")
        with self.assertRaises(state.TaskStateError):
            state.start_successor_task(
                "task-next",
                predecessor_task_id="task-running",
                goal="Nao deve iniciar",
                agent_id="gpt",
            )

        state.mark_ready(
            "task-running",
            evidence=["done"],
            verification="verified",
        )
        state.complete_task("task-running")
        state.start_task("task-next", "Destino ja existe", "gpt")
        with self.assertRaises(state.TaskStateError):
            state.start_successor_task(
                "task-next",
                predecessor_task_id="task-running",
                goal="Nao sobrescrever",
                agent_id="gpt",
            )

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

    def test_production_e2e_gate_is_required_and_cannot_invoke_recovery_stages(self) -> None:
        probe = ROOT / "scripts" / "task_continuity_e2e.py"
        workflow = ROOT / ".github" / "workflows" / "task-continuity-production-e2e.yml"
        self.assertTrue(probe.is_file(), "production continuity E2E probe is required")
        self.assertTrue(workflow.is_file(), "production continuity E2E workflow is required")

        probe_text = probe.read_text(encoding="utf-8")
        self.assertIn("continuity_e2e_pass", probe_text)
        self.assertNotIn("task_continuation_watchdog", probe_text)
        self.assertNotIn("task_resume_dispatcher", probe_text)
        self.assertNotIn("run_once(", probe_text)

        workflow_text = workflow.read_text(encoding="utf-8")
        self.assertIn("scripts/task_continuity_e2e.py", workflow_text)
        self.assertIn("shopvivaliz-a1-deploy", workflow_text)

        validator = (ROOT / "scripts" / "validate-task-continuity-enforcement.py").read_text(encoding="utf-8")
        self.assertIn("task_continuity_e2e.py", validator)
        self.assertIn("task-continuity-production-e2e.yml", validator)

        docs = (ROOT / "docs" / "knowledge" / "task-continuity.md").read_text(encoding="utf-8")
        self.assertIn("DETACHED_TASK_RECOVERY_E2E_V7", docs)
        self.assertIn("continuity_e2e_pass", docs)

        audit = (ROOT / "AUDIT_POLICY.md").read_text(encoding="utf-8")
        self.assertIn("continuity_e2e_pass", audit)



if __name__ == "__main__":
    unittest.main()
