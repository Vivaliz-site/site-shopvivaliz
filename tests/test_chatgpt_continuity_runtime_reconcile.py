from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class ChatgptContinuityRuntimeReconcileTests(unittest.TestCase):
    def test_post_deploy_reconcile_is_fully_automatic_and_private(self) -> None:
        workflow = ROOT / ".github" / "workflows" / "chatgpt-continuity-runtime-reconcile.yml"
        self.assertTrue(workflow.is_file(), "post-deploy continuity reconcile workflow must exist")
        text = workflow.read_text(encoding="utf-8")

        self.assertIn("Master Production Pipeline 24/7", text)
        self.assertIn("workflow_run", text)
        self.assertIn("shopvivaliz-a1-deploy", text)
        self.assertIn("github.event.workflow_run.conclusion == 'success'", text)

        self.assertIn("/home/ubuntu/shopvivaliz-deploy/shared/storage/private/chatgpt-continuity/bridge.token", text)
        self.assertIn("openssl rand -hex 32", text)
        self.assertIn("umask 077", text)
        self.assertIn("http://127.0.0.1:8080/api/chatgpt-continuity/bridge.php", text)
        self.assertIn("Host: shopvivaliz.com.br", text)

        self.assertIn("ubuntu@10.0.1.38", text)
        self.assertIn("/home/ubuntu/.config/shopvivaliz-chatgpt-continuity/bridge.token", text)
        self.assertIn("install-chatgpt-continuity-backend-bridge.sh", text)
        self.assertIn("http://10.0.1.112:8080/api/chatgpt-continuity/bridge.php", text)
        self.assertIn("127.0.0.1:9555", text)
        self.assertIn("shopvivaliz-chatgpt-continuity.service", text)

        # The browser must use the existing authenticated backend profile; no
        # new profile or Windows browser is allowed.
        self.assertIn("/home/fredrdp/.config/shopvivaliz-chromium", text)
        self.assertIn("/opt/shopvivaliz-browser/chrome-linux/chrome", text)
        self.assertNotIn("C:\\ShopVivaliz", text)

        # Do not make a GitHub secret the source of truth for the bridge token.
        self.assertNotIn("secrets.CHATGPT_CONTINUITY_BRIDGE_TOKEN", text)

    def test_reconcile_never_echoes_the_bridge_token(self) -> None:
        workflow = ROOT / ".github" / "workflows" / "chatgpt-continuity-runtime-reconcile.yml"
        text = workflow.read_text(encoding="utf-8")
        forbidden = [
            "echo $token",
            "echo \"$token\"",
            "printf '%s\\n' \"$token\"",
            "set -x",
        ]
        for needle in forbidden:
            self.assertNotIn(needle, text)


if __name__ == "__main__":
    unittest.main()
