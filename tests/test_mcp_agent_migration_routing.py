"""Regression guard for the current four-host MCP routing after VM cutover.

This test checks operational instructions, not authorization. The live schema,
owner grant and runtime identity are independently verified by MCP tests.
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs/ops/mcp-first-agent-execution-2026-11-05.md"
ENTRYPOINTS = (ROOT / "AGENTS.md", ROOT / "CLAUDE.md", ROOT / "GEMINI.md")
HOSTS = (
    "always-free-arm-1787907847-26",
    "Fred-Win",
    "KOCEPSV",
    "marina-a1-4c24g",
)
OBSOLETE_PHRASES = (
    "Backend/Fred-Win/KOCEPSV: `shopvivaliz-remote-control`",
    "opera `always-free-arm-1787907847-26`, `Fred-Win` e `KOCEPSV`. Para `marina-a1-4c24g`",
)


class McpAgentMigrationRoutingTests(unittest.TestCase):
    def test_mcp_first_runbook_announces_all_four_live_hosts(self):
        runbook = DOC.read_text(encoding="utf-8")
        section = runbook.split("## Fluxo que deve ser seguido", 1)[1].split(
            "## Tratamento de limite foreground", 1
        )[0]
        for host in HOSTS:
            with self.subTest(host=host):
                self.assertIn(host, section)
        self.assertIn("MCP central", section)
        self.assertIn("grants", section)
        self.assertIn("schema real da sessao", section)
        self.assertIn("shopvivaliz-free-a1", section)
        for stale in OBSOLETE_PHRASES:
            self.assertNotIn(stale, section)

    def test_entrypoints_do_not_claim_marina_is_outside_central_mcp(self):
        for path in ENTRYPOINTS:
            with self.subTest(path=path.name):
                text = path.read_text(encoding="utf-8")
                top = text[:12000]
                self.assertIn("marina-a1-4c24g", top)
                self.assertIn("shopvivaliz-remote-control", top)
                self.assertIn("schema", top)
                for stale in OBSOLETE_PHRASES:
                    self.assertNotIn(stale, top)

    def test_protections_and_execution_validation_remain_required(self):
        doc = DOC.read_text(encoding="utf-8")
        for term in (
            "MFA", "RBAC", "grants", "task_submit", "task_status",
            "exit_code", "readback", "controller_response_indeterminate",
            "MCP_NOT_AUTHORIZED", "401/403",
        ):
            with self.subTest(term=term):
                self.assertIn(term, doc)
        self.assertIn("nao deve ser acessado", doc)


if __name__ == "__main__":
    unittest.main()
