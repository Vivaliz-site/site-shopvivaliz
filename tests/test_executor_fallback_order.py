from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class ExecutorFallbackOrderTests(unittest.TestCase):
    def setUp(self) -> None:
        self.script = (ROOT / "scripts/autonomous-provider-failover.sh").read_text(encoding="utf-8")

    def test_first_tier_is_gemini_flash_and_local_ollama(self) -> None:
        self.assertIn("BACKGROUND_ORDER=(gemini ollama", self.script)
        self.assertIn("ORDER=(gemini ollama)", self.script)
        self.assertIn("run_background_gemini.py", self.script)
        self.assertIn("run_background_ollama.py", self.script)
        self.assertLess(self.script.index("\n    gemini)"), self.script.index("\n    ollama)"))

    def test_background_chain_is_gemini_ollama_then_login_paid_tiers(self) -> None:
        # Owner decision 2026-11-05: Gemini Flash > local Ollama > Claude Haiku 5.5 > GPT 6 Luna.
        # Paid tiers are login-based (never API key) and capped by timeout + single attempt.
        self.assertIn("BACKGROUND_ORDER=(gemini ollama claude_haiku gpt_luna)", self.script)
        self.assertLess(self.script.index("\n    claude_haiku)"), self.script.index("\n    gpt_luna)"))
        self.assertIn('ANTHROPIC_MODEL="${ANTHROPIC_MODEL:-claude-haiku-5-5}"', self.script)
        self.assertIn("timeout ", self.script)
        self.assertIn("env -u OPENAI_API_KEY -u CODEX_API_KEY", self.script)

    def test_background_claude_timeout_wraps_executable_not_shell_function(self) -> None:
        # GNU timeout cannot exec Bash functions (exit 127); bound the real CLI.
        self.assertNotIn('timeout "$BACKGROUND_TIER_TIMEOUT" run_claude', self.script)
        self.assertIn('try_provider claude_haiku run_claude && exit 0', self.script)
        self.assertIn('timeout "$BACKGROUND_TIER_TIMEOUT" "$launcher" --print', self.script)

    def test_background_paid_tiers_never_use_api_keys(self) -> None:
        self.assertIn("unset ANTHROPIC_API_KEY", self.script)
        self.assertIn("unset OPENAI_API_KEY CODEX_API_KEY", self.script)

    def test_background_paid_fallback_flags_are_obsolete(self) -> None:
        self.assertNotIn("background_paid_fallback_forbidden=true", self.script)
        background = self.script.split('if [ "$SHOPVIVALIZ_RESUME_BACKGROUND" = "1" ]; then', 2)[-1]
        # The paid providers are never appended to the background order,
        # even if the legacy environment variables are set to 1.
        order_block = self.script.split("# FREE_PROVIDER_FALLBACK_V2:", 1)[1].split("for provider in", 1)[0]
        self.assertNotIn("BACKGROUND_ORDER+=(anthropic)", order_block)
        self.assertNotIn("BACKGROUND_ORDER+=(codex_auto)", order_block)
        self.assertNotIn("background_paid_fallback_ignored=true", order_block)

    def test_manual_paid_fallback_requires_explicit_opt_in(self) -> None:
        self.assertIn('SHOPVIVALIZ_INTERACTIVE_PAID_FALLBACK:-0', self.script)
        self.assertIn("ORDER+=(anthropic codex)", self.script)
        self.assertLess(self.script.index("\n    anthropic)"), self.script.index("\n    codex)"))
        self.assertLess(self.script.index("\n    ollama)"), self.script.index("\n    anthropic)"))

    def test_installer_keeps_ollama_local_tier(self) -> None:
        installer=(ROOT / "scripts/install-gemini-24x7-controller.sh").read_text(encoding="utf-8")
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
        self.assertIn('"BACKGROUND_ORDER=(gemini ollama claude_haiku gpt_luna)"', validator)

if __name__ == "__main__":
    unittest.main()
