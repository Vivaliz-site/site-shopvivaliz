from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class ExecutorFallbackOrderTests(unittest.TestCase):
    def setUp(self) -> None:
        self.script = (ROOT / "scripts/autonomous-provider-failover.sh").read_text(encoding="utf-8")

    def test_background_chain_is_free_only_gemini_then_local_ollama(self) -> None:
        self.assertIn("BACKGROUND_ORDER=(gemini ollama)", self.script)
        self.assertIn("ORDER=(gemini ollama)", self.script)
        self.assertIn('ORDER=("\${BACKGROUND_ORDER[@]}")'.replace("\\", ""), self.script)
        self.assertIn("background_chain=gemini_free,ollama_local_only", self.script)
        self.assertIn("run_background_gemini.py", self.script)
        self.assertIn("run_background_ollama.py", self.script)
        self.assertNotIn("claude_haiku", self.script)
        self.assertNotIn("gpt_luna", self.script)
        self.assertNotIn("codex_auto", self.script)

    def test_background_never_inherits_paid_fallback_flags(self) -> None:
        block = self.script.split("# FREE_PROVIDER_FALLBACK_V2:", 1)[1].split("for provider in", 1)[0]
        self.assertIn('if [ "$SHOPVIVALIZ_RESUME_BACKGROUND" = "1" ]; then', block)
        background = block.split('if [ "$SHOPVIVALIZ_RESUME_BACKGROUND" = "1" ]; then', 1)[1].split("else", 1)[0]
        self.assertIn('ORDER=("\${BACKGROUND_ORDER[@]}")'.replace("\\", ""), background)
        self.assertNotIn("anthropic", background)
        self.assertNotIn("codex", background)
        self.assertNotIn("claude", background)
        self.assertNotIn("gpt", background)

    def test_paid_fallback_requires_explicit_interactive_opt_in(self) -> None:
        self.assertIn("SHOPVIVALIZ_INTERACTIVE_PAID_FALLBACK", self.script)
        self.assertIn("ORDER+=(anthropic codex)", self.script)
        block = self.script.split("# FREE_PROVIDER_FALLBACK_V2:", 1)[1].split("for provider in", 1)[0]
        self.assertIn("SHOPVIVALIZ_INTERACTIVE_PAID_FALLBACK", block)
        self.assertLess(self.script.index("\n    anthropic)"), self.script.index("\n    codex)"))
        self.assertLess(self.script.index("\n    ollama)"), self.script.index("\n    anthropic)"))

    def test_paid_interactive_providers_have_finite_timeouts(self) -> None:
        self.assertIn("PAID_INTERACTIVE_TIMEOUT", self.script)
        self.assertIn('timeout "$PAID_INTERACTIVE_TIMEOUT" "$launcher" --print', self.script)
        self.assertIn('timeout "$PAID_INTERACTIVE_TIMEOUT" env -u OPENAI_API_KEY -u CODEX_API_KEY codex exec', self.script)

    def test_gemini_background_runner_enforces_free_tier_attestation(self) -> None:
        runner = (ROOT / "scripts/run_background_gemini.py").read_text(encoding="utf-8")
        self.assertIn("FREE_TIER_BILLING_GUARD_V1", runner)
        self.assertIn("free_tier_approved_credentials(", runner)
        self.assertIn("background_gemini_error=free_tier_not_verified", runner)

    def test_installer_keeps_ollama_local_tier(self) -> None:
        installer=(ROOT / "scripts/install-gemini-24x7-controller.sh").read_text(encoding="utf-8")
        self.assertIn("run_background_ollama.py", installer)

    def test_chatgpt_common_gate_precedes_cli(self) -> None:
        self.assertIn("SHOPVIVALIZ_RESUME_STAGE", self.script)
        self.assertIn('!= "cli_last"', self.script)
        self.assertIn("chatgpt_common -> chatgpt_work -> cli_last", self.script)
        policy = (ROOT / "docs/knowledge/task-continuity.md").read_text(encoding="utf-8")
        self.assertIn("CODEX_LAST_RESORT_V1", policy)

    def test_continuity_policy_is_checked(self) -> None:
        validator=(ROOT / "scripts/validate-task-continuity-enforcement.py").read_text(encoding="utf-8")
        self.assertIn('"ORDER=(gemini ollama)"', validator)
        self.assertIn('"BACKGROUND_ORDER=(gemini ollama)"', validator)
        self.assertIn('"background_chain=gemini_free,ollama_local_only"', validator)


if __name__ == "__main__":
    unittest.main()
