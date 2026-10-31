from __future__ import annotations

import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

import agent_docs_gate as gate  # noqa: E402


class AgentDocsGateTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.original_root = gate.ROOT
        self.original_runtime = gate.RUNTIME_DIR
        self.original_required = gate.REQUIRED_DOCS

        gate.ROOT = self.root
        gate.RUNTIME_DIR = self.root / "storage" / "private" / "agent-docs-read"
        gate.REQUIRED_DOCS = (
            "REGRAS-AGENTES-CENTRALIZADAS.md",
            "AGENTS.md",
            "docs/AGENT-DOCS-PREFLIGHT.md",
        )
        for relative in gate.REQUIRED_DOCS:
            path = self.root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(f"documento obrigatorio: {relative}\n", encoding="utf-8")

    def tearDown(self) -> None:
        gate.ROOT = self.original_root
        gate.RUNTIME_DIR = self.original_runtime
        gate.REQUIRED_DOCS = self.original_required
        self.temp.cleanup()

    def test_mandatory_topology_is_in_real_preflight(self) -> None:
        self.assertIn(
            "docs/knowledge/current-topology-and-access-2026-10-29.md",
            self.original_required,
        )

    def test_task_continuity_directives_are_mandatory_and_unambiguous(self) -> None:
        for relative in (
            "docs/knowledge/agent-rules.md",
            "docs/knowledge/task-continuity.md",
        ):
            with self.subTest(required_doc=relative):
                self.assertIn(relative, self.original_required)

        rules = (ROOT / "docs/knowledge/agent-rules.md").read_text(encoding="utf-8")
        self.assertIn("TASK_SESSION_CONTINUITY_V1", rules)
        self.assertIn("controlador de continuidade e contingencia", rules.lower())
        self.assertIn("nao sao prazo de validade", rules.lower())
        self.assertIn("read -> acknowledge -> verify", rules)

        continuity = (ROOT / "docs/knowledge/task-continuity.md").read_text(encoding="utf-8")
        self.assertIn("nao** prazo de encerramento", continuity)
        self.assertIn("TASK_SESSION_CONTINUITY_V1", continuity)

    def test_v5_audit_rules_are_in_all_agent_read_packets(self) -> None:
        for path in (
            "AUDIT_POLICY.md",
            "docs/quality/EXTREME_AUDIT_PROTOCOL.md",
            "docs/quality/AUDIT_APTO_REMEDIATION_LOOP_V1.md",
            "docs/quality/AUDIT_ABSOLUTE_GATE_V1.md",
            "docs/quality/AUDIT_MERGE_ENFORCEMENT_V1.md",
            "docs/quality/AUDIT_AGENT_PREFLIGHT_AND_PR_CONTINUITY_V1.md",
        ):
            with self.subTest(path=path):
                self.assertIn(path, self.original_required)

    def test_previous_session_receipt_expires_even_without_changes(self) -> None:
        import json
        packet = gate.deliver_read_packet("gpt", "outdated-v5")
        gate.acknowledge("gpt", "outdated-v5", packet["digest"])
        path = gate._receipt_path("gpt", "outdated-v5")
        data = json.loads(path.read_text(encoding="utf-8"))
        for stamped in ("2000-01-01T00:00:00Z", "2999-01-01T00:00:00Z"):
            data["acknowledged_at"] = stamped
            path.write_text(json.dumps(data), encoding="utf-8")
            verified, reason, _ = gate.verify_receipt("gpt", "outdated-v5")
            self.assertFalse(verified)
            self.assertIn("expired or future", reason)

    def test_v5_policy_change_invalidates_agent_receipt(self) -> None:
        path = self.root / "docs/quality/AUDIT_ABSOLUTE_GATE_V1.md"
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text("AUDIT_VERDICT=APTO guarded by certifier\\n", encoding="utf-8")
        gate.REQUIRED_DOCS = (*gate.REQUIRED_DOCS, "docs/quality/AUDIT_ABSOLUTE_GATE_V1.md")
        packet = gate.deliver_read_packet("claude", "v5-policy")
        gate.acknowledge("claude", "v5-policy", packet["digest"])
        path.write_text("AUDIT_VERDICT=APTO guarded by modified certifier\\n", encoding="utf-8")
        valid, reason, _ = gate.verify_receipt("claude", "v5-policy")
        self.assertFalse(valid)
        self.assertIn("changed", reason)

    def test_changed_topology_invalidates_receipt(self) -> None:
        topo_name = "docs/knowledge/current-topology-and-access-2026-10-29.md"
        gate.REQUIRED_DOCS = (*gate.REQUIRED_DOCS, topo_name)
        topo = self.root / topo_name
        topo.parent.mkdir(parents=True, exist_ok=True)
        topo.write_text("Marina production\n", encoding="utf-8")
        packet = gate.deliver_read_packet("codex", "topology")
        gate.acknowledge("codex", "topology", packet["digest"])
        valid, _, _ = gate.verify_receipt("codex", "topology")
        self.assertTrue(valid)
        topo.write_text("Marina production - source retired\n", encoding="utf-8")
        valid, reason, _ = gate.verify_receipt("codex", "topology")
        self.assertFalse(valid)
        self.assertIn("changed", reason)

    def test_every_agent_entrypoint_references_live_topology(self) -> None:
        entrypoints = [
          "AGENTS.md",
          "CLAUDE.md",
          "GEMINI.md",
          "docs/AGENTS.md",
          ".github/AGENTS.md",
          ".github/copilot-instructions.md",
          ".github/instructions/ai-to-cli.instructions.md",
          ".codex/agents/shopvivaliz-devops.md",
          ".claude/rules/ai-to-cli.md",
          ".cursor/rules/nonstop-continuity.mdc",
          ".windsurf/rules/ai-to-cli.md",
          ".windsurf/rules/nonstop-continuity.md",
          "AGENTS-ACCESS-INDEX.md",
          "docs/ai-agents-map.md",
          "docs/knowledge/agent-rules.md",
          "docs/knowledge/atendimento-chatgpt-bootstrap.md",
          "docs/knowledge/claude-vm-bootstrap.md",
          "docs/knowledge/dev-agent-briefing.md",
          ".ai/README.md"
]
        for relative in entrypoints:
            with self.subTest(agent_entrypoint=relative):
                content = (ROOT / relative).read_text(encoding="utf-8")
                self.assertIn("ACTIVE_MARINA_ROUTING_20261029", content)
                self.assertIn("docs/knowledge/current-topology-and-access-2026-10-29.md", content)

    def test_verify_fails_before_read(self) -> None:
        valid, reason, receipt = gate.verify_receipt("gpt", "task-001")
        self.assertFalse(valid)
        self.assertIn("missing docs receipt", reason)
        self.assertIsNone(receipt)

    def test_acknowledge_requires_prior_read(self) -> None:
        digest = gate.build_manifest()["digest"]
        with self.assertRaises(gate.DocsGateError):
            gate.acknowledge("claude", "task-002", digest)

    def test_read_acknowledge_verify_success(self) -> None:
        packet = gate.deliver_read_packet("gemini", "task-003")
        self.assertEqual(len(packet["documents"]), len(gate.REQUIRED_DOCS))
        self.assertTrue(all("content" in doc for doc in packet["documents"]))

        gate.acknowledge("gemini", "task-003", packet["digest"])
        valid, reason, receipt = gate.verify_receipt("gemini", "task-003")
        self.assertTrue(valid, reason)
        self.assertIsNotNone(receipt)
        self.assertTrue(receipt["acknowledged"])

    def test_receipt_becomes_stale_when_required_doc_changes(self) -> None:
        packet = gate.deliver_read_packet("gpt", "task-004")
        gate.acknowledge("gpt", "task-004", packet["digest"])

        policy = self.root / "REGRAS-AGENTES-CENTRALIZADAS.md"
        policy.write_text("policy changed after acknowledgement\n", encoding="utf-8")

        valid, reason, _ = gate.verify_receipt("gpt", "task-004")
        self.assertFalse(valid)
        self.assertIn("changed", reason)

    def test_scope_specific_document_is_bound_to_receipt(self) -> None:
        scope = self.root / "docs" / "scope.md"
        scope.write_text("scope rules\n", encoding="utf-8")
        packet = gate.deliver_read_packet("claude", "task-005", ["docs/scope.md"])
        gate.acknowledge("claude", "task-005", packet["digest"], ["docs/scope.md"])
        valid, reason, _ = gate.verify_receipt("claude", "task-005", ["docs/scope.md"])
        self.assertTrue(valid, reason)

        scope.write_text("scope rules changed\n", encoding="utf-8")
        valid, _, _ = gate.verify_receipt("claude", "task-005", ["docs/scope.md"])
        self.assertFalse(valid)

    def test_scope_document_cannot_escape_repository(self) -> None:
        with self.assertRaises(gate.DocsGateError):
            gate.build_manifest(["../outside.md"])


if __name__ == "__main__":
    unittest.main()
