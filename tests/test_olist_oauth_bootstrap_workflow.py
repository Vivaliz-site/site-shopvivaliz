#!/usr/bin/env python3
from __future__ import annotations

import unittest
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = REPO_ROOT / ".github" / "workflows" / "refresh-olist-token-2h.yml"


class OlistOAuthBootstrapWorkflowTests(unittest.TestCase):
    def test_manual_refresh_can_seed_missing_runtime_from_production_secrets(self) -> None:
        text = WORKFLOW.read_text(encoding="utf-8")
        expected = {
            "OLIST_CLIENT_ID_VALUE": "${{ secrets.OLIST_CLIENT_ID }}",
            "OLIST_CLIENT_SECRET_VALUE": "${{ secrets.OLIST_CLIENT_SECRET }}",
            "OLIST_REFRESH_TOKEN_VALUE": "${{ secrets.OLIST_REFRESH_TOKEN }}",
            "TINY_CLIENT_ID_VALUE": "${{ secrets.TINY_CLIENT_ID }}",
            "TINY_CLIENT_SECRET_VALUE": "${{ secrets.TINY_CLIENT_SECRET }}",
            "TINY_REFRESH_TOKEN_VALUE": "${{ secrets.TINY_REFRESH_TOKEN }}",
        }
        for name, expression in expected.items():
            self.assertIn(f"{name}: {expression}", text)

        self.assertIn("bootstrap-olist-oauth-runtime.py", text)
        self.assertNotIn("OLIST_ACCESS_TOKEN_VALUE:", text)
        self.assertNotIn("TINY_ACCESS_TOKEN_VALUE:", text)

    def test_bootstrap_is_fail_closed_and_cleans_temporary_seed(self) -> None:
        text = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("set -Eeuo pipefail", text)
        self.assertIn("oauth-bootstrap.seed", text)
        self.assertIn("if: always()", text)
        self.assertIn("Remove OAuth bootstrap seed", text)


if __name__ == "__main__":
    unittest.main()
