from pathlib import Path
import importlib.util
import unittest

ROOT = Path(__file__).resolve().parents[1]
GUARD = ROOT / "scripts" / "validate-final-response-deploy-gate.py"
GOVERNANCE = ROOT / "scripts" / "repository-governance-validate.sh"
NORMATIVE = (
    ROOT / "REGRAS-AGENTES-CENTRALIZADAS.md",
    ROOT / "AGENTS.md",
    ROOT / "docs" / "AGENTS.md",
    ROOT / "CLAUDE.md",
)
MARKER = "FINAL_RESPONSE_DEPLOY_GATE_V1"


class FinalResponseDeployGateTests(unittest.TestCase):
    def test_normative_docs_require_post_deploy_before_final_response(self):
        for path in NORMATIVE:
            text = path.read_text(encoding="utf-8")
            self.assertIn(MARKER, text, str(path))
            self.assertIn("resposta final", text.lower(), str(path))
            self.assertIn("pós-deploy", text.lower(), str(path))

    def test_guard_is_enforced_by_repository_governance(self):
        self.assertTrue(GUARD.is_file(), "final-response deploy guard must exist")
        text = GOVERNANCE.read_text(encoding="utf-8")
        self.assertIn("python3 scripts/validate-final-response-deploy-gate.py", text)
