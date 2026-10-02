import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MARKER = "DIAGNOSTIC_REMEDIATE_VALIDATE_GLOBAL_V1"


class GlobalDiagnosticPolicyTests(unittest.TestCase):
    def test_policy_is_present_in_agent_entrypoints(self):
        required = [
            "AGENTS.md",
            "CLAUDE.md",
            "GEMINI.md",
            "REGRAS-AGENTES-CENTRALIZADAS.md",
            "docs/knowledge/agent-rules.md",
            "docs/knowledge/README.md",
        ]
        missing = [p for p in required if MARKER not in (ROOT / p).read_text(encoding="utf-8")]
        self.assertFalse(missing, f"global diagnostic remediation policy missing from: {missing}")

    def test_policy_requires_correction_and_post_fix_validation(self):
        text = (ROOT / "REGRAS-AGENTES-CENTRALIZADAS.md").read_text(encoding="utf-8")
        for term in ("correção segura", "testes de regressão", "validar no runtime real", "RUNNING", "BLOCKED_EXTERNAL"):
            with self.subTest(term=term):
                self.assertIn(term, text)


if __name__ == "__main__":
    unittest.main()
