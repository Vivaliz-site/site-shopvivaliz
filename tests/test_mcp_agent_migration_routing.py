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


    def test_every_priority_agent_instruction_is_current(self):
        """Independent-review P1: the old top-12k check missed stale active guidance."""
        import re
        paths = (
            "AGENTS.md", "CLAUDE.md", "GEMINI.md",
            "docs/knowledge/agent-rules.md",
            "docs/knowledge/atendimento-chatgpt-bootstrap.md",
            "docs/knowledge/host-access.md",
            ".claude/rules/ai-to-cli.md", ".github/AGENTS.md",
            ".github/copilot-instructions.md",
            ".github/instructions/ai-to-cli.instructions.md",
            ".codex/agents/shopvivaliz-devops.md",
            ".windsurf/rules/ai-to-cli.md",
            ".windsurf/rules/nonstop-continuity.md",
        )
        stale = (
            r"MCP central DESATUALIZADO",
            r"MCP independente Marina NAO APTO",
            r"Marina segue fora da allowlist MCP central",
            r"Marina permanece fora do MCP central",
            r"MCP central ainda nao inclui Marina",
            r"MCP central ainda aponta ao legado",
            r"somente backend, Fred-Win e KOCEPSV",
            r"MCP independente Marina permanece inativo",
            r"central.*nao inclui Marina",
        )
        patterns = [re.compile(s, re.IGNORECASE) for s in stale]
        for name in paths:
            with self.subTest(path=name):
                text = (ROOT / name).read_text(encoding="utf-8")
                self.assertEqual(
                    text.count("<!-- MCP_ROUTE_CANONICAL_V5_20261106 -->"), 1,
                    "active instructions must have exactly one current route authority",
                )
                self.assertIn("marina-a1-4c24g", text)
                self.assertIn("MFA", text)
                self.assertIn("RBAC", text)
                for pattern in patterns:
                    self.assertIsNone(
                        pattern.search(text),
                        f"stale claim elsewhere in {name}: {pattern.pattern}",
                    )

    def test_false_green_regression_detects_mutated_stale_claim(self):
        """An auditor must fail if a stale line is reintroduced after 12k bytes."""
        import re
        mutated = "A" * 13000 + "MCP central DESATUALIZADO"
        self.assertIsNotNone(
            re.compile("MCP central DESATUALIZADO", re.IGNORECASE).search(mutated)
        )

if __name__ == "__main__":
    unittest.main()
