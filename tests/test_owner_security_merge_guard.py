from pathlib import Path
import unittest

class SecurityMergeGuardTest(unittest.TestCase):
    def test_owner_security_files_denied_before_merge(self):
        script=(Path(__file__).resolve().parents[1]/"scripts/merge_green_pr_via_vm.sh").read_text()
        self.assertIn("security/owner-approvals/*",script)
        self.assertIn("security/requests/*",script)
        self.assertIn("owner-protected PR must not be autonomously merged",script)
        self.assertLess(script.index("changed_files="),script.index("result=\"$(gh api --method PUT"))

if __name__=="__main__": unittest.main()
