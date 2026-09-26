from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class ExecutorFallbackOrderTests(unittest.TestCase):
    def test_codex_is_last_provider_in_finite_failover(self) -> None:
        script = (ROOT / "scripts" / "autonomous-provider-failover.sh").read_text(encoding="utf-8")
        self.assertIn("ORDER=(gemini anthropic codex)", script)
        self.assertLess(script.index("gemini)"), script.index("anthropic)"))
        self.assertLess(script.index("anthropic)"), script.index("codex)"))

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


if __name__ == "__main__":
    unittest.main()
