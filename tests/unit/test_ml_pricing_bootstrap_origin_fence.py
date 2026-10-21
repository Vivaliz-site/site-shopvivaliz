from __future__ import annotations

import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
FENCE_GUARD = "test ! -e /etc/shopvivaliz/migration/origin-workers-fenced"


def workflow_step(name: str) -> str:
    workflow = (ROOT / ".github/workflows/ml-pricing-production-bootstrap.yml").read_text(encoding="utf-8")
    heading = f"      - name: {name}\n"
    if workflow.count(heading) != 1:
        raise AssertionError(f"Expected exactly one workflow step: {name}")
    following = workflow.split(heading, 1)[1]
    return following.split("\n      - name: ", 1)[0]


class PricingBootstrapOriginFenceTest(unittest.TestCase):
    def test_origin_ownership_checked_before_each_production_write(self) -> None:
        # Every potentially mutating step needs its own fail-closed guard.
        # A gate in the first step alone races against transfer of ownership.
        guarded_steps = {
            "Validate canonical production target": 'test -n "${CF_TOKEN:-}"',
            "Publish proxied production DNS": 'auth=(-H "Authorization:',
            "Bootstrap HTTP and bounded workers": "sudo -n /home/ubuntu/ml-pricing-api-deploy/current/ops/deploy/bootstrap-host.sh",
            "Obtain or renew origin certificate with DNS-01": "if ! dpkg-query",
            "Enable TLS vhost and services": "sudo -n /home/ubuntu/ml-pricing-api-deploy/current/ops/deploy/bootstrap-host.sh",
            "Enable Cloudflare proxy": 'zone_id="${{ steps.dns.outputs.zone_id }}"',
            "Roll back new DNS record on failure": 'zone_id="${{ steps.dns.outputs.zone_id }}"',
        }
        for name, first_operation in guarded_steps.items():
            with self.subTest(step=name):
                block = workflow_step(name)
                self.assertIn(FENCE_GUARD, block, f"{name} lacks origin fence")
                self.assertIn(first_operation, block)
                self.assertLess(
                    block.index(FENCE_GUARD),
                    block.index(first_operation),
                    f"{name} checks origin ownership only after beginning work",
                )

    def test_fence_does_not_interfere_with_local_read_only_health(self) -> None:
        block = workflow_step("Verify public production health")
        self.assertIn("PUBLIC_HEALTH_OK=1", block)
        self.assertNotIn("systemctl start", block)


if __name__ == "__main__":
    unittest.main()
