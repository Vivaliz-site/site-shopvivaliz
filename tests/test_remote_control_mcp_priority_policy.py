#!/usr/bin/env python3
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
ACTIVE_ACCESS_DOCS = (
    ROOT / "AGENTS.md",
    ROOT / "docs" / "HOST-ACCESS.md",
    ROOT / "docs" / "AGENT-REMOTE-ACCESS.md",
    ROOT / "docs" / "knowledge" / "host-access.md",
    ROOT / "docs" / "knowledge" / "agent-rules.md",
    ROOT / "CLAUDE.md",
    ROOT / "docs" / "knowledge" / "claude-vm-bootstrap.md",
    ROOT / "GEMINI.md",
    ROOT / "docs" / "knowledge" / "README.md",
    ROOT / "REGRAS-AGENTES-CENTRALIZADAS.md",
    ROOT / "docs" / "AGENT-MCP-REMOTE.md",
)

LEGACY_CLAUDE_ACCESS_DOCS = (
    ROOT / "CLAUDE-REMOTE-CONFIG.md",
    ROOT / "CLAUDE-SETUP-COMPLETE.md",
    ROOT / "SETUP-CLAUDE-REMOTO.md",
)


class RemoteControlMcpPriorityPolicyTest(unittest.TestCase):
    def test_active_agent_access_docs_prioritize_remote_control_mcp_and_retire_rdc(self) -> None:
        for path in ACTIVE_ACCESS_DOCS:
            text = path.read_text(encoding="utf-8")
            with self.subTest(path=str(path.relative_to(ROOT))):
                self.assertIn("Remote Control MCP", text)
                self.assertNotIn("Desktop Commander", text)

        remote_access = (ROOT / "docs" / "AGENT-REMOTE-ACCESS.md").read_text(encoding="utf-8")
        self.assertIn("1. **Remote Control MCP:**", remote_access)
        self.assertIn("2. **SSH privado/Tailscale:**", remote_access)
        self.assertIn("3. **GitHub Actions/OCI Bastion:**", remote_access)
        self.assertIn("4. **GUI/validação visual:**", remote_access)

    def test_legacy_claude_access_guides_are_explicitly_superseded(self) -> None:
        for path in LEGACY_CLAUDE_ACCESS_DOCS:
            text = path.read_text(encoding="utf-8")
            with self.subTest(path=str(path.relative_to(ROOT))):
                self.assertIn("SUPERSEDED", text[:600])
                self.assertIn("Remote Control MCP", text[:1200])
                self.assertIn("docs/knowledge/host-access.md", text[:1200])


if __name__ == "__main__":
    unittest.main()
