#!/usr/bin/env python3
"""Regressao: agentes nao podem usar VM origem excluida como destino ativo.

Contrato documental (nao comprova OCI lifecycle, MCP autenticado nem cutover E2E).
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
TOPOLOGY = ROOT / "docs/knowledge/current-topology-and-access-2026-10-29.md"
HOSTS = ROOT / "docs/knowledge/host-access.md"
BOOTSTRAPS = (
    "AGENTS.md",
    "CLAUDE.md",
    "GEMINI.md",
    "docs/AGENTS.md",
    "docs/HOST-ACCESS.md",
    "docs/AGENT-MCP-REMOTE.md",
    "docs/AGENT-REMOTE-ACCESS.md",
    "docs/knowledge/README.md",
    "docs/knowledge/atendimento-chatgpt-bootstrap.md",
    "docs/knowledge/dev-agent-briefing.md",
    "docs/knowledge/claude-vm-bootstrap.md",
    "AGENTS-VM-ACCESS.md",
    "docs/operations/oci-agent-access.md",
    ".codex/RECOVERY-MEMORY.md",
)


class ActiveTopologyDocs(unittest.TestCase):
    def test_access_and_mcp_states_are_explicit(self):
        text = TOPOLOGY.read_text(encoding="utf-8")
        for marker in (
            "marina-a1-4c24g", "always-free-arm-1787907847-26",
            "shopvivaliz-free-a1", "No route to host",
            "ESPOSA", "PRINCIPAL", "Remote Desktop Commander",
            "ShopVivaliz Remote Control", "marina-direct-mcp",
            "INATIVOS", "NAO INSTALADO/NAO CONECTADO",
            "shopvivaliz-cloudflared-admin.service", "lifecycle",
            "nao inclui Marina",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker.lower(), text.lower())

    def test_canonical_active_host_table_uses_marina(self):
        content = HOSTS.read_text(encoding="utf-8")
        active = content.split("## Hosts operacionais atuais", 1)[1].split(
            "<!-- MARINA_DIRECT_RDC_V1 -->", 1
        )[0]
        self.assertIn("marina-a1-4c24g", active)
        self.assertIn("always-free-arm-1787907847-26", active)
        self.assertIn("Origem retirada", active)
        self.assertNotIn("| `shopvivaliz-free-a1` |", active)
        self.assertNotIn("site/web/deploy de produção |", active)

    def test_all_agent_bootstraps_link_current_roles(self):
        for path in BOOTSTRAPS:
            with self.subTest(file=path):
                content = (ROOT / path).read_text(encoding="utf-8")
                self.assertIn("marina-a1-4c24g", content)
                self.assertIn("always-free-arm-1787907847-26", content)

    def test_mcp_limitations_fail_closed(self):
        text = (ROOT / "docs/AGENT-MCP-REMOTE.md").read_text(encoding="utf-8")
        self.assertIn("nao inclui Marina", text)
        self.assertIn("inativos", text)
        legacy = (ROOT / "docs/REMOTE-ACCESS-GITHUB.md").read_text(encoding="utf-8")
        self.assertIn("HISTORICAS", legacy)
        self.assertIn("Nao acionar nem recriar a origem", legacy)


if __name__ == "__main__":
    unittest.main()
