#!/usr/bin/env python3
"""Reject false equivalence between RDC and unprovisioned MCP host capabilities."""
import copy
import importlib.util
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
PATH = ROOT / "scripts" / "validate-mcp-rdc-capability-parity.py"
DATA = ROOT / "docs" / "ops" / "mcp-rdc-capability-parity-2026-11-01.json"
spec = importlib.util.spec_from_file_location("parity", PATH)
assert spec and spec.loader
v = importlib.util.module_from_spec(spec)
spec.loader.exec_module(v)


class McpRdcParityMatrixTests(unittest.TestCase):
    def setUp(self):
        self.doc = json.loads(DATA.read_text(encoding="utf-8"))

    def test_complete_inventory_stays_discovery_only(self):
        self.assertEqual(v.validate(self.doc), (True, "discovery_only"))
        self.assertEqual(len(self.doc["capabilities"]), 29)
        self.assertEqual(self.doc["certification"], "NOT_CERTIFIED")

    def test_no_automated_promotion_to_certified(self):
        for value in ("APTO", "GREEN", "RUNTIME_VERIFIED"):
            with self.subTest(value=value):
                doc = copy.deepcopy(self.doc)
                doc["certification"] = value
                self.assertFalse(v.validate(doc)[0])
        doc = copy.deepcopy(self.doc)
        doc["capabilities"][0]["last_verified_e2e"] = "claimed"
        self.assertFalse(v.validate(doc)[0])

    def test_does_not_reintroduce_retired_host(self):
        doc = copy.deepcopy(self.doc)
        doc["target_hosts"] = ["shopvivaliz-free-a1"] + doc["target_hosts"][1:]
        self.assertFalse(v.validate(doc)[0])

    def test_no_fake_runtime_from_review_branch(self):
        for field, value in (
            ("runtime_state", "CONNECTED"),
            ("code_state", "DEPLOYED"),
            ("e2e", "PASS"),
            ("authorization_state", "AUTHORIZED"),
        ):
            doc = copy.deepcopy(self.doc)
            doc["service_state"]["central"]["marina"][field] = value
            self.assertFalse(v.validate(doc)[0])

    def test_all_rdc_methods_required(self):
        doc = copy.deepcopy(self.doc)
        doc["capabilities"].pop()
        self.assertFalse(v.validate(doc)[0])
        doc = copy.deepcopy(self.doc)
        doc["capabilities"][1]["rdc_method"] = doc["capabilities"][0]["rdc_method"]
        self.assertFalse(v.validate(doc)[0])

    def test_independent_route_is_not_controller_or_rdc(self):
        for field, value in (
            ("independent_marina", False),
            ("rdc_dependency_for_normal_operation", True),
            ("no_cross_host_relay", False),
            ("exact_owner_security_pr_required_for_new_grants", False),
        ):
            doc = copy.deepcopy(self.doc)
            doc["requirements"][field] = value
            self.assertFalse(v.validate(doc)[0])


if __name__ == "__main__":
    unittest.main()
