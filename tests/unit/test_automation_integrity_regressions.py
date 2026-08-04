from __future__ import annotations

import importlib.util
import json
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def load_module(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


AUDITOR = load_module(
    "deep_agent_auditor", ROOT / "scripts" / "audit-agents-real-work.py"
)
INDEXABILITY = load_module(
    "product_page_indexability_audit",
    ROOT / "scripts" / "product-page-indexability-audit.py",
)


class AutomationIntegrityRegressionTests(unittest.TestCase):
    def test_web_deploy_endpoints_are_absent(self) -> None:
        self.assertFalse((ROOT / "admin/sync-critical-files.php").exists())
        self.assertFalse((ROOT / "admin/force-git-pull.php").exists())

    def test_unsafe_legacy_automation_is_absent(self) -> None:
        removed = (
            "scripts/autonomous-provider-failover.sh",
            "scripts/main_advanced.py",
            "scripts/shopvivaliz_agent_api.py",
            "scripts/shopvivaliz_debugger.py",
            "scripts/task-distribution-engine.php",
            "scripts/shopvivaliz_backup.py",
            "scripts/task-queue-cli.py",
            "scripts/autonomous-worker.sh",
        )
        for relative in removed:
            self.assertFalse((ROOT / relative).exists(), relative)

    def test_unsafe_secret_utilities_are_absent(self) -> None:
        removed = (
            "copy-secrets-from-file.py",
            "export-and-copy-secrets.py",
            "scripts/setup_secrets.py",
            "scripts/sincronizar_secrets_github.py",
            "scripts/tiktok-get-shop-cipher.py",
            "scripts/tiktok-inspect-tool.py",
            "scripts/verify_secrets.py",
            "setup-all-secrets-complete.sh",
        )
        for relative in removed:
            self.assertFalse((ROOT / relative).exists(), relative)

    def test_legacy_queue_is_retired_and_empty(self) -> None:
        payload = json.loads((ROOT / "tasks-queue.json").read_text(encoding="utf-8"))
        self.assertEqual(payload["metadata"]["status"], "retired_read_only")
        self.assertIs(payload["metadata"]["queue_modified_by_runtime"], False)
        self.assertEqual(payload["tasks"], [])

    def test_legacy_selector_never_modifies_queue(self) -> None:
        queue_path = ROOT / "tasks-queue.json"
        before = queue_path.read_bytes()
        result = subprocess.run(
            [sys.executable, str(ROOT / "scripts/autonomous-continuous-cycle.py"), "--advance"],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
            timeout=30,
        )
        self.assertEqual(result.returncode, 2)
        self.assertIn('"queue_modified": false', result.stdout.lower())
        self.assertEqual(queue_path.read_bytes(), before)

    def test_service_loop_uses_only_canonical_orchestrator(self) -> None:
        text = (ROOT / "scripts/autonomous-agent-loop.sh").read_text(encoding="utf-8")
        self.assertIn("api/agent/real-work-orchestrator.php", text)
        self.assertIn("execution_accepted", text)
        self.assertIn("work_evidence_count", text)
        self.assertNotIn("autonomous-continuous-cycle.py --advance", text)
        self.assertNotIn("agent-operations-worker.py", text)
        self.assertNotIn("|| log", text)

    def test_first_agent_cycle_generates_evidence_before_requiring_it(self) -> None:
        text = (ROOT / "scripts/autonomous-agent-loop.sh").read_text(encoding="utf-8")
        execution = text.index("real-work-orchestrator.php\" > \"$tmp_response\"")
        requirement = text.index('require_file "$PERSISTED_EVIDENCE"')
        self.assertLess(execution, requirement)

    def test_guardian_is_read_only_and_fail_closed(self) -> None:
        text = (ROOT / "scripts/autonomous-hourly-guardian.py").read_text(encoding="utf-8")
        self.assertIn('"queue_modified": False', text)
        self.assertIn("return 0 if not errors else 2", text)
        self.assertNotIn("systemctl", text)
        self.assertNotIn("auto-task-generator.py", text)
        self.assertNotIn("--advance", text)

    def test_operations_observer_does_not_assign_or_fake_execution(self) -> None:
        text = (ROOT / "scripts/agent-operations-worker.py").read_text(encoding="utf-8")
        self.assertIn('"queue_modified": False', text)
        self.assertNotIn('task["assigned_to"]', text)
        self.assertNotIn("Executando comando de teste", text)
        self.assertNotIn('"status": "alive"', text)
        self.assertNotIn("Enviando resposta de sucesso", text)

    def test_bridge_requires_successful_commands_and_evidence(self) -> None:
        text = (ROOT / "agent-bridge/agent_bridge.py").read_text(encoding="utf-8")
        self.assertIn("all_commands_succeeded", text)
        self.assertIn("Action lacks verified evidence", text)
        self.assertIn('task_path.with_suffix(".json.failed")', text)
        self.assertIn('["bash", "-Eeu", "-o", "pipefail", "-c", command]', text)

    def test_active_files_have_no_deep_audit_finding(self) -> None:
        active_files = (
            "agent-bridge/agent_bridge.py",
            "scripts/agent-operations-worker.py",
            "scripts/autonomous-hourly-guardian.py",
        )
        failures: dict[str, list[dict[str, object]]] = {}
        for relative in active_files:
            text = (ROOT / relative).read_text(encoding="utf-8")
            findings = AUDITOR.audit_text(relative, text, True)
            if findings:
                failures[relative] = [
                    {"rule": item.rule, "line": item.line, "message": item.message}
                    for item in findings
                ]
        self.assertEqual(failures, {})

    def test_legacy_powershell_entrypoints_are_blocked(self) -> None:
        for relative in ("SETUP-COMPLETE.ps1", "RUN-ORCHESTRATOR.ps1"):
            text = (ROOT / relative).read_text(encoding="utf-8")
            self.assertIn("status = 'blocked'", text)
            self.assertIn("success = $false", text)
            self.assertIn("exit 2", text)

    def test_production_monitor_is_fail_closed_and_single_request(self) -> None:
        text = (ROOT / "scripts/audit-monitor-24-7.py").read_text(encoding="utf-8")
        self.assertIn('"read_only": True', text)
        self.assertIn('return 0 if report["passed"] else 2', text)
        self.assertNotIn("load_env", text)
        self.assertEqual(text.count('f"{BASE_URL}/api/orders/health.php"'), 1)
        service = (ROOT / "deploy/systemd/shopvivaliz-audit-monitor.service").read_text(encoding="utf-8")
        self.assertIn("ProtectSystem=strict", service)
        self.assertIn("ReadWritePaths=/home/ubuntu/shopvivaliz-deploy/shared/logs", service)

    def test_integration_snapshot_is_not_group_writable(self) -> None:
        text = (ROOT / ".github/workflows/integrations-hourly.yml").read_text(encoding="utf-8")
        self.assertIn('chmod 0640 "$state_file"', text)
        self.assertIn('-m 0640 /dev/null "$state_file"', text)
        self.assertNotIn('chmod 0660 "$state_file"', text)

    def test_indexability_accepts_list_and_object_catalogs(self) -> None:
        list_products, list_shape = INDEXABILITY.normalize_products([{"slug": "a"}])
        object_products, object_shape = INDEXABILITY.normalize_products({"123": {"slug": "b"}})
        self.assertEqual(list_shape, "list")
        self.assertEqual(list_products, [{"slug": "a"}])
        self.assertEqual(object_shape, "object_by_id")
        self.assertEqual(object_products[0]["id"], "123")
        self.assertEqual(object_products[0]["slug"], "b")

    def test_indexability_audit_fails_when_checks_fail(self) -> None:
        text = (ROOT / "scripts/product-page-indexability-audit.py").read_text(encoding="utf-8")
        self.assertIn("return 0 if passed else 2", text)
        self.assertIn('"status": "PASSED" if passed else "FAILED"', text)

    def test_system_health_is_structural_not_runtime_proof(self) -> None:
        result = subprocess.run(
            [sys.executable, str(ROOT / "scripts/maintenance/system_health_check.py")],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
            timeout=30,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        report = json.loads((ROOT / "artifacts/system-health/report.json").read_text(encoding="utf-8"))
        self.assertEqual(report["runtime_health"], "NOT_OBSERVED_IN_REPOSITORY_CHECKOUT")
        self.assertEqual(report["queue_mode"], "retired_read_only")


if __name__ == "__main__":
    unittest.main()
