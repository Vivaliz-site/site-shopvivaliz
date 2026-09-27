from __future__ import annotations

import importlib.util
import os
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RUNNER = ROOT / "scripts" / "run_background_gemini.py"


def load_runner():
    if not RUNNER.is_file():
        raise AssertionError("scripts/run_background_gemini.py is missing")
    spec = importlib.util.spec_from_file_location("run_background_gemini_test", RUNNER)
    if spec is None or spec.loader is None:
        raise RuntimeError("cannot load background Gemini runner")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class BackgroundGeminiRunnerTests(unittest.TestCase):
    def setUp(self) -> None:
        self.mod = load_runner()

    def test_selects_first_supported_nonempty_credential_without_exposing_value(self) -> None:
        source, value = self.mod.select_gemini_credential(
            """
            # comment
            GEMINI_API_KEY=
            GOOGLE_API_KEY="google-value"
            GOOGLE_GEMINI_API_KEY=third-value
            """
        )
        self.assertEqual(source, "GOOGLE_API_KEY")
        self.assertEqual(value, "google-value")

    def test_child_environment_is_trusted_and_contains_only_gemini_provider_secret(self) -> None:
        base = {
            "PATH": "/usr/bin",
            "OPENAI_API_KEY": "openai-secret",
            "ANTHROPIC_API_KEY": "anthropic-secret",
            "GOOGLE_API_KEY": "old-google",
            "GOOGLE_GEMINI_API_KEY": "old-google-gemini",
            "UNRELATED": "keep-me",
        }
        env = self.mod.build_child_env(base, "gemini-secret")
        self.assertEqual(env["GEMINI_API_KEY"], "gemini-secret")
        self.assertEqual(env["GEMINI_CLI_TRUST_WORKSPACE"], "true")
        self.assertEqual(env["UNRELATED"], "keep-me")
        self.assertNotIn("OPENAI_API_KEY", env)
        self.assertNotIn("ANTHROPIC_API_KEY", env)
        self.assertNotIn("GOOGLE_API_KEY", env)
        self.assertNotIn("GOOGLE_GEMINI_API_KEY", env)

    def test_default_binary_does_not_depend_on_systemd_path(self) -> None:
        self.assertEqual(
            str(self.mod.DEFAULT_GEMINI_BIN),
            "/home/ubuntu/.local/bin/gemini",
        )

    def test_failover_uses_protected_background_runner_only_in_background_mode(self) -> None:
        failover = (ROOT / "scripts" / "autonomous-provider-failover.sh").read_text(encoding="utf-8")
        self.assertIn("run_background_gemini.py", failover)
        self.assertIn('if [ "$SHOPVIVALIZ_RESUME_BACKGROUND" = "1" ]', failover)
        self.assertIn('--prompt-file "$PROMPT_FILE"', failover)
        self.assertIn('--model "$GEMINI_MODEL"', failover)
        self.assertIn("BACKGROUND_ORDER=(gemini)", failover)
        self.assertIn("background_paid_fallback_forbidden=true", failover)


if __name__ == "__main__":
    unittest.main()
