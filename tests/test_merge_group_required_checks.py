"""Native GitHub merge-queue checks must be real, universal and fail-closed."""
from pathlib import Path
import re
import unittest
WF=Path(__file__).resolve().parents[1]/".github"/"workflows"

class RequiredQueueChecks(unittest.TestCase):
    def test_required_contexts_run_for_queued_merge_group(self):
        for name,context in (("mandatory-validation-gate.yml","  validation-gate:"),
                             ("repository-governance.yml","name: Audit repository automation")):
            with self.subTest(name=name):
                s=(WF/name).read_text()
                self.assertRegex(s,r"(?m)^  merge_group:\n    types: \[checks_requested\]$")
                self.assertIn("  pull_request:",s)
                self.assertIn("  push:",s)
                self.assertIn(context,s)
                self.assertIn("persist-credentials: false",s)
    def test_synthetic_commit_gets_complete_validation(self):
        gate=(WF/"mandatory-validation-gate.yml").read_text()
        self.assertIn("github.event.pull_request.head.sha || github.sha",gate)
        self.assertIn("mode=full",gate)
        self.assertIn("push_full_validation_required=true",gate)
        self.assertIn("bash scripts/repository-governance-validate.sh ci",gate)
        gov=(WF/"repository-governance.yml").read_text()
        self.assertIn("inputs.head_sha || github.sha",gov)
        self.assertIn("git merge-base HEAD origin/main",gov)

if __name__=="__main__":
    unittest.main()
