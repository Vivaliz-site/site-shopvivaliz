import json
from pathlib import Path
import unittest

ROOT=Path(__file__).resolve().parents[1]
CONF=ROOT/"config/github-main-merge-queue-p2.json"
WF=ROOT/".github/workflows"

class GitHubMergeQueueP2ScopeTests(unittest.TestCase):
    def test_exact_queue_parameters_and_no_bypass(self):
        d=json.loads(CONF.read_text())
        self.assertEqual(d["repository"],"Vivaliz-site/site-shopvivaliz")
        self.assertEqual(d["ruleset_id"],24523478)
        self.assertTrue(d["owner_review_required"])
        self.assertTrue(d["preserve_existing_rules"])
        self.assertEqual(d["preserve_bypass_actors"],[])
        self.assertEqual(d["required_status_checks"],["Audit repository automation","validation-gate"])
        self.assertEqual(d["merge_queue_rule"],{"type":"merge_queue","parameters":{
            "check_response_timeout_minutes":40,
            "grouping_strategy":"ALLGREEN",
            "max_entries_to_build":1,
            "max_entries_to_merge":1,
            "merge_method":"SQUASH",
            "min_entries_to_merge":1,
            "min_entries_to_merge_wait_minutes":0,
        }})

    def test_required_workflows_execute_on_merge_group(self):
        for path in ("mandatory-validation-gate.yml","repository-governance.yml"):
            with self.subTest(path=path):
                content=(WF/path).read_text()
                self.assertIn("  merge_group:\n    types: [checks_requested]",content)
        gate=(WF/"mandatory-validation-gate.yml").read_text()
        self.assertIn("Enforce human owner APPROVED review on exact protected PR HEAD",gate)
        self.assertIn("set -Eeuo pipefail",gate)

if __name__=="__main__":
    unittest.main()
