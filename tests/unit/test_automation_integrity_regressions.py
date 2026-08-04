from __future__ import annotations

import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class AutomationIntegrityRegressionTests(unittest.TestCase):
    def test_web_deploy_endpoints_are_absent(self) -> None:
        self.assertFalse((ROOT / "admin/sync-critical-files.php").exists())
        self.assertFalse((ROOT / "admin/force-git-pull.php").exists())

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
        self.assertIn("Mutating action lacks commit/PR/evidence", text)
        self.assertIn('task_path.with_suffix(".json.failed")', text)
        self.assertNotIn('return {"status": "OK", "action": "run_readonly_audit", "outputs": outputs}', text)

    def test_legacy_powershell_entrypoints_are_blocked(self) -> None:
        for relative in ("SETUP-COMPLETE.ps1", "RUN-ORCHESTRATOR.ps1"):
            text = (ROOT / relative).read_text(encoding="utf-8")
            self.assertIn("status = 'blocked'", text)
            self.assertIn("success = $false", text)
            self.assertIn("exit 2", text)

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
