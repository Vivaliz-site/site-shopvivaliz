#!/usr/bin/env python3
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
SETUP = ROOT / "scripts" / "setup-iphone-private-ssh-relay.sh"
WORKFLOW = ROOT / ".github" / "workflows" / "shopvivaliz-remote-access.yml"


class IphonePrivateSshRelayContractTest(unittest.TestCase):
    def test_relay_is_tailscale_only_and_targets_site_private_ssh(self):
        self.assertTrue(SETUP.exists(), f"missing setup script: {SETUP}")
        text = SETUP.read_text(encoding="utf-8")

        required = [
            "set -Eeuo pipefail",
            'BACKEND_HOST="always-free-arm-1787907847-26"',
            'SITE_PRIVATE_IP="10.0.1.112"',
            'RELAY_PORT="2224"',
            "tailscale ip -4",
            "ListenStream=$tailscale_ip:$RELAY_PORT",
            "systemd-socket-proxyd $SITE_PRIVATE_IP:22",
            "IPHONE_SSH_RELAY_LISTENER_PRIVATE=true",
            "IPHONE_SSH_RELAY_HANDSHAKE=PASS",
        ]
        for needle in required:
            self.assertIn(needle, text, f"relay contract missing: {needle}")

        forbidden = [
            "0.0.0.0:2224",
            "[::]:2224",
            "137.131.149.55",
            "144.22.157.209",
            "PasswordAuthentication yes",
            "StrictHostKeyChecking=no",
        ]
        for needle in forbidden:
            self.assertNotIn(needle, text, f"relay must not expose or weaken SSH: {needle}")

    def test_remote_access_workflow_exposes_only_backend_install_and_status(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        for action in ("iphone_ssh_relay_install", "iphone_ssh_relay_status"):
            self.assertIn(action, text, f"workflow action missing: {action}")

        self.assertIn(
            'if action.startswith("iphone_ssh_relay_") and target != "always-free-arm-1787907847-26":',
            text,
        )
        self.assertIn("scripts/setup-iphone-private-ssh-relay.sh", text)
        self.assertNotIn("target=137.131.149.55", text)
        self.assertNotIn("target=144.22.157.209", text)


if __name__ == "__main__":
    unittest.main()
