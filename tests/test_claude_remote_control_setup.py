#!/usr/bin/env python3
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
SETUP = ROOT / "scripts" / "setup-claude-remote-control.sh"
UNIT = ROOT / "deploy" / "systemd" / "shopvivaliz-claude-remote-control.service"
DOC = ROOT / "docs" / "knowledge" / "claude-vm-bootstrap.md"


class ClaudeRemoteControlSetupContract(unittest.TestCase):
    def test_setup_keeps_remote_mcp_private_and_uses_unix_stdio_bridge(self) -> None:
        text = SETUP.read_text(encoding="utf-8")
        self.assertIn("/run/shopvivaliz-remote-control/mcp.sock", text)
        self.assertIn("shopvivaliz-mcp-clients", text)
        self.assertIn("claude-remote-control-mcp-stdio.py", text)
        self.assertIn('"type":"stdio"', text)
        self.assertNotIn("headersHelper", text)
        self.assertNotIn("mcp-token", text)
        self.assertNotIn("0.0.0.0:5580", text)
        self.assertNotIn("cloudflared", text)
        self.assertNotIn("ngrok", text)

    def test_service_runs_claude_remote_control_locally_on_backend(self) -> None:
        text = UNIT.read_text(encoding="utf-8")
        self.assertIn("User=ubuntu", text)
        self.assertIn("claude remote-control", text)
        self.assertIn("--spawn worktree", text)
        self.assertIn("--no-create-session-in-dir", text)
        self.assertIn("Restart=always", text)
        self.assertIn("NoNewPrivileges=true", text)
        self.assertNotIn("ANTHROPIC_API_KEY", text)

    def test_setup_requires_eligible_claude_ai_login_before_service_start(self) -> None:
        text = SETUP.read_text(encoding="utf-8")
        self.assertIn("auth status", text)
        self.assertIn("claude doctor", text)
        self.assertIn("REMOTE_CONTROL_ELIGIBLE=PASS", text)
        self.assertIn("REMOTE_CONTROL_LOGIN_REQUIRED", text)
        self.assertIn("REMOTE_CONTROL_POLICY_REQUIRED", text)

    def test_setup_installs_official_native_claude_when_missing(self) -> None:
        text = SETUP.read_text(encoding="utf-8")
        self.assertIn("https://claude.ai/install.sh", text)
        self.assertIn(".local/bin/claude", text)
        self.assertIn("CLAUDE_NATIVE_INSTALL=PASS", text)


    def test_stdio_bridge_contains_no_reusable_mcp_secret(self) -> None:
        bridge = (ROOT / "scripts" / "claude-remote-control-mcp-stdio.py").read_text(encoding="utf-8")
        self.assertIn("/run/shopvivaliz-remote-control/mcp.sock", bridge)
        self.assertNotIn("Bearer", bridge)
        self.assertNotIn("mcp-token", bridge)

    def test_docs_define_claude_web_to_backend_remote_control_path(self) -> None:
        text = DOC.read_text(encoding="utf-8")
        self.assertIn("Claude Code Remote Control", text)
        self.assertIn("claude.ai/code", text)
        self.assertIn("127.0.0.1:5580", text)
        self.assertIn("GitHub Actions", text)


if __name__ == "__main__":
    unittest.main()
