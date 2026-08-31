from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKFLOWS = ROOT / ".github" / "workflows"

PUBLISH = (
    "agent-vm-readonly-diagnostics.yml",
    "apply-gsc-indexing-fix-20260824.yml",
    "fred-win-terminal.yml",
    "mei-email-graph-token-diagnostic.yml",
    "seo-durable-code.yml",
    "seo-durable-repair.yml",
)

AUTO_EVIDENCE = (
    "actions-run-index.yml",
    "desktop-commander-24h-health.yml",
    "desktop-commander-three-host-control-plane.yml",
    "desktop-commander-three-host-quick-probe.yml",
    "mei-email-prod-probe-now.yml",
    "mei-email-prod-probe-robust.yml",
    "vm-desktop-commander-connection-probe.yml",
    "windows-private-peer-recovery.yml",
)


class RepositoryWorkflowGovernanceRemediationTests(unittest.TestCase):
    def text(self, name: str) -> str:
        return (WORKFLOWS / name).read_text(encoding="utf-8-sig")

    def test_publication_workflows_never_publish_or_destructively_reset(self):
        for name in PUBLISH:
            text = self.text(name)
            self.assertNotRegex(text, r"\bgit\s+push\b", name)
            self.assertNotRegex(text, r"\bgit\s+reset\s+--hard\b", name)
            self.assertNotRegex(text, r"(?m)^\s{2}contents:\s*write\s*$", name)


    def test_automatic_diagnostics_do_not_mutate_issues_and_require_artifacts(self):
        for name in AUTO_EVIDENCE:
            text = self.text(name)
            self.assertNotRegex(text, r"(?m)^\s{2}issues:\s*write\s*$", name)
            self.assertNotRegex(text, r"\bgh\s+issue\s+(?:create|edit|comment)\b", name)
            self.assertIn("actions/upload-artifact@v4", text, name)
            self.assertRegex(text, r"if-no-files-found:\s*error", name)


    def trigger_body(self, name: str) -> str:
        text = self.text(name)
        match = re.search(r"(?m)^on:\s*\n(?P<body>(?:^[ \t]+[^\n]*(?:\n|$))*)", text)
        self.assertIsNotNone(match, name)
        return match.group("body")

    def test_desktop_commander_automatic_health_cannot_repair(self):
        text = self.text("desktop-commander-24h-health.yml")
        self.assertIn("ALLOW_REPAIR: ${{ github.event_name == 'workflow_dispatch' && '1' || '0' }}", text)
        self.assertIn("MAX_REPAIR_ATTEMPTS = 1 if os.environ.get('ALLOW_REPAIR') == '1' else 0", text)

    def test_windows_private_peer_recovery_is_manual_only(self):
        triggers = self.trigger_body("windows-private-peer-recovery.yml")
        self.assertRegex(triggers, r"(?m)^  workflow_dispatch:\s*$")
        self.assertNotRegex(triggers, r"(?m)^  push:\s*$")


    def test_failure_paths_do_not_hide_errors(self):
        for name in (
            "fred-win-terminal.yml",
            "mei-email-prod-probe-now.yml",
            "mei-email-prod-probe-robust.yml",
            "test-inventory.yml",
        ):
            text = self.text(name)
            self.assertNotRegex(text, r"continue-on-error\s*:\s*true", name)
            self.assertNotRegex(text, r"(?m)^\s*set\s+\+e\s*$", name)


    def test_production_functional_audit_has_no_push_trigger(self):
        triggers = self.trigger_body("production-functional-audit.yml")
        self.assertRegex(triggers, r"(?m)^  workflow_dispatch:\s*$")
        self.assertRegex(triggers, r"(?m)^  schedule:\s*$")
        self.assertNotRegex(triggers, r"(?m)^  push:\s*$")


    def test_checkout_migration_is_preview_only_and_never_edits_production(self):
        text = self.text("agent-vm-readonly-diagnostics.yml")
        self.assertIn("checkout_patch=preview_only", text)
        self.assertNotIn("sudo cp \"$tmp\" \"$target\"", text)
        self.assertNotIn("systemctl reload apache2", text)
        self.assertNotIn("patch_checkout \"$current/checkout.php\"", text)
        self.assertRegex(text, r"(?s)- name: Configure SSH\n\s+if: env\.MODE == 'health'")
        self.assertRegex(text, r"(?s)- name: Run VM route\n\s+if: env\.MODE == 'health'")

    def test_desktop_commander_health_materializes_fallback_evidence(self):
        text = self.text("desktop-commander-24h-health.yml")
        self.assertIn("CONTROL_PLANE_EVIDENCE=fallback", text)
        self.assertIn("/tmp/dc-control-plane-status.json", text)
        self.assertIn("/tmp/dc-control-plane-status.md", text)
        self.assertIn("probe_failed_before_status_materialization", text)

    def test_three_host_control_plane_materializes_fallback_evidence(self):
        text = self.text("desktop-commander-three-host-control-plane.yml")
        self.assertIn("CONTROL_PLANE_EVIDENCE=fallback", text)
        self.assertIn("/tmp/dc-status.json", text)
        self.assertIn("/tmp/dc-status.md", text)
        self.assertIn("probe_failed_before_status_materialization", text)

    def test_three_host_quick_probe_materializes_fallback_evidence(self):
        text = self.text("desktop-commander-three-host-quick-probe.yml")
        self.assertRegex(text, r"(?s)- name: Stage sanitized probe evidence\n\s+if: always\(\)")
        self.assertRegex(text, r"(?s)- name: Upload immutable probe evidence\n\s+if: always\(\)")
        self.assertIn("QUICK_PROBE_EVIDENCE=fallback", text)
        self.assertIn("probe_failed_before_quick_evidence_materialization", text)

    def test_vm_connection_probe_materializes_fallback_evidence(self):
        text = self.text("vm-desktop-commander-connection-probe.yml")
        self.assertRegex(text, r"(?s)- name: Stage sanitized connection evidence\n\s+if: always\(\)")
        self.assertRegex(text, r"(?s)- name: Upload immutable connection evidence\n\s+if: always\(\)")
        self.assertIn("VM_CONNECTION_EVIDENCE=fallback", text)
        self.assertIn("probe_failed_before_connection_evidence_materialization", text)

    def test_mei_probe_preserves_unknown_when_systemctl_returns_no_state(self):
        text = self.text("mei-email-prod-probe-now.yml")
        self.assertIn('worker_state="${worker_state:-unknown}"', text)
        self.assertIn('timer_state="${timer_state:-unknown}"', text)
        self.assertNotIn('worker_state="${worker_state:-inactive}"', text)
        self.assertNotIn('timer_state="${timer_state:-inactive}"', text)

    def test_mei_probe_materializes_evidence_after_collection_failure(self):
        text = self.text("mei-email-prod-probe-now.yml")
        self.assertRegex(text, r"(?s)- name: Stage sanitized probe evidence\n\s+if: always\(\)")
        self.assertRegex(text, r"(?s)- name: Upload immutable probe evidence\n\s+if: always\(\)")
        self.assertIn("PROBE_EVIDENCE=fallback", text)
        self.assertIn("collection_failed_before_probe_materialization", text)

    def test_test_inventory_reports_failures_explicitly_without_blocking(self):
        text = self.text("test-inventory.yml")
        self.assertIn("::warning::PHP inventory failures=$failed", text)
        self.assertIn("::warning::pytest inventory exit status=$status", text)
        self.assertNotIn('exit "$status"', text)
        self.assertNotIn('if [ "$failed" -ne 0 ]; then\n            exit 1', text)

    def test_graph_diagnostic_materializes_fallback_evidence(self):
        text = self.text("mei-email-graph-token-diagnostic.yml")
        self.assertRegex(text, r"(?s)- name: Require diagnostic evidence\n\s+if: always\(\)")
        self.assertIn("GRAPH_DIAGNOSTIC_EVIDENCE=fallback", text)
        self.assertIn('if [ ! -s "$REPORT_PATH" ]; then', text)

    def test_dc_contracts_track_current_control_plane_status_step_and_changes(self):
        marker = "Stage sanitized status evidence"
        for name in (
            "desktop-commander-auto-repair-guard-contract-test.php",
            "desktop-commander-nondisruptive-recovery-contract-test.php",
        ):
            text = (ROOT / "tests" / name).read_text(encoding="utf-8")
            self.assertIn(marker, text, name)
            self.assertNotIn("Publish sanitized status", text, name)
        workflow = self.text("dc-orphan-wrapper-contract.yml")
        self.assertIn(".github/workflows/desktop-commander-three-host-control-plane.yml", workflow)
        self.assertIn("tests/desktop-commander-auto-repair-guard-contract-test.php", workflow)
        self.assertIn("tests/desktop-commander-nondisruptive-recovery-contract-test.php", workflow)
        self.assertIn("php tests/desktop-commander-nondisruptive-recovery-contract-test.php", workflow)


if __name__ == "__main__":
    unittest.main()
