"""Prevent false claims of administrative parity when ChatGPT has a stale snapshot."""
from __future__ import annotations

import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / "scripts" / "ops" / "verify-mcp-published-client-catalog.py"
SPEC = importlib.util.spec_from_file_location("mcp_published_catalog_verifier", MODULE)
assert SPEC is not None and SPEC.loader is not None
m = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(m)


def specs(hosts=None):
    hosts = list(sorted(m.ACTIVE_HOSTS)) if hosts is None else hosts
    return {"result": {"tools": [
        {"name": name, "inputSchema": {
            "type": "object", "properties": {
                "host": {"type": "string", "enum": list(hosts)}
            }
        }}
        for name in m.ADMIN_TOOLS + m.OTHER_HOST_TOOLS
    ]}}


class PublishedMcpCatalogTests(unittest.TestCase):
    def setUp(self):
        self.server = specs()
        self.client = specs()

    def test_matching_published_admin_and_read_catalog_passes(self):
        self.assertEqual(m.compare(self.server, self.client), [])
        self.assertIn(m.MARINA_HOST, m.host_enum(m.tool_map(self.client)["admin_command_run"]))

    def test_stale_admin_schema_cannot_be_declared_ready(self):
        self.client["result"]["tools"][0]["inputSchema"]["properties"]["host"]["enum"].remove(m.MARINA_HOST)
        self.assertIn("client_schema_stale:admin_command_run",
                      m.compare(self.server, self.client))

    def test_retired_vm_exposed_by_old_orquestrador_is_rejected(self):
        self.client["result"]["tools"][0]["inputSchema"]["properties"]["host"]["enum"].append(m.RETIRED_HOST)
        problems = m.compare(self.server, self.client)
        self.assertIn("client_retired_host:admin_command_run", problems)
        self.assertIn("client_schema_stale:admin_command_run", problems)

    def test_missing_tool_even_when_hosts_list_is_healthy_fails(self):
        self.client["result"]["tools"].pop(1)
        self.client["result"]["tools"].append({"name": "hosts_list", "inputSchema": {}})
        self.assertIn("required_tool_missing:task_submit",
                      m.compare(self.server, self.client))

    def test_stale_readonly_tool_schema_also_fails(self):
        name = "file_read"
        target = next(x for x in self.client["result"]["tools"] if x["name"] == name)
        target["inputSchema"]["properties"]["host"]["enum"].remove(m.MARINA_HOST)
        self.assertIn("client_schema_stale:file_read", m.compare(self.server, self.client))

    def test_server_not_authorized_must_not_be_false_green(self):
        self.server["result"]["tools"][0]["inputSchema"]["properties"]["host"]["enum"].remove(m.MARINA_HOST)
        self.assertIn("server_active_host_contract_mismatch:admin_command_run",
                      m.compare(self.server, self.client))

    def test_bad_schema_is_a_failure_not_silent_success(self):
        del self.client["result"]["tools"][0]["inputSchema"]
        self.assertIn("admin_command_run:missing_input_schema",
                      m.compare(self.server, self.client))

    def test_duplicate_tool_records_and_host_enum_fail_closed(self):
        broken = copy.deepcopy(self.client)
        broken["result"]["tools"].append(copy.deepcopy(broken["result"]["tools"][0]))
        self.assertEqual(m.compare(self.server, broken),
                         ["missing_or_duplicate_tool_name"])
        target = self.client["result"]["tools"][0]["inputSchema"]["properties"]["host"]
        target["enum"].append(target["enum"][0])
        self.assertIn("admin_command_run:invalid_host_enum",
                      m.compare(self.server, self.client))

    def test_read_catalog_never_guesses_missing_client(self):
        with tempfile.TemporaryDirectory() as tmp:
            file = Path(tmp) / "catalog.json"
            file.write_text(json.dumps(self.server), encoding="utf-8")
            self.assertEqual(m.tool_map(m.read_catalog(file)).keys(),
                             m.tool_map(self.server).keys())
            with self.assertRaises(m.CatalogError):
                m.read_catalog(Path(tmp) / "not_exported.json")

    def test_all_agent_entrypoints_advertise_effective_client_state(self):
        paths = (
            "docs/knowledge/atendimento-chatgpt-bootstrap.md",
            "docs/knowledge/dev-chatgpt-bootstrap.md",
            "docs/knowledge/host-access.md",
            "docs/knowledge/README.md",
            "docs/knowledge/agent-rules.md",
            "docs/knowledge/chatgpt-access-parity.md",
            "docs/ops/mcp-central-marina-dual-route-2026-11-01.md",
        )
        for path in paths:
            with self.subTest(path=path):
                text = (ROOT / path).read_text(encoding="utf-8")
                first = text[:2100]
                self.assertIn("<!-- MCP_CLIENT_CATALOG_PARITY_20261106 -->", first)
                self.assertIn("CHATGPT_CLIENT_CATALOG_APTO=NAO_COMPROVADO", first)
                self.assertIn("mcp-client-catalog-publication-2026-11-06.md", first)
                self.assertIn("shopvivaliz-free-a1", first)
        runbook = (ROOT / "docs/ops/mcp-client-catalog-publication-2026-11-06.md").read_text(
            encoding="utf-8")
        self.assertIn("--server /tmp/mcp-server-tools.json", runbook)
        self.assertIn("--client /tmp/mcp-client-tools.json", runbook)
        self.assertIn("nao", runbook.lower())

    def test_no_server_inventory_can_certify_missing_client(self):
        self.assertNotEqual(m.compare(self.server, None), [])
        self.assertNotEqual(m.compare(None, self.client), [])


if __name__ == "__main__":
    unittest.main()
