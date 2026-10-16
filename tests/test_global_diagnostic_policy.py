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


    def test_absolute_error_remediation_rule_is_present_everywhere(self):
        required = [
            "AGENTS.md",
            "docs/knowledge/agent-rules.md",
            "docs/knowledge/README.md",
            "CLAUDE.md",
            "GEMINI.md",
            "GEPETO-POLICY.md",
        ]
        for path in required:
            with self.subTest(path=path):
                doc = (ROOT / path).read_text(encoding="utf-8")
                self.assertIn("<!-- EVERY_CONFIRMED_ERROR_REMEDIATE_V1 -->", doc)
                self.assertIn("<!-- /EVERY_CONFIRMED_ERROR_REMEDIATE_V1 -->", doc)

    def test_absolute_rule_includes_correction_evidence_and_safety(self):
        policy = (ROOT / "AGENTS.md").read_text(encoding="utf-8")
        for term in (
            "todo erro encontrado deve ser corrigido",
            "causa raiz",
            "RED",
            "GREEN",
            "regressao",
            "E2E",
            "RUNNING",
            "BLOCKED_EXTERNAL",
            "somente leitura",
            "protecoes Git/CI",
        ):
            with self.subTest(term=term):
                self.assertIn(term, policy)


if __name__ == "__main__":
    unittest.main()
