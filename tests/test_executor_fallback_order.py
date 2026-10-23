from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class ExecutorFallbackOrderTests(unittest.TestCase):
    def setUp(self) -> None:
        self.script = (ROOT / "scripts/autonomous-provider-failover.sh").read_text(encoding="utf-8")

    def test_first_tier_is_gemini_flash_and_local_ollama(self) -> None:
        self.assertIn("BACKGROUND_ORDER=(gemini ollama)", self.script)
        self.assertIn("ORDER=(gemini ollama)", self.script)
        self.assertIn("run_background_gemini.py", self.script)
        self.assertIn("run_background_ollama.py", self.script)
        self.assertLess(self.script.index("\n    gemini)"), self.script.index("\n    ollama)"))

    def test_background_paid_fallback_is_unconditionally_forbidden(self) -> None:
        self.assertIn("background_paid_fallback_forbidden=true", self.script)
        background = self.script.split('if [ "$SHOPVIVALIZ_RESUME_BACKGROUND" = "1" ]; then', 2)[-1]
        # The paid providers are never appended to the background order,
        # even if the legacy environment variables are set to 1.
        order_block = self.script.split("# FREE_PROVIDER_FALLBACK_V2:", 1)[1].split("for provider in", 1)[0]
        self.assertNotIn("BACKGROUND_ORDER+=(anthropic)", order_block)
        self.assertNotIn("BACKGROUND_ORDER+=(codex_auto)", order_block)
        self.assertIn("background_paid_fallback_ignored=true", order_block)

    def test_manual_paid_fallback_requires_explicit_opt_in(self) -> None:
        self.assertIn('SHOPVIVALIZ_INTERACTIVE_PAID_FALLBACK:-0', self.script)
        self.assertIn("ORDER+=(anthropic codex)", self.script)
        self.assertLess(self.script.index("\n    anthropic)"), self.script.index("\n    codex)"))
        self.assertLess(self.script.index("\n    ollama)"), self.script.index("\n    anthropic)"))

    def test_installer_defaults_to_no_paid_background(self) -> None:
        installer=(ROOT / "scripts/install-gemini-24x7-controller.sh").read_text(encoding="utf-8")
        self.assertIn("SHOPVIVALIZ_BACKGROUND_CLAUDE_FALLBACK=0", installer)
        self.assertIn("SHOPVIVALIZ_BACKGROUND_CODEX_FALLBACK=0", installer)
        self.assertIn("run_background_ollama.py", installer)

    def test_chatgpt_common_gate_precedes_cli(self) -> None:
        self.assertIn('SHOPVIVALIZ_RESUME_STAGE', self.script)
        self.assertIn('!= "cli_last"', self.script)
        self.assertIn("chatgpt_common -> chatgpt_work -> cli_last", self.script)
        policy = (ROOT / "docs/knowledge/task-continuity.md").read_text(encoding="utf-8")
        self.assertIn("CODEX_LAST_RESORT_V1", policy)

    def test_continuity_policy_is_checked(self) -> None:
        validator=(ROOT / "scripts/validate-task-continuity-enforcement.py").read_text(encoding="utf-8")
        self.assertIn('"ORDER=(gemini ollama)"', validator)
        self.assertIn('"BACKGROUND_ORDER=(gemini ollama)"', validator)

if __name__ == "__main__":
    unittest.main()
