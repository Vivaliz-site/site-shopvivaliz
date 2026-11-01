#!/usr/bin/env python3
"""Cross-repository administrative routine coverage inventory: metadata != authority."""
import copy
import importlib.util
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / "scripts" / "validate-mcp-admin-parity-inventory.py"
INVENTORY = ROOT / "docs" / "ops" / "mcp-admin-repositories-2026-11-01.json"
SPEC = importlib.util.spec_from_file_location("mcp_admin_parity_validator", MODULE)
assert SPEC is not None and SPEC.loader is not None
validator = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(validator)
REPOS = {
    "Vivaliz-site/site-shopvivaliz",
    "Vivaliz-site/amazon-returns-safet",
    "Vivaliz-site/demo-repository",
    "Vivaliz-site/shopvivaliz-m365",
    "Vivaliz-site/mercadolivre-returns-recovery",
    "Vivaliz-site/buscador",
    "Vivaliz-site/ml-pricing-api",
    "Vivaliz-site/-shopvivaliz-pipeline",
    "fredmourao-ai/solange-rolla-consultorio",
    "fredmourao-ai/solange-rolla",
    "fredmourao-ai/mei-mg-email",
}

class AdminParityInventoryTests(unittest.TestCase):
    def setUp(self):
        self.inventory = json.loads(INVENTORY.read_text(encoding="utf8"))

    def test_coverage_every_active_repository(self):
        self.assertEqual(set(x["repository"] for x in self.inventory["repositories"]), REPOS)
        self.assertEqual(len(self.inventory["repositories"]), 11)
        self.assertEqual((True, "valid_discovery_only_inventory"),
                         validator.validate(self.inventory))

    def test_discovery_never_implies_root_or_active_mcp(self):
        self.assertEqual(self.inventory["execution_state"], "DISCOVERY_ONLY")
        for repo in self.inventory["repositories"]:
            self.assertEqual(repo["execution_state"], "NOT_CERTIFIED")
            for action in repo["candidate_actions"]:
                self.assertIs(action["executable"], False)
                self.assertEqual(action["authorization"], "NOT_PROVISIONED")
                self.assertNotIn(action["host"], ["shopvivaliz-free-a1", "*"])
        self.assertEqual(next(x for x in self.inventory["repositories"]
                              if x["repository"] == "Vivaliz-site/demo-repository")["candidate_actions"], [])

    def test_declarative_admin_scope_must_not_enable_actions(self):
        for change in [
            ("execution_state", "APTO"),
            ("repository", "attacker/other"),
        ]:
            changed = copy.deepcopy(self.inventory)
            if change[0] == "execution_state":
                changed["execution_state"] = change[1]
            else:
                changed["repositories"][0]["repository"] = change[1]
            self.assertFalse(validator.validate(changed)[0])
        changed = copy.deepcopy(self.inventory)
        chosen = next(x for x in changed["repositories"] if x["candidate_actions"])
        chosen["candidate_actions"][0]["executable"] = True
        self.assertFalse(validator.validate(changed)[0])
        chosen["candidate_actions"][0]["executable"] = False
        chosen["candidate_actions"][0]["authorization"] = "AUTHORIZED"
        self.assertFalse(validator.validate(changed)[0])

    def test_no_generic_privileged_or_wrong_host_routing(self):
        changed = copy.deepcopy(self.inventory)
        chosen = next(x for x in changed["repositories"] if x["candidate_actions"])
        chosen["candidate_actions"][0]["host"] = "shopvivaliz-free-a1"
        self.assertFalse(validator.validate(changed)[0])
        chosen["candidate_actions"][0]["host"] = "marina-a1-4c24g"
        chosen["candidate_actions"][0]["action_id"] = "admin_command_run"
        self.assertFalse(validator.validate(changed)[0])

    def test_unhashable_payload_types_are_denied_not_crash(self):
        for key, value in [("host", []), ("kind", {}), ("risk", [])]:
            document = copy.deepcopy(self.inventory)
            chosen = next(x for x in document["repositories"] if x["candidate_actions"])
            chosen["candidate_actions"][0][key] = value
            ok, _ = validator.validate(document)
            self.assertFalse(ok, key)

    def test_source_commit_and_valid_path_required(self):
        changed = copy.deepcopy(self.inventory)
        chosen = next(x for x in changed["repositories"] if x["candidate_actions"])
        chosen["main_sha"] = "stale"
        self.assertFalse(validator.validate(changed)[0])
        changed = copy.deepcopy(self.inventory)
        chosen = next(x for x in changed["repositories"] if x["candidate_actions"])
        chosen["candidate_actions"][0]["source"] = "../../secrets.env"
        self.assertFalse(validator.validate(changed)[0])

if __name__ == "__main__":
    unittest.main()
