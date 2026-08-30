#!/usr/bin/env python3
from pathlib import Path
import unittest

REPO_ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = REPO_ROOT / ".github" / "workflows" / "prepare-olist-oauth-reauth-manual.yml"


class OlistOAuthLiveCutoverWorkflowTests(unittest.TestCase):
    def test_moves_live_rotating_store_instead_of_static_refresh_secret(self) -> None:
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertNotIn("secrets.TINY_REFRESH_TOKEN", text)
        self.assertNotIn("TINY_REFRESH_TOKEN_VALUE", text)
        self.assertIn("source_token_store_valid=true", text)
        self.assertIn("source_product_get_http=200", text)
        self.assertIn("target_product_get_http=200", text)
        self.assertIn("oauth_cutover_promoted=true", text)

    def test_cutover_serializes_rotation_and_cleans_transport_material(self) -> None:
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("shopvivaliz-token-renewer.service", text)
        self.assertIn("daemon-token-renewer.py --once", text)
        self.assertIn("if: always()", text)
        self.assertIn("rm -f", text)
        self.assertNotIn("cat $source_store", text)
        self.assertNotIn('cat "$source_store"', text)


if __name__ == "__main__":
    unittest.main()
