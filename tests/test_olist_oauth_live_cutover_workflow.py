#!/usr/bin/env python3
from pathlib import Path
import unittest

REPO_ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = REPO_ROOT / ".github" / "workflows" / "prepare-olist-oauth-reauth-manual.yml"


class OlistOAuthLiveCutoverWorkflowTests(unittest.TestCase):
    def test_promotes_local_callback_store_without_static_or_remote_refresh(self) -> None:
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertNotIn("secrets.TINY_REFRESH_TOKEN", text)
        self.assertNotIn("TINY_REFRESH_TOKEN_VALUE", text)
        self.assertNotIn("SOURCE_PRIVATE_HOST", text)
        self.assertIn("target_local_store_present=true", text)
        self.assertIn("target_product_get_http=200", text)
        self.assertIn("oauth_local_store_promoted=true", text)

    def test_local_store_is_normalized_rotated_and_guarded_by_service(self) -> None:
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn('chown ubuntu:www-data "$canonical"', text)
        self.assertIn('chmod 660 "$canonical"', text)
        self.assertIn("sudo -u ubuntu -g www-data", text)
        self.assertIn("daemon-token-renewer.py --once", text)
        self.assertIn("shopvivaliz-token-renewer.service", text)
        self.assertIn("target_token_renewer=active_enabled", text)
        self.assertIn("if: always()", text)
        self.assertNotIn("cat $canonical", text)
        self.assertNotIn('cat "$canonical"', text)


if __name__ == "__main__":
    unittest.main()
