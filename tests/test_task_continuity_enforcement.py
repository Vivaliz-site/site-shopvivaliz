from __future__ import annotations

import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

import agent_task_state as state  # noqa: E402


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


class TaskContinuityPolicyTests(unittest.TestCase):
    def test_normative_entrypoints_override_generic_pause_gates(self) -> None:
        marker = "TASK_CONTINUITY_ENFORCEMENT_V3"
        paths = (
            ROOT / "REGRAS-AGENTES-CENTRALIZADAS.md",
            ROOT / "AGENTS.md",
            ROOT / "AI-TO-CLI-PROTOCOL.md",
            ROOT / "docs" / "knowledge" / "agent-rules.md",
            ROOT / "CLAUDE.md",
            ROOT / "GEMINI.md",
            ROOT / "AGENTS.override.md",
            ROOT / ".github" / "copilot-instructions.md",
        )
        for path in paths:
            text = path.read_text(encoding="utf-8")
            self.assertIn(marker, text, str(path))
            self.assertIn("BLOCKED_EXTERNAL", text, str(path))

    def test_governance_executes_continuity_validator(self) -> None:
        governance = (ROOT / "scripts" / "repository-governance-validate.sh").read_text(encoding="utf-8")
        self.assertIn("python3 scripts/validate-task-continuity-enforcement.py", governance)


if __name__ == "__main__":
    unittest.main()
