#!/usr/bin/env python3
import copy
import importlib.util
import json
from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]
validator_path=ROOT/"scripts/validate-mcp-admin-workflow-audit.py"
module_spec=importlib.util.spec_from_file_location("mcp_admin_workflow_audit",validator_path)
assert module_spec and module_spec.loader
validator=importlib.util.module_from_spec(module_spec)
module_spec.loader.exec_module(validator)
EXPECTED={r["repository"] for r in json.loads((ROOT/"docs/ops/mcp-admin-repositories-2026-11-01.json").read_text())["repositories"]}
AUDIT=json.loads((ROOT/"docs/ops/mcp-admin-workflow-source-audit-2026-11-01.json").read_text())

class WorkflowSourceReconciliationTests(unittest.TestCase):
    def test_complete_evidence_only_snapshot(self):
        self.assertEqual(validator.validate(AUDIT,expected_repositories=EXPECTED),
                         (True,"valid_discovery_only_workflow_audit"))
        self.assertEqual(len(AUDIT["repositories"]),11)
        self.assertEqual(sum(len(x["active_registered_not_in_main_paths"]) for x in AUDIT["repositories"]),345)

    def test_workflow_sources_are_not_mcp_executors(self):
        for row in AUDIT["repositories"]:
            self.assertEqual(row["execution_state"],"NOT_CERTIFIED")
            self.assertFalse(row["mcp_executable"])
            self.assertEqual(row["main_workflow_files_total"],
                             len(row["active_registered_in_main_paths"]) +
                             len(row["inactive_registered_in_main_paths"]) +
                             len(row["main_not_registered_paths"]))

    def test_admin_catalog_cannot_activate_workflows_by_edit(self):
        mutated=copy.deepcopy(AUDIT)
        mutated["repositories"][0]["mcp_executable"]=True
        self.assertFalse(validator.validate(mutated,expected_repositories=EXPECTED)[0])
        mutated=copy.deepcopy(AUDIT)
        mutated["execution_state"]="APTO"
        self.assertFalse(validator.validate(mutated,expected_repositories=EXPECTED)[0])
        mutated=copy.deepcopy(AUDIT)
        mutated["repositories"][0]["main_sha"]="unknown"
        self.assertFalse(validator.validate(mutated,expected_repositories=EXPECTED)[0])

    def test_anti_alias_and_bad_paths(self):
        mutated=copy.deepcopy(AUDIT)
        mutated["repositories"][0]["repository"]="shopvivaliz-free-a1"
        self.assertFalse(validator.validate(mutated,expected_repositories=EXPECTED)[0])
        mutated=copy.deepcopy(AUDIT)
        chosen=next(row for row in mutated["repositories"] if row["active_registered_not_in_main_paths"])
        chosen["active_registered_not_in_main_paths"][0]="../../inactive-workflow.yml"
        self.assertFalse(validator.validate(mutated,expected_repositories=EXPECTED)[0])

    def test_invalid_type_rejected_without_exception(self):
        for key in ("active_registered_in_main_paths","registered_workflows_total","main_sha"):
            bad=copy.deepcopy(AUDIT)
            bad["repositories"][0][key]=[]
            ok,_=validator.validate(bad,expected_repositories=EXPECTED)
            self.assertFalse(ok,key)

if __name__=="__main__":
    unittest.main()
