from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "runtime-status-policy.sh"
LEGACY_SCRIPT = ROOT / "scripts" / "runtime-service-status.sh"
WORKFLOW = ROOT / ".github" / "workflows" / "shopvivaliz-remote-access.yml"


class RuntimeStatusPolicyContractTests(unittest.TestCase):
    def test_policy_aware_runtime_status_script_exists_and_is_valid_bash(self) -> None:
        self.assertTrue(SCRIPT.is_file(), "runtime_status must have a checked-in policy-aware diagnostic script")
        self.assertFalse(LEGACY_SCRIPT.exists(), "removed runtime-service-status.sh must not be resurrected")
        result = subprocess.run(
            ["bash", "-n", str(SCRIPT)],
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_remote_access_uses_same_policy_script_for_both_linux_roles(self) -> None:
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertGreaterEqual(workflow.count("scripts/runtime-status-policy.sh"), 2)
        self.assertIn("bash scripts/runtime-status-policy.sh site", workflow)
        self.assertIn("'bash -s -- backend' < scripts/runtime-status-policy.sh", workflow)
        self.assertNotIn("scripts/runtime-service-status.sh", workflow)

    def test_site_policy_tracks_current_required_services(self) -> None:
        body = SCRIPT.read_text(encoding="utf-8")
        for unit in (
            "apache2.service",
            "shopvivaliz-queue-worker.service",
            "shopvivaliz-token-renewer.service",
            "shopvivaliz-shopee-token-renewer.service",
            "shopvivaliz-agent.service",
            "shopvivaliz-catalog-reconcile.timer",
            "shopvivaliz-sync-safe.timer",
            "shopvivaliz-abandoned-cart-recovery.timer",
        ):
            self.assertIn(f"report_required_active {unit}", body)

        for unit in (
            "shopvivaliz-catalog-reconcile.service",
            "shopvivaliz-sync-safe.service",
        ):
            self.assertIn(f"report_oneshot_success {unit}", body)

    def test_backend_policy_tracks_current_chatgpt_runtime_and_stop_line(self) -> None:
        body = SCRIPT.read_text(encoding="utf-8")
        for marker in (
            "shopvivaliz-remote-control-mcp.service",
            "shopvivaliz-chatgpt-continuity.service",
            "http://127.0.0.1:5580/health",
            "http://127.0.0.1:9555/json/version",
            "/var/lib/mei-mg-email/sender_blocked.pause",
            "EXPECTED=inactive-sender-block",
            "EXPECTED=active-no-sender-block",
        ):
            self.assertIn(marker, body)

    def test_policy_emits_explicit_health_envelope(self) -> None:
        body = SCRIPT.read_text(encoding="utf-8")
        for marker in (
            'echo "RUNTIME_STATUS_BEGIN"',
            'echo "POLICY=runtime-status-policy-v1"',
            'echo "RUNTIME_HEALTH=$health"',
            'echo "RUNTIME_STATUS_END"',
        ):
            self.assertIn(marker, body)
        self.assertIn('if [ "$health" = "degraded" ]; then', body)
        self.assertIn("exit 1", body)

    def test_runtime_status_does_not_restore_legacy_control_plane_dependencies(self) -> None:
        body = SCRIPT.read_text(encoding="utf-8").lower()
        for forbidden in (
            "desktop-commander",
            "desktop commander",
            "shopvivaliz-mcp.service",
            "shopvivaliz-24x7",
            "agent-bridge",
            "remote desktop commander",
            "rustdesk",
            "anydesk",
        ):
            self.assertNotIn(forbidden, body)

    def test_optional_or_unrelated_services_do_not_become_required_health_gates(self) -> None:
        body = SCRIPT.read_text(encoding="utf-8")
        for optional in (
            "shopvivaliz-products-active-sync.service",
            "shopvivaliz-catalog-audit.service",
            "shopvivaliz-orchestrator.service",
            "shopvivaliz-desktop-commander.service",
        ):
            self.assertNotIn(f"report_required_active {optional}", body)


if __name__ == "__main__":
    unittest.main()
