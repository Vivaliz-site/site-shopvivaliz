#!/usr/bin/env python3
"""Regression: ShopVivaliz agents must see MCP-first real-invocation instructions."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]

class McpFirstContractTests(unittest.TestCase):
    def test_canonical_contract(self):
        c = (ROOT / "docs/ops/mcp-first-agent-execution-2026-11-05.md").read_text()
        for phrase in ("PRIMEIRA rota executavel", "MCP_ATTEMPTED_OK", "MCP_NOT_EXPOSED",
                       "MCP_ATTEMPTED_FAILED", "MCP_NOT_AUTHORIZED", "task_status",
                       "ZDesigner server 2", "Cloudflare Access", "remote_access_attempts"):
            with self.subTest(phrase=phrase):
                self.assertIn(phrase, c)

    def test_propagated_to_agent_entrypoints(self):
        anchors = {
            "AGENTS.md": "REGRA DO PROPRIETARIO: MCP PRIMEIRO",
            "CLAUDE.md": "PRIORIDADE MCP DO OWNER",
            "GEMINI.md": "PRIORIDADE MCP DO OWNER",
            "docs/knowledge/atendimento-chatgpt-bootstrap.md": "PRIORIDADE MCP ADMINISTRATIVO",
            "docs/knowledge/agent-rules.md": "PRIORIDADE MCP ADMINISTRATIVO",
        }
        for file, marker in anchors.items():
            with self.subTest(file=file):
                content = (ROOT / file).read_text()
                self.assertIn(marker, content[:3200])
                self.assertIn("mcp-first-agent-execution-2026-11-05.md", content)

    def test_not_catalog_only_or_mcp_security_bypass(self):
        c = (ROOT / "docs/ops/mcp-first-agent-execution-2026-11-05.md").read_text()
        self.assertIn("Nao basta mencionar MCP", c)
        self.assertIn("sem contornar auth", c)
        self.assertIn("MCP_NOT_APPLICABLE", c)
        self.assertIn("MCP_NOT_EXPOSED", c)
        self.assertIn("nunca escrever", c)
        self.assertIn("Catalogo da sessao", c)

if __name__ == "__main__":
    unittest.main()
