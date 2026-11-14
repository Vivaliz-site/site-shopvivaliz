#!/usr/bin/env python3
"""Garante MCP admin concreto e diagnostico sem bloqueio falso nos entrypoints."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
MARKER = "MCP_ADMIN_CAPABILITY_BEFORE_BLOCK_20261114"
ENTRYPOINTS = (
    "AGENTS.md",
    "CLAUDE.md",
    "GEMINI.md",
    "REGRAS-AGENTES-CENTRALIZADAS.md",
    "AGENTS-ACCESS-INDEX.md",
    "docs/knowledge/atendimento-chatgpt-bootstrap.md",
    "docs/knowledge/agent-rules.md",
    "docs/knowledge/host-access.md",
    "docs/knowledge/README.md",
    "docs/ops/mcp-first-agent-execution-2026-11-05.md",
)


class McpAdminNoFalseBlockContractTest(unittest.TestCase):
    def test_entrypoints_explain_admin_mcp_before_declaring_blocked(self):
        for rel in ENTRYPOINTS:
            with self.subTest(path=rel):
                s = (ROOT / rel).read_text(encoding="utf-8")
                self.assertIn("<!-- " + MARKER + " -->", s)
                self.assertIn("<!-- /" + MARKER + " -->", s)
                self.assertIn("MCP", s)

    def test_claude_agent_entrypoints_remain_byte_for_byte_identical(self):
        self.assertEqual((ROOT / "AGENTS.md").read_bytes(),
                         (ROOT / "CLAUDE.md").read_bytes())

    def test_runbook_proves_capability_not_only_listing(self):
        s = (ROOT / "docs/ops/mcp-first-agent-execution-2026-11-05.md").read_text()
        for expected in (
            "admin_command_run", "task_submit", "task_status",
            "marina_admin_shell", "as_user", "HOST_MCP_ADMIN",
            "APP_AUTH", "commercial_request", "target_margin_apply",
            "scripts/amazon-margin/run.php --next", "/auth/login.php",
            "exit_code=0", "readback",
        ):
            with self.subTest(expected=expected):
                self.assertIn(expected, s)

    def test_no_login_bypass_or_implied_commercial_authorization(self):
        s = (ROOT / "docs/ops/mcp-first-agent-execution-2026-11-05.md").read_text()
        for expected in ("CSRF", "MFA", "401/403", "RBAC", "P2", "journal"):
            with self.subTest(expected=expected):
                self.assertIn(expected, s)
        self.assertIn("acesso root/ubuntu por MCP nao equivale a sessao admin", s)

    def test_historical_access_index_is_labeled_superseded(self):
        s = (ROOT / "AGENTS-ACCESS-INDEX.md").read_text()
        self.assertNotIn("Estado de runtime posterior, prevalente (2026-10-29)", s)
        self.assertNotIn("Regra vigente para todos os agentes (2026-10-29)", s)
        self.assertIn("REGISTRO HISTORICO SUPERADO", s[:3500])


if __name__ == "__main__":
    unittest.main()
