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


    def test_absolute_rule_required_in_agent_entrypoints(self):
        required = (
            "AGENTS.md",
            "docs/knowledge/agent-rules.md",
            "CLAUDE.md",
            "GEMINI.md",
            "GEPETO-POLICY.md",
            "docs/knowledge/README.md",
        )
        for relative in required:
            with self.subTest(path=relative):
                doc = (ROOT / relative).read_text(encoding="utf-8")
                self.assertIn("<!-- ABSOLUTE_REMEDIATE_EVERY_ERROR_V1 -->", doc)
                self.assertIn("<!-- /ABSOLUTE_REMEDIATE_EVERY_ERROR_V1 -->", doc)

    def test_absolute_rule_does_not_allow_false_success_or_safety_bypass(self):
        doc = (ROOT / "AGENTS.md").read_text(encoding="utf-8")
        for phrase in (
            "todo erro identificado exige correcao real e validacao",
            "RED",
            "GREEN",
            "causa raiz",
            "regressao",
            "RUNNING/NAO_APTO",
            "next_action",
            "SOMENTE LEITURA",
            "Sem bypass",
        ):
            with self.subTest(phrase=phrase):
                self.assertIn(phrase, doc)


if __name__ == "__main__":
    unittest.main()
