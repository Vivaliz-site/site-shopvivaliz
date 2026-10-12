#!/usr/bin/env python3
"""Contract for opt-in, identity-gated adoption of a logged-in Dev aux Chrome."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "adopt-verified-dev-aux-browser.sh"


class DevAuxAdoptionDeployTests(unittest.TestCase):
    def test_migration_requires_explicit_owner_optin_and_account_identity(self):
        body = SCRIPT.read_text()
        self.assertIn("SHOPVIVALIZ_AUTHORIZE_DEV_AUX_ADOPTION", body)
        self.assertIn("dev_aux", body)
        self.assertIn("AUTHENTICATED", body)
        self.assertIn("identity_match", body)
        self.assertIn("runtime_lock.acquire_runtime_lock", body)
        self.assertIn("runtime_lock.release_runtime_lock", body)

    def test_only_reconfigures_dev_mcp_and_preserves_chromium_processes(self):
        body = SCRIPT.read_text()
        self.assertIn("shopvivaliz-browser-dev-mcp.service", body)
        self.assertIn("SHOPVIVALIZ_BROWSER_CDP_URL=http://127.0.0.1:9557", body)
        self.assertIn("SHOPVIVALIZ_DEV_ADOPTED_CDP=9557", body)
        self.assertIn("systemctl restart shopvivaliz-browser-dev-mcp.service", body)
        # NO Chrome restart, cookie/profile copy, stale OTP transfer or browser
        # profile reset. Only the account-verified MCP endpoint may change.
        for item in (
            "systemctl restart shopvivaliz-dev-browser.service",
            "systemctl restart shopvivaliz-atendimento-mcp-browser.service",
            "systemctl stop shopvivaliz-atendimento-mcp-browser.service",
            "cp -r /home/fredrdp/.config",
            "rsync /home/fredrdp/.config",
            "sqlite3",
        ):
            self.assertNotIn(item, body)

    def test_health_must_prove_exact_account_and_rollback_on_error(self):
        body = SCRIPT.read_text()
        self.assertIn("account_identity_verified", body)
        self.assertIn("account_authenticated", body)
        self.assertIn("rollback", body.lower())
        self.assertIn("trap", body)
        self.assertIn("127.0.0.1:5583/health", body)


if __name__ == "__main__":
    unittest.main()
