from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "runtime-service-status.sh"
WORKFLOW = ROOT / ".github" / "workflows" / "shopvivaliz-remote-access.yml"


class RuntimeServiceStatusContractTests(unittest.TestCase):
    def test_policy_aware_runtime_status_script_exists_and_is_valid_bash(self) -> None:
        self.assertTrue(SCRIPT.is_file(), "runtime_status must have a checked-in policy-aware diagnostic script")
        result = subprocess.run(
            ["bash", "-n", str(SCRIPT)],
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_remote_access_uses_same_checked_in_runtime_status_for_both_linux_roles(self) -> None:
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertGreaterEqual(workflow.count("scripts/runtime-service-status.sh"), 2)
        self.assertIn("bash scripts/runtime-service-status.sh site", workflow)
        self.assertIn("'bash -s -- backend' < scripts/runtime-service-status.sh", workflow)

    def test_site_policy_tracks_current_required_services_and_continuity(self) -> None:
        body = SCRIPT.read_text(encoding="utf-8")
        for unit in (
            "apache2.service",
            "shopvivaliz-queue-worker.service",
            "shopvivaliz-token-renewer.service",
            "shopvivaliz-shopee-token-renewer.service",
            "shopvivaliz-agent.service",
        ):
            self.assertIn(f"report_required_active {unit}", body)

        for marker in (
            "CHATGPT_CONTINUITY_QUEUE_CERTIFIED=",
            "CHATGPT_CONTINUITY_QUEUE_ACTIONABLE_ROWS=",
            "CHATGPT_CONTINUITY_NUDGE_LEDGER_PRESENT=",
            "AGENT_LAST_AUTONOMOUS_CYCLE_ERROR_AT=",
            "AGENT_LAST_WATCHDOG_COMPLETED_AT=",
            "AGENT_LAST_CHATGPT_DISPATCHER_COMPLETED_AT=",
            "AGENT_CONTINUITY_PATH_BLOCKED_BY_PRESTEP=",
        ):
            self.assertIn(marker, body)

    def test_backend_policy_tracks_only_current_chatgpt_runtime(self) -> None:
        body = SCRIPT.read_text(encoding="utf-8")
        self.assertIn("shopvivaliz-chatgpt-continuity.service", body)
        self.assertIn("CHATGPT_CONTINUITY_BACKEND_WORKER_ACTIVE=", body)
        self.assertIn("CHATGPT_CONTINUITY_CDP_REACHABLE=", body)
        self.assertIn("http://127.0.0.1:9555/json/version", body)

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
