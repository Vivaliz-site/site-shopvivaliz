from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class ExecutorFallbackOrderTests(unittest.TestCase):
    def test_codex_is_last_provider_in_finite_failover(self) -> None:
        script = (ROOT / "scripts" / "autonomous-provider-failover.sh").read_text(encoding="utf-8")
        self.assertIn("ORDER=(gemini anthropic codex)", script)
        self.assertLess(script.index("\n    gemini)"), script.index("\n    anthropic)"))
        self.assertLess(script.index("\n    anthropic)"), script.index("\n    codex)"))

    def test_continuity_policy_marks_codex_as_last_resort(self) -> None:
        marker = "CODEX_LAST_RESORT_V1"
        for rel in (
            "REGRAS-AGENTES-CENTRALIZADAS.md",
            "AGENTS.md",
            "docs/knowledge/task-continuity.md",
            "docs/knowledge/agent-rules.md",
        ):
            text = (ROOT / rel).read_text(encoding="utf-8")
            self.assertIn(marker, text, rel)
            self.assertIn("Codex", text, rel)

    def test_codex_exhaustion_is_not_terminal_blocker(self) -> None:
        policy = (ROOT / "docs" / "knowledge" / "task-continuity.md").read_text(encoding="utf-8")
        self.assertIn("cota do Codex", policy)
        self.assertIn("RUNNING", policy)
        self.assertIn("BLOCKED_EXTERNAL", policy)

    def test_finite_failover_persists_running_checkpoint_when_all_executors_fail(self) -> None:
        script = (ROOT / "scripts" / "autonomous-provider-failover.sh").read_text(encoding="utf-8")
        self.assertIn("SHOPVIVALIZ_TASK_ID", script)
        self.assertIn("agent_task_state.py progress", script)
        self.assertIn("exit 75", script)

    def test_account_authenticated_alternatives_are_tried_before_codex(self) -> None:
        script = (ROOT / "scripts" / "autonomous-provider-failover.sh").read_text(encoding="utf-8")
        self.assertIn("command -v gemini", script)
        self.assertIn("command -v claude", script)
        self.assertIn("env -u GEMINI_API_KEY -u GOOGLE_API_KEY gemini", script)
        self.assertIn("env -u ANTHROPIC_API_KEY claude", script)


if __name__ == "__main__":
    unittest.main()
