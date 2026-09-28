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


if __name__ == "__main__":
    unittest.main()
