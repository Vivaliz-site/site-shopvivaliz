from pathlib import Path
import importlib.util
import subprocess
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/mandatory-validation-gate.yml"
SCOPED_VALIDATOR = ROOT / "scripts/ci_scoped_validate.sh"
SELECTOR = ROOT / "scripts/ci_impact_selection.py"
spec = importlib.util.spec_from_file_location("ci_impact_selection", SELECTOR)
assert spec and spec.loader
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


class ScopedGateContractTests(unittest.TestCase):
    def test_all_prs_still_have_commercial_and_history_guards(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("Validate current PR history lineage", text)
        self.assertIn("Enforce commercial approval in required gate", text)
        self.assertIn("python3 scripts/enforce-autonomy-policy.py", text)
        self.assertIn("Select fail-closed impact scope", text)

    def test_full_fallback_retained_when_selection_is_missing_or_unknown(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("if: steps.impact.outputs.mode != 'scoped'", text)
        self.assertIn("bash scripts/repository-governance-validate.sh ci", text)
        self.assertIn("mode=full", text)
        self.assertIn("push_full_validation_required=true", text)

    def test_scoped_guardrails_cannot_be_skipped(self):
        text = SCOPED_VALIDATOR.read_text(encoding="utf-8")
        for marker in (
            "validate-recurring-ai-policy.py",
            "validate-final-response-deploy-gate.py",
            "validate-audit-governance.py",
            "validate-task-continuity-enforcement.py",
            "check-agent-docs-enforcement.py",
            "test_production_functional_audit_security.py",
            "tests.test_agent_docs_gate",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, text)
        self.assertIn('*) echo "unsupported_scoped_validation_group=', text)

    def test_shared_and_protected_paths_go_full(self):
        for path in (
            ".github/workflows/mandatory-validation-gate.yml",
            "scripts/ci_scoped_validate.sh",
            "AGENTS.md", "docs/knowledge/host-access.md",
            "docs/ops/cloudflare-admin-independent-vms.md",
            "api/site/orders.php", "composer.lock",
        ):
            with self.subTest(path=path):
                self.assertEqual(mod.classify([path])["mode"], "full")

    def test_deleted_file_is_included_in_git_diff(self):
        fake = subprocess.CompletedProcess(args=["git"], returncode=0, stdout=b"AGENTS.md\x00README.md\x00")
        with patch.object(mod.subprocess, "run", return_value=fake) as call:
            got = mod.changed_files("a"*40, "b"*40)
        self.assertEqual(got, ["AGENTS.md", "README.md"])
        self.assertEqual(mod.classify(got)["mode"], "full")
        self.assertNotIn("--diff-filter=ACMRT", call.call_args.args[0])

    def test_dispatcher_scoped_validation_still_runs_crosscutting_guards(self):
        text = SCOPED_VALIDATOR.read_text(encoding="utf-8")
        self.assertIn('chatgpt-dispatcher)', text)
        self.assertIn('tests.test_chatgpt_continuity_nudge_dispatcher', text)
        self.assertIn('validate-audit-governance.py', text)
        self.assertIn('check-agent-docs-enforcement.py', text)

    def test_cloudflare_preflight_is_not_removed(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("Cloudflare admin staging preflight regression (when present)", text)
        self.assertIn("test_cloudflare_admin_*.py", text)


if __name__ == "__main__":
    unittest.main()
