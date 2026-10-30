#!/usr/bin/env python3
"""Prioridade de acesso por host: backend via MCP, Marina via RDC verificado.

Nao impor proibição global de Desktop Commander: a Marina foi comprovadamente
acessada por RDC em 2026-10-29 enquanto a allowlist MCP central nao a inclui.
"""
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
    ROOT / "docs" / "AGENT-MCP-REMOTE.md",
)
LEGACY_CLAUDE_ACCESS_DOCS = (
    ROOT / "CLAUDE-REMOTE-CONFIG.md",
    ROOT / "CLAUDE-SETUP-COMPLETE.md",
    ROOT / "SETUP-CLAUDE-REMOTO.md",
)


class RemoteControlMcpPriorityPolicyTest(unittest.TestCase):
    def test_active_agent_access_docs_document_backend_mcp_and_marina_target(self) -> None:
        for path in ACTIVE_ACCESS_DOCS:
            text = path.read_text(encoding="utf-8")
            with self.subTest(path=str(path.relative_to(ROOT))):
                self.assertIn("always-free-arm-1787907847-26", text)
                self.assertIn("marina-a1-4c24g", text)
                self.assertTrue(
                    "Remote Control MCP" in text or "MCP central" in text,
                    "Acesso MCP central deve permanecer documentado",
                )

    def test_backend_first_and_marina_direct_route_fail_closed(self) -> None:
        remote_access = (ROOT / "docs" / "AGENT-REMOTE-ACCESS.md").read_text(
            encoding="utf-8"
        )
        self.assertIn("1. **Remote Control MCP:**", remote_access)
        self.assertIn("2. **SSH privado/Tailscale:**", remote_access)
        self.assertIn("3. **GitHub Actions/OCI Bastion:**", remote_access)
        self.assertIn("4. **GUI/validação visual:**", remote_access)
        self.assertIn("nao inclui Marina", remote_access)

        current = (ROOT / "docs" / "knowledge" /
                   "current-topology-and-access-2026-10-29.md").read_text(
                       encoding="utf-8"
                   )
        self.assertIn("Remote Desktop Commander", current)
        self.assertIn("RDC 1/2", current)
        self.assertIn("nao inclui Marina", current)
        self.assertIn("NAO INSTALADO/NAO CONECTADO", current)

        host_access = (ROOT / "docs" / "knowledge" / "host-access.md").read_text(
            encoding="utf-8"
        )
        self.assertIn("Remote Control MCP", host_access)
        self.assertIn("Remote Desktop Commander", host_access)
        self.assertIn("Origem retirada", host_access)

    def test_legacy_claude_access_guides_are_explicitly_superseded(self) -> None:
        for path in LEGACY_CLAUDE_ACCESS_DOCS:
            text = path.read_text(encoding="utf-8")
            with self.subTest(path=str(path.relative_to(ROOT))):
                self.assertIn("SUPERSEDED", text[:600])
                self.assertIn("Remote Control MCP", text[:1200])
                self.assertIn("docs/knowledge/host-access.md", text[:1200])


if __name__ == "__main__":
    unittest.main()
