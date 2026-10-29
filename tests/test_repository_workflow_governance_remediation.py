from pathlib import Path
import re
import subprocess
import unittest
from unittest.mock import patch

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

    def run_body(self, name: str, step_name: str) -> str:
        text = self.text(name)
        marker = f"      - name: {step_name}\n"
        step_start = text.index(marker)
        run_marker = "        run: |\n"
        run_start = text.index(run_marker, step_start) + len(run_marker)
        next_step = text.find("\n      - name:", run_start)
        block = text[run_start:] if next_step < 0 else text[run_start:next_step]
        return "\n".join(line[10:] if line.startswith("          ") else line for line in block.splitlines()) + "\n"

    def test_m365_activation_shell_is_syntactically_valid(self):
        script = self.run_body("deploy-m365-runtime.yml", "Activate exact M365 source and validate")
        result = subprocess.run(["bash", "-n"], input=script, text=True, capture_output=True, check=False)
        self.assertEqual(result.returncode, 0, result.stderr)

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

    # #1757 intentionally allows one bounded repair on schedule; push remains observation-only.
    def test_desktop_commander_scheduled_health_allows_one_bounded_repair(self):
        text = self.text("desktop-commander-24h-health.yml")
        self.assertIn(
            "ALLOW_REPAIR: ${{ (github.event_name == 'workflow_dispatch' || github.event_name == 'schedule') && '1' || '0' }}",
            text,
        )
        self.assertIn("MAX_REPAIR_ATTEMPTS = 1 if os.environ.get('ALLOW_REPAIR') == '1' else 0", text)
        self.assertNotIn("github.event_name == 'push' && '1'", text)

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
        self.assertNotRegex(triggers, r"(?m)^  (?:push|schedule|workflow_run|issue_comment):")

        # The deleted source remains disabled; scheduling belongs to Marina.
        retired = self.text("production-functional-audit.yml")
        jobs = retired.split("jobs:\n", 1)[1]
        self.assertEqual(re.findall(r"(?m)^  ([A-Za-z_][\w-]*):\s*$", jobs), ["functional"])
        header = jobs.split("  functional:\n", 1)[1].split("    steps:\n", 1)[0]
        conditions = re.findall(r"(?m)^    if:\s*([^\n]+)$", header)
        self.assertEqual(len(conditions), 1, "retired job must have one unambiguous guard")
        self.assertRegex(conditions[0], r"^\$\{\{\s*false\s*\}\}\s*(?:#.*)?$")
        self.assertIn("RETIRED_SOURCE_JOB_GUARD_20261029", header)

        marina = self.text("production-functional-audit-marina.yml")
        marina_triggers = self.trigger_body("production-functional-audit-marina.yml")
        self.assertRegex(marina_triggers, r"(?m)^  workflow_dispatch:\s*$")
        self.assertRegex(marina_triggers, r"(?m)^  schedule:\s*$")
        self.assertNotRegex(marina_triggers, r"(?m)^  (?:push|workflow_run|issue_comment):")
        marina_jobs = marina.split("jobs:\n", 1)[1]
        self.assertEqual(re.findall(r"(?m)^  ([A-Za-z_][\w-]*):\s*$", marina_jobs), ["functional"])
        marina_header = marina_jobs.split("  functional:\n", 1)[1].split("    steps:\n", 1)[0]
        self.assertEqual(
            re.findall(r"(?m)^    if:\s*([^\n]+)$", marina_header),
            ["github.repository == 'Vivaliz-site/site-shopvivaliz'"],
        )
        self.assertEqual(
            re.findall(r"(?m)^    runs-on:[ \t]*([^\n]+)$", marina_header),
            ["[self-hosted, Linux, ARM64, shopvivaliz-a1-deploy]"],
        )
        self.assertIn("environment: production", marina_header)
        self.assertRegex(
            marina,
            r'(?m)^ {10,}test "\$\(hostname -s\)" = "marina-a1-4c24g"[ \t]*$',
        )
        self.assertIn("AUDIT_TARGET_IP: 127.0.0.1", marina)
        self.assertIn("StrictHostKeyChecking=yes", marina)
        self.assertIn("tests/test_production_functional_audit_contract.py", marina)
        self.assertIn('bash "$stage/production-functional-audit.sh"', marina)
        self.assertIn("PRODUCTION_FUNCTIONAL_AUDIT_MARINA=FAIL", marina)
        self.assertNotIn("shopvivaliz-free-a1", marina)
        self.assertNotIn("10.0.1.112", marina)

    def test_marina_audit_contract_rejects_commented_guards(self):
        original_text = self.text
        marina = original_text("production-functional-audit-marina.yml")
        runner = "    runs-on: [self-hosted, Linux, ARM64, shopvivaliz-a1-deploy]"
        hostname = 'test "$(hostname -s)" = "marina-a1-4c24g"'
        mutations = {
            "commented runner with hosted replacement": marina.replace(
                runner, runner.replace("runs-on:", "# runs-on:") + "\n    runs-on: ubuntu-latest"
            ),
            "commented hostname checks": marina.replace(hostname, "# " + hostname),
        }
        for label, mutated in mutations.items():
            with self.subTest(label=label):
                self.assertNotEqual(mutated, marina)
                with patch.object(
                    self, "text",
                    side_effect=lambda name, fixture=mutated: (
                        fixture if name == "production-functional-audit-marina.yml" else original_text(name)
                    ),
                ):
                    with self.assertRaises(AssertionError, msg=label):
                        self.test_production_functional_audit_has_no_push_trigger()


    def test_repository_governance_fails_closed_on_global_workflow_debt(self):
        text = self.text("repository-governance.yml")
        self.assertNotIn("::warning::Repository-wide workflow debt remains", text)
        self.assertRegex(
            text,
            r"(?s)status=0\s+python scripts/maintenance/audit_active_workflows\.py \|\| status=\$\?.*?test -s artifacts/workflow-policy/report\.json.*?test -s artifacts/workflow-policy/report\.md.*?exit \"\$status\"",
        )


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
        self.assertIn("if ! grep -qx", text)

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

    def test_graph_diagnostic_requires_completion_marker(self):
        text = self.text("mei-email-graph-token-diagnostic.yml")
        self.assertIn("GRAPH_DIAGNOSTIC_COMPLETE=1", text)
        self.assertIn("if ! grep -qx", text)
        self.assertIn("collection_failed_before_graph_diagnostic_completion", text)

    def test_dc_contract_trigger_covers_all_validated_workflows(self):
        workflow = self.text("dc-orphan-wrapper-contract.yml")
        self.assertIn(".github/workflows/vm-desktop-commander-action.yml", workflow)
        self.assertIn(".github/workflows/vm-desktop-commander-secure-recovery.yml", workflow)


if __name__ == "__main__":
    unittest.main()
