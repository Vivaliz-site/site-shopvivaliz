"""Fail closed CI contract for approved Marina Access DCR repair workflow."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github" / "workflows" / "marina-mcp-cloudflare-dcr-apply.yml"

class MarinaDcrApplyWorkflowContract(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.workflow = WORKFLOW.read_text(encoding="utf-8")

    def test_explicit_consent_marker_only_in_dispatch(self):
        self.assertIn("workflow_dispatch:", self.workflow)
        self.assertIn("APPLY_MARINA_DCR_PR3521", self.workflow)
        self.assertIn('inputs.confirmation', self.workflow)
        self.assertIn("if not allowed", self.workflow)

    def test_protected_human_grant_exact_operation_must_be_checked(self):
        self.assertIn("scripts/validate-owner-pr-grant.py", self.workflow)
        for scope in ("3521", "access_manage", "configure_cloudflare_mcp_ingress", "shopvivaliz-human",
                      "marina-mcp-service", "mcp:marina"):
            self.assertIn(scope, self.workflow)

    def test_exact_app_and_redirect_only(self):
        self.assertIn("961c1049-6e70-46ae-bcfa-7827fbdf7553", self.workflow)
        self.assertIn("https://chatgpt.com/connector_platform_oauth_redirect", self.workflow)
        self.assertIn("scripts/marina_mcp_oauth_dcr.py", self.workflow)
        self.assertIn("--apply", self.workflow)
        self.assertIn("--app-id", self.workflow)
        self.assertIn("--redirect-uri", self.workflow)
        self.assertIn("--backup-path", self.workflow)

    def test_existing_secret_and_job_scoping(self):
        self.assertIn("secrets.CLOUDFLARE_API_TOKEN", self.workflow)
        self.assertIn("environment: Production", self.workflow)
        self.assertIn("concurrency:", self.workflow)
        self.assertIn("cancel-in-progress: false", self.workflow)
        self.assertNotIn("secrets.CLOUDFLARE_DNS_EDIT_TOKEN", self.workflow)
        self.assertNotIn("CLOUDFLARE_MARINA_TUNNEL_TOKEN", self.workflow)
        self.assertNotIn("cloudflared tunnel create", self.workflow)
        self.assertNotIn("--admin", self.workflow)

    def test_apply_never_proceeds_without_verified_owner_grant(self):
        self.assertIn("OWNER_PR_GRANT=AUTHORIZED", self.workflow)
        self.assertIn("OWNER_GRANT_VERIFIED", self.workflow)
        self.assertIn("git diff --quiet", self.workflow)

if __name__ == "__main__":
    unittest.main()
