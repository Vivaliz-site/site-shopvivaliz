#!/usr/bin/env python3
"""Static fail-closed contracts for the ERP price audit deployment gate."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/postdeploy-erp-price-parity.yml"
SCRIPT = ROOT / "scripts/quality/audit-storefront-erp-prices.py"


class PostDeployPriceParityContract(unittest.TestCase):
    def test_pipeline_runs_after_real_release_and_regularly(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        for marker in (
            "workflows: [Master Production Pipeline 24/7]",
            "workflow_run:",
            "types: [completed]",
            "pull_request:",
            "schedule:",
            "workflow_dispatch:",
            "deployment/latest.json?ref=deployment-evidence",
            "p.get('status')=='PRODUCTION_UPDATED'",
            "jobs.get(k)=='success'",
            "if: ${{ needs.select_release.outputs.audit_required == 'true' }}",
            "--expected-sha '$DEPLOY_SHA'",
            "scripts/quality/audit-storefront-erp-prices.py",
            "Upload immutable read-only price parity evidence",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, workflow)

    def test_live_source_is_verifiably_erp_v3_and_read_only(self):
        code = SCRIPT.read_text(encoding="utf-8")
        for marker in (
            'daemon-sync-products.py',
            'token = api.get_token()',
            'api.fetch_products_active(token)',
            'api.api_get("produtos/" + pid, token',
            'public_products()',
            'cache_price_differs_from_erp',
            'storefront_price_differs_from_erp',
            'duplicate_erp_sku',
            'public_release_sha_mismatch',
            'immutable_release_sha_mismatch',
            'read_only": True',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, code)
        for forbidden in ('INSERT INTO ', 'UPDATE products ', 'DELETE FROM ', 'api_post(', 'api_put('):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, code)

    def test_all_agent_policies_require_real_deploy_and_price_parity(self):
        for relative in ("AGENTS.md", "REGRAS-AGENTES-CENTRALIZADAS.md",
                         "docs/knowledge/agent-rules.md", "docs/knowledge/deploy.md"):
            with self.subTest(path=relative):
                text=(ROOT/relative).read_text(encoding="utf-8")
                self.assertIn("DEPLOY_PRICE_PARITY_AUTOMATED_GATE_V2", text)
                self.assertIn("postdeploy-erp-price-parity.yml", text)


if __name__ == "__main__":
    unittest.main()
