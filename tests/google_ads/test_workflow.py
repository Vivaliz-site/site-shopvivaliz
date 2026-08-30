import unittest
from pathlib import Path


WORKFLOW = Path(".github/workflows/google-ads-rest-audit.yml")


class WorkflowTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.text = WORKFLOW.read_text(encoding="utf-8")

    def test_invokes_reusable_readonly_cli(self):
        self.assertIn("scripts/google_ads/audit.py", self.text)
        self.assertNotIn("oauth2.googleapis.com/token", self.text)
        self.assertNotIn("googleAds:searchStream", self.text)

    def test_targets_only_new_site_a1(self):
        self.assertIn("163.176.103.253", self.text)
        self.assertNotIn("136.248.69.116", self.text)
        self.assertNotIn("137.131.156.17", self.text)

    def test_uses_known_hosts_and_strict_host_checking(self):
        self.assertIn("SHOPVIVALIZ_VM_KNOWN_HOSTS", self.text)
        self.assertIn("StrictHostKeyChecking=yes", self.text)
        self.assertNotIn("StrictHostKeyChecking=no", self.text)

    def test_discovers_release_and_env_without_old_checkout(self):
        self.assertIn("DocumentRoot", self.text)
        self.assertIn("readlink -f", self.text)
        self.assertNotIn("/home/ubuntu/site-shopvivaliz", self.text)

    def test_workflow_contains_no_ads_write_surface(self):
        lowered = self.text.lower()
        self.assertNotIn("googleads:mutate", lowered)
        self.assertNotIn("mutate_", lowered)


if __name__ == "__main__":
    unittest.main()
