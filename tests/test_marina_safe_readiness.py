"""Static, non-operational readiness contracts for the Marina migration."""
from __future__ import annotations

from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[1]


class MarinaSafeReadinessTests(unittest.TestCase):
    def test_only_marina_runner_can_reach_editorial_or_email_execution(self) -> None:
        gate = 'test "$(hostname -s)" = "marina-a1-4c24g"'
        workflows = {
            "blog": ROOT / ".github/workflows/blog-publish-scheduled.yml",
            "product": ROOT / ".github/workflows/product-highlights-email.yml",
        }

        for name, path in workflows.items():
            with self.subTest(workflow=name):
                workflow = path.read_text(encoding="utf-8")
                self.assertIn(gate, workflow)
                self.assertLess(workflow.index(gate), workflow.index('install -d -m 700 "$HOME/.ssh"'))

        publishers = {
            "blog": '"${SSH[@]}" \'set -euo pipefail; cd /home/ubuntu/shopvivaliz-deploy/current;',
            "product": "scripts/run-product-highlights-campaign.php",
        }
        for name, publisher in publishers.items():
            with self.subTest(publisher=name):
                workflow = workflows[name].read_text(encoding="utf-8")
                self.assertLess(workflow.index(gate), workflow.index(publisher))

    def test_pricing_certificate_repair_is_marina_only_and_non_issuing(self) -> None:
        workflow = (ROOT / ".github/workflows/ml-pricing-certbot-renewal-repair.yml").read_text(encoding="utf-8")

        self.assertIn('test "$(hostname -s)" = "marina-a1-4c24g"', workflow)
        self.assertNotIn("certbot renew", workflow)
        self.assertNotIn("dns_records", workflow)

    def test_migration_record_labels_readiness_without_claiming_acceptance(self) -> None:
        record = (ROOT / "docs/ops/oci-marina-migration-v5-20261017.md").read_text(encoding="utf-8")

        self.assertIn("BLOG_ORIGIN_FENCE_WORKFLOW=FAIL_CLOSED", record)
        self.assertIn("MARINA_CERT_REPAIR_WORKFLOW=BLOCKED_RUNNER_NOT_PROVISIONED", record)
        self.assertIn("GLOBAL_MIGRATION_STATUS=NAO_APTO", record)
        self.assertIn("NO_DECOMMISSION", record)


if __name__ == "__main__":
    unittest.main()
