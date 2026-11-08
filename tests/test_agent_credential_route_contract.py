"""Regression checks: agents must discover installed credential routes before blocker claims."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
FILES = (
    "AGENTS.md",
    "docs/knowledge/agent-rules.md",
    "docs/ops/mcp-first-agent-execution-2026-11-05.md",
)

class CredentialRouteContract(unittest.TestCase):
    def test_each_agent_entrypoint_has_route_discovery(self):
        for name in FILES:
            with self.subTest(file=name):
                content=(ROOT/name).read_text(encoding="utf-8")
                self.assertIn("OWNER_CREDENTIAL_ROUTE_DISCOVERY_20261107" if name != FILES[2] else "OWNER_EXISTING_CREDENTIAL_DISCOVERY_20261107", content)
                self.assertIn("shopvivaliz-remote-access.yml",content)
                self.assertIn("42429462524",content)
                self.assertIn("401/403",content)

    def test_mcp_required_prior_to_other_routes(self):
        doc=(ROOT/"AGENTS.md").read_text(encoding="utf-8")
        self.assertIn("MCP PRIMEIRO",doc)
        self.assertIn("task_id",doc)
        self.assertIn("CLOUDFLARE_ACCESS_APPS_READ=true",doc)

    def test_policies_distinguish_read_and_write(self):
        doc=(ROOT/"docs/ops/mcp-first-agent-execution-2026-11-05.md").read_text(encoding="utf-8")
        self.assertIn("write_authorized",doc)
        self.assertIn("nao evidencia de poder para editar",doc)
        self.assertIn("Nao usar Opera",doc)

if __name__ == "__main__":
    unittest.main()
