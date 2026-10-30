#!/usr/bin/env python3
"""Fail-closed contract for workflows that still refer to the deleted site VM.

Historical references remain in source for traceability. No such job may run
until its target, identity, leases, tests and release are migrated independently.
"""
from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1] / ".github" / "workflows"

# Job-level fail-closed is intentional. These are not replacements for Marina
# deployment, product audits, restored Windows sessions or Amazon SAFE-T.
GUARDED_JOBS = {
    "production-functional-audit.yml": "functional",
    "mlrr-production-ops-bridge.yml": "operate",
    "google-ads-rest-audit.yml": "audit",
    "dc-four-host-login-once.yml": "login",
    "recover-shopvivaliz-runner.yml": "recover",
    "abandoned-cart-cron-install.yml": "install-cron",
    "desktop-commander-peer-repair.yml": "repair-peer",
    "linux-desktop-commander-stage-protection.yml": "stage",
    "amazon-returns-safet-recovery.yml": "recover-and-verify",
    "production-runner-rescue.yml": "rescue",
    "backend-disk-hygiene-reconcile.yml": "reconcile",
    "oci-site-runner-recovery.yml": "recover",
    "oci-principal-controller-downsize.yml": "resize",
    "refresh-a1-delete-repo-scope-once.yml": "refresh",
    "ml-pricing-production-bootstrap.yml": "bootstrap",
    "production-runner-bastion-rescue.yml": "rescue",
    "amazon-support-reply-oci-breakglass.yml": "reply",
    "oci-bastion-private-access-bootstrap.yml": "bootstrap",
    "desktop-commander-24h-health.yml": "runtime",
    "desktop-commander-reauth-runner.yml": "reauth",
}


class RetiredSourceWorkflows(unittest.TestCase):
    def test_legacy_jobs_must_be_fail_closed(self) -> None:
        for filename, job in GUARDED_JOBS.items():
            with self.subTest(filename=filename, job=job):
                source = (ROOT / filename).read_text(encoding="utf-8")
                self.assertIn("shopvivaliz-free-a1", source, "must be legacy-only")
                marker = f"  {job}:\n"
                self.assertIn(marker, source)
                first = source.split(marker, 1)[1].splitlines()[:14]
                prefix = "\n".join(first)
                self.assertIn("if:", prefix, "no job-level authorization guard")
                self.assertTrue(
                    "if: ${{ false }}" in prefix or "false &&" in prefix,
                    "retired-host workflow must be impossible to schedule",
                )

    def test_official_backend_route_denies_retired_target_before_credentials(self) -> None:
        source = (ROOT / "shopvivaliz-remote-access.yml").read_text(encoding="utf-8")
        self.assertIn('if target == "shopvivaliz-free-a1":', source)
        self.assertIn('raise SystemExit("RETIRED_SOURCE_TARGET_DENIED")', source)
        self.assertLess(
            source.index('raise SystemExit("RETIRED_SOURCE_TARGET_DENIED")'),
            source.index("Configure backend SSH"),
        )
        self.assertIn("gemini_24x7_controller_install)", source)

    def test_backend_mcp_install_remains_live_but_four_host_legacy_job_is_disabled(self) -> None:
        source = (ROOT / "remote-control-mcp-bootstrap.yml").read_text(encoding="utf-8")
        self.assertIn("RETIRED_SOURCE_JOB_GUARD_20261029", "\n".join(
            (ROOT / "shopvivaliz-remote-access.yml").read_text(encoding="utf-8").splitlines()
        ))
        self.assertIn("DECOMMISSIONED_SOURCE_GUARD_20261029", source)
        self.assertIn("if: ${{ false }}", source)
        self.assertIn("backend_controller_only:", source)


if __name__ == "__main__":
    unittest.main()
