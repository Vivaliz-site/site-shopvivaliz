from __future__ import annotations

import importlib.util
import subprocess
import sys
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]


def load(name: str, relative: str):
    path = ROOT / relative
    spec = importlib.util.spec_from_file_location(name, path)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


HEALTH = load("system_health_check", "scripts/system-health-check.py")
HEARTBEAT = load("heartbeat_executor", "scripts/heartbeat-executor.py")
AGENTS = load("all_documented_agents", "scripts/all-documented-agents.py")


class QueueEvidenceTests(unittest.TestCase):
    def test_accepts_failed_and_blocked_without_completion(self):
        payload = {
            "tasks": [
                {"task_id": "F-1", "status": "failed", "last_result": {"success": False}},
                {"task_id": "B-1", "status": "blocked", "blocked_reason": "missing credential", "last_result": {"success": False}},
            ]
        }
        errors, checks = HEALTH.validate_queue(payload)
        self.assertEqual(errors, [])
        self.assertEqual(checks["queue_state_counts"]["failed"], 1)
        self.assertEqual(checks["queue_state_counts"]["blocked"], 1)

    def test_rejects_legacy_completed_without_evidence(self):
        errors, _ = HEALTH.validate_queue({"tasks": [{"task_id": "A-1", "status": "completed", "last_result": {"success": True}}]})
        self.assertTrue(any("invalid status" in error for error in errors))

    def test_rejects_failed_task_with_completed_at(self):
        errors, _ = HEALTH.validate_queue({
            "tasks": [{
                "task_id": "F-2",
                "status": "failed",
                "completed_at": "2026-07-30T00:00:00Z",
                "last_result": {"success": False},
            }]
        })
        self.assertTrue(any("must not have completed_at" in error for error in errors))

    def test_requires_full_verified_completion_evidence(self):
        task = {
            "task_id": "V-1",
            "status": "completed_verified",
            "completed_at": "2026-07-30T00:00:00Z",
            "last_result": {"success": True},
            "evidence": {"run_id": 1, "artifact": "a", "commit_sha": "b", "verification": "read-back"},
        }
        errors, _ = HEALTH.validate_queue({"tasks": [task]})
        self.assertEqual(errors, [])
        del task["evidence"]["artifact"]
        errors, _ = HEALTH.validate_queue({"tasks": [task]})
        self.assertTrue(any("lacks evidence fields" in error for error in errors))


class HeartbeatEvidenceTests(unittest.TestCase):
    def test_accepts_recent_success_with_artifact(self):
        now = datetime.now(timezone.utc)
        run = {
            "databaseId": 123,
            "status": "completed",
            "conclusion": "success",
            "updatedAt": (now - timedelta(minutes=10)).isoformat(),
        }
        artifacts = [{"name": "agents-deep-audit-123", "expired": False}]
        status, errors = HEARTBEAT.evaluate_run(run, artifacts, now, 120)
        self.assertEqual(status, "completed_verified")
        self.assertEqual(errors, [])

    def test_rejects_stale_run_or_missing_artifact(self):
        now = datetime.now(timezone.utc)
        run = {
            "databaseId": 456,
            "status": "completed",
            "conclusion": "success",
            "updatedAt": (now - timedelta(minutes=180)).isoformat(),
        }
        status, errors = HEARTBEAT.evaluate_run(run, [], now, 120)
        self.assertEqual(status, "failed")
        self.assertTrue(any("stale" in error for error in errors))
        self.assertTrue(any("artifact" in error for error in errors))


class DocumentedAgentStateTests(unittest.TestCase):
    def test_external_agent_is_blocked_without_credentials(self):
        spec = AGENTS.AgentSpec("external", (r"provider",), required_env=("MISSING_PROVIDER_TOKEN",), external=True)
        with patch.dict(AGENTS.os.environ, {}, clear=True):
            result = AGENTS.evaluate_agent(spec, [(AGENTS.ROOT / "provider.py", "provider integration")], "a" * 40)
        self.assertEqual(result.status, "blocked")
        self.assertFalse(result.external_operation_performed)

    def test_local_readiness_can_complete_with_evidence(self):
        spec = AGENTS.AgentSpec("local", (r"validator",))
        result = AGENTS.evaluate_agent(spec, [(AGENTS.ROOT / "validator.py", "validator")], "b" * 40)
        self.assertEqual(result.status, "completed_verified")
        self.assertEqual(result.scope, "readiness_audit")
        self.assertEqual(result.evidence["commit_sha"], "b" * 40)


class RetiredExecutorTests(unittest.TestCase):
    def test_retired_executors_fail_closed(self):
        paths = [
            "scripts/autonomous-executor.py",
            "scripts/continuous-executor.py",
            "scripts/parallel-executor.py",
            "scripts/olist-sync-master.py",
            "scripts/git-auto-sync-master.py",
            "scripts/automation/pipeline_orchestrator.py",
        ]
        for relative in paths:
            with self.subTest(relative=relative):
                result = subprocess.run(
                    [sys.executable, str(ROOT / relative)],
                    cwd=ROOT,
                    text=True,
                    encoding="utf-8",
                    errors="replace",
                    capture_output=True,
                    timeout=20,
                    check=False,
                )
                self.assertEqual(result.returncode, 2)
                self.assertIn('"status": "blocked"', result.stderr)


if __name__ == "__main__":
    unittest.main()
