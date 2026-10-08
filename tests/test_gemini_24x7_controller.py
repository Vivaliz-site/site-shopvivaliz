from __future__ import annotations

import importlib.util
import json
import os
import subprocess
import sys
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest.mock import patch


ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "gemini_24x7_controller.py"


def load_controller():
    spec = importlib.util.spec_from_file_location("gemini_24x7_controller_test", SCRIPT)
    if spec is None or spec.loader is None:
        raise RuntimeError("cannot load Gemini 24x7 controller")
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


class Gemini24x7ControllerTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.runtime = Path(self.temp.name) / "runtime"
        self.runtime.mkdir()
        self.previous_claude_pointer = os.environ.get("CLAUDE_REMOTE_CONTROL_POINTER_FILE")
        self.claude_pointer = Path(self.temp.name) / "claude-bridge-pointer.json"
        process_fields = Path(f"/proc/{os.getpid()}/stat").read_text(encoding="utf-8").split()
        self.claude_pointer.write_text(
            json.dumps(
                {
                    "sessionId": "session-test",
                    "environmentId": "env-test",
                    "source": "standalone",
                    "pid": os.getpid(),
                    "procStart": process_fields[21],
                }
            ),
            encoding="utf-8",
        )
        os.environ["CLAUDE_REMOTE_CONTROL_POINTER_FILE"] = str(self.claude_pointer)
        (self.runtime / "_chatgpt-browser-health.json").write_text(
            json.dumps(
                {
                    "schema_version": 1,
                    "updated_at": datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
                    "session_state": "AUTHENTICATED",
                    "authenticated": True,
                }
            ),
            encoding="utf-8",
        )
        (self.runtime / "_chatgpt-continuity-monitor-state.json").write_text(
            json.dumps(
                {
                    "schema_version": 1,
                    "updated_at": datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
                    "degraded": False,
                    "action": "no_banner",
                    "sent": False,
                    "progress_confirmed": False,
                    "failure_reason": "",
                }
            ),
            encoding="utf-8",
        )

    def tearDown(self) -> None:
        if self.previous_claude_pointer is None:
            os.environ.pop("CLAUDE_REMOTE_CONTROL_POINTER_FILE", None)
        else:
            os.environ["CLAUDE_REMOTE_CONTROL_POINTER_FILE"] = self.previous_claude_pointer
        self.temp.cleanup()

    def test_controller_reports_single_writer_ownership_counters(self) -> None:
        controller = load_controller()
        with (
            patch.dict(os.environ, {"SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF": "1"}, clear=False),
            patch.object(controller.watchdog, "run_once", return_value={"scanned": 1, "eligible": 0, "dispatched": 0}),
            patch.object(controller.nudge_dispatcher, "run_once", return_value={
                "scanned": 1, "eligible": 1, "dispatched": 0,
                "skipped_foreground_active": 1, "skipped_ownership_busy": 2,
            }),
            patch.object(controller.dispatcher, "run_once", return_value={"scanned": 1, "eligible": 0, "executed": 0}),
        ):
            result = controller.run_once(runtime_dir=self.runtime, owner_id="single-writer-observer")
        self.assertTrue(result["single_writer_enforced"])
        self.assertTrue(result["durable_handoff_enabled"])
        self.assertEqual(result["chatgpt_nudge"]["skipped_foreground_active"], 1)
        self.assertEqual(result["chatgpt_nudge"]["skipped_ownership_busy"], 2)

    def test_unbound_running_checkpoint_must_fail_readiness_closed(self) -> None:
        controller = load_controller()
        now = datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")
        (self.runtime / "unbound-real-task.json").write_text(
            json.dumps({
                "task_id": "unbound-real-task", "status": "RUNNING",
                "next_action": "finish task", "created_at": now, "updated_at": now,
            }),
            encoding="utf-8",
        )
        with (
            patch.dict(os.environ, {"SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF": "1"}, clear=False),
            patch.object(controller.watchdog, "run_once", return_value={"scanned": 1, "eligible": 1}),
            patch.object(controller.nudge_dispatcher, "run_once", return_value={"scanned": 1, "skipped_unbound": 1}),
            patch.object(controller.dispatcher, "run_once", return_value={"scanned": 1, "deferred_unbound": 1}),
        ):
            result = controller.run_once(runtime_dir=self.runtime, owner_id="unbound-real-task")
        self.assertEqual(result["completion_sweep"]["unbound_active"], 1)
        self.assertFalse(result["continuity_ready"])
        self.assertFalse(result["ok"])
        self.assertIn("active_checkpoint_unbound", result["degraded_reasons"])
        self.assertEqual(result["chatgpt_nudge"]["skipped_unbound"], 1)
        self.assertEqual(result["dispatcher"]["deferred_unbound"], 1)

    def test_unbound_session_and_no_conversation_are_visible_in_health(self) -> None:
        controller = load_controller()
        with (
            patch.dict(os.environ, {"SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF": "1"}, clear=False),
            patch.object(controller.watchdog, "run_once", return_value={}),
            patch.object(controller.nudge_dispatcher, "run_once", return_value={"skipped_unbound": 2}),
            patch.object(controller.dispatcher, "run_once", return_value={"deferred_unbound": 2, "deferred_unbound_session": 1}),
        ):
            result = controller.run_once(runtime_dir=self.runtime, owner_id="unbound-counters")
        self.assertFalse(result["continuity_ready"])
        self.assertIn("chatgpt_resume_unbound_conversation", result["degraded_reasons"])
        self.assertIn("dispatcher_unbound_conversation", result["degraded_reasons"])
        self.assertIn("dispatcher_unbound_session", result["degraded_reasons"])
        self.assertEqual(result["chatgpt_nudge"]["skipped_unbound"], 2)
        self.assertEqual(result["dispatcher"]["deferred_unbound_session"], 1)

    def test_legacy_handoff_disabled_allows_unbound_fallback_without_false_red(self) -> None:
        controller = load_controller()
        with (
            patch.dict(os.environ, {"SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF": "0"}, clear=False),
            patch.object(controller.watchdog, "run_once", return_value={}),
            patch.object(controller.nudge_dispatcher, "run_once", return_value={"skipped_unbound": 1}),
            patch.object(controller.dispatcher, "run_once", return_value={"launched": 1, "in_flight": 1}),
        ):
            result = controller.run_once(runtime_dir=self.runtime, owner_id="legacy-unbound-fallback")
        self.assertFalse(result["durable_handoff_enabled"])
        self.assertTrue(result["continuity_ready"])
        self.assertEqual(result["degraded_reasons"], [])
        self.assertEqual(result["dispatcher"]["in_flight"], 1)

    def test_controller_can_retry_after_repeated_restart_failures(self) -> None:
        unit = (ROOT / "deploy/systemd/shopvivaliz-gemini-24x7-controller.service").read_text(encoding="utf-8")
        self.assertIn("StartLimitIntervalSec=0", unit)
        self.assertIn("RestartSec=60", unit)

    def test_expired_durable_lease_is_recovered_and_recorded(self) -> None:
        controller = load_controller()
        lease = self.runtime / controller.LEASE_FILE
        lease.write_text(
            json.dumps({"owner_id": "crashed-owner", "expires_at": "2000-01-01T00:00:00Z"}),
            encoding="utf-8",
        )

        result = controller.acquire_lease(self.runtime, owner_id="recovery-owner", ttl_seconds=60)

        self.assertTrue(result.acquired)
        self.assertTrue(result.recovered)
        events = [
            json.loads(line)
            for line in (self.runtime / controller.EVENTS_FILE).read_text(encoding="utf-8").splitlines()
            if line.strip()
        ]
        self.assertEqual(events[-1]["event"], "lease_recovered")

    def test_live_ttl_lease_from_dead_process_is_recovered_immediately(self) -> None:
        controller = load_controller()
        child = subprocess.Popen([sys.executable, "-c", "import time; time.sleep(30)"])
        try:
            pid = child.pid
            start_ticks = controller._process_start_ticks(pid)
            self.assertTrue(start_ticks)
            boot_id = controller._boot_id()
        finally:
            child.terminate()
            child.wait(timeout=5)

        lease = self.runtime / controller.LEASE_FILE
        lease.write_text(
            json.dumps(
                {
                    "schema_version": 2,
                    "owner_id": "crashed-owner",
                    "pid": pid,
                    "pid_start_ticks": start_ticks,
                    "boot_id": boot_id,
                    "expires_at": "2999-01-01T00:00:00Z",
                }
            ),
            encoding="utf-8",
        )

        result = controller.acquire_lease(self.runtime, owner_id="restarted-owner", ttl_seconds=60)

        self.assertTrue(result.acquired)
        self.assertTrue(result.recovered)
        events = [
            json.loads(line)
            for line in (self.runtime / controller.EVENTS_FILE).read_text(encoding="utf-8").splitlines()
            if line.strip()
        ]
        self.assertEqual(events[-1]["event"], "lease_recovered")
        self.assertEqual(events[-1]["reason"], "owner_dead")

    def test_live_lease_blocks_duplicate_controller_ownership(self) -> None:
        controller = load_controller()
        first = controller.acquire_lease(self.runtime, owner_id="owner-one", ttl_seconds=300)
        second = controller.acquire_lease(self.runtime, owner_id="owner-two", ttl_seconds=300)

        self.assertTrue(first.acquired)
        self.assertFalse(second.acquired)
        self.assertEqual(second.reason, "lease_held")

    def test_process_lifetime_daemon_guard_blocks_second_owner(self) -> None:
        controller = load_controller()
        with controller.daemon_guard(self.runtime) as first:
            self.assertTrue(first)
            with controller.daemon_guard(self.runtime) as second:
                self.assertFalse(second)

    def test_failed_browser_resume_is_not_continuity_ready(self) -> None:
        controller = load_controller()
        with (
            patch.object(controller.watchdog, "run_once", return_value={}),
            patch.object(controller.nudge_dispatcher, "run_once", return_value={"failed": 1}),
            patch.object(controller.dispatcher, "run_once", return_value={"deferred_chatgpt": 1}),
        ):
            result = controller.run_once(runtime_dir=self.runtime, owner_id="browser-error")
        self.assertTrue(result["liveness_ok"])
        self.assertFalse(result["continuity_ready"])
        self.assertFalse(result["ok"])
        self.assertIn("chatgpt_resume_failed", result["degraded_reasons"])
        self.assertEqual(result["chatgpt_nudge"]["failed"], 1)

    def test_logged_out_browser_fails_readiness_closed(self) -> None:
        controller = load_controller()
        (self.runtime / controller.CHATGPT_BROWSER_HEALTH_STATE_FILE).write_text(
            json.dumps(
                {
                    "schema_version": 1,
                    "updated_at": datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
                    "session_state": "LOGGED_OUT",
                    "authenticated": False,
                }
            ),
            encoding="utf-8",
        )
        with (
            patch.object(controller.watchdog, "run_once", return_value={}),
            patch.object(controller.nudge_dispatcher, "run_once", return_value={}),
            patch.object(controller.dispatcher, "run_once", return_value={}),
        ):
            result = controller.run_once(runtime_dir=self.runtime, owner_id="logged-out-browser")

        self.assertTrue(result["liveness_ok"])
        self.assertFalse(result["continuity_ready"])
        self.assertFalse(result["ok"])
        self.assertIn("chatgpt_browser_not_authenticated", result["degraded_reasons"])
        self.assertEqual(result["chatgpt_browser"]["session_state"], "LOGGED_OUT")

    def test_auth_flow_browser_fails_readiness_without_claiming_logout(self) -> None:
        controller = load_controller()
        (self.runtime / controller.CHATGPT_BROWSER_HEALTH_STATE_FILE).write_text(
            json.dumps(
                {
                    "schema_version": 1,
                    "updated_at": datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
                    "session_state": "AUTH_FLOW",
                    "authenticated": False,
                }
            ),
            encoding="utf-8",
        )
        with (
            patch.object(controller.watchdog, "run_once", return_value={}),
            patch.object(controller.nudge_dispatcher, "run_once", return_value={}),
            patch.object(controller.dispatcher, "run_once", return_value={}),
        ):
            result = controller.run_once(runtime_dir=self.runtime, owner_id="auth-flow-browser")

        self.assertFalse(result["continuity_ready"])
        self.assertIn("chatgpt_browser_auth_in_progress", result["degraded_reasons"])
        self.assertNotIn("chatgpt_browser_not_authenticated", result["degraded_reasons"])

    def test_browser_health_freshness_covers_declared_probe_interval(self) -> None:
        controller = load_controller()
        observed = datetime.now(timezone.utc) - timedelta(seconds=120)
        (self.runtime / controller.CHATGPT_BROWSER_HEALTH_STATE_FILE).write_text(
            json.dumps(
                {
                    "schema_version": 1,
                    "updated_at": observed.replace(microsecond=0).isoformat().replace("+00:00", "Z"),
                    "session_state": "AUTHENTICATED",
                    "authenticated": True,
                    "probe_interval_seconds": 300,
                }
            ),
            encoding="utf-8",
        )

        health = controller._chatgpt_browser_health(self.runtime)

        self.assertTrue(health["fresh"])
        self.assertTrue(health["authenticated"])
        self.assertGreaterEqual(health["max_age_seconds"], 360)

    def test_stale_browser_auth_health_fails_readiness_closed(self) -> None:
        controller = load_controller()
        (self.runtime / controller.CHATGPT_BROWSER_HEALTH_STATE_FILE).write_text(
            json.dumps(
                {
                    "schema_version": 1,
                    "updated_at": "2000-01-01T00:00:00Z",
                    "session_state": "AUTHENTICATED",
                    "authenticated": True,
                }
            ),
            encoding="utf-8",
        )
        with (
            patch.object(controller.watchdog, "run_once", return_value={}),
            patch.object(controller.nudge_dispatcher, "run_once", return_value={}),
            patch.object(controller.dispatcher, "run_once", return_value={}),
        ):
            result = controller.run_once(runtime_dir=self.runtime, owner_id="stale-browser-auth")

        self.assertFalse(result["continuity_ready"])
        self.assertIn("chatgpt_browser_auth_unknown", result["degraded_reasons"])
        self.assertFalse(result["chatgpt_browser"]["fresh"])

    def test_disabled_reinforcement_monitor_does_not_fail_readiness(self) -> None:
        controller = load_controller()
        (self.runtime / controller.CHATGPT_MONITOR_STATE_FILE).write_text(
            json.dumps(
                {
                    "schema_version": 2,
                    "updated_at": "2000-01-01T00:00:00Z",
                    "degraded": True,
                    "action": "recovery_retry_cooldown",
                    "sent": False,
                    "progress_confirmed": False,
                    "failure_reason": "silent_stall",
                }
            ),
            encoding="utf-8",
        )
        with (
            patch.dict(os.environ, {"CHATGPT_CONTINUITY_MONITOR_REQUIRED": "0"}),
            patch.object(controller.watchdog, "run_once", return_value={}),
            patch.object(controller.nudge_dispatcher, "run_once", return_value={}),
            patch.object(controller.dispatcher, "run_once", return_value={}),
        ):
            result = controller.run_once(runtime_dir=self.runtime, owner_id="monitor-disabled")

        self.assertTrue(result["continuity_ready"])
        self.assertNotIn("chatgpt_browser_monitor_stale", result["degraded_reasons"])
        self.assertNotIn("chatgpt_browser_stall_unresolved", result["degraded_reasons"])
        self.assertFalse(result["chatgpt_monitor"]["required"])

    def test_missing_reinforcement_monitor_fails_readiness_closed(self) -> None:
        controller = load_controller()
        (self.runtime / controller.CHATGPT_MONITOR_STATE_FILE).unlink()
        with (
            patch.object(controller.watchdog, "run_once", return_value={}),
            patch.object(controller.nudge_dispatcher, "run_once", return_value={}),
            patch.object(controller.dispatcher, "run_once", return_value={}),
        ):
            result = controller.run_once(runtime_dir=self.runtime, owner_id="missing-monitor")

        self.assertFalse(result["continuity_ready"])
        self.assertIn("chatgpt_browser_monitor_stale", result["degraded_reasons"])
        self.assertFalse(result["chatgpt_monitor"]["fresh"])

    def test_stale_reinforcement_monitor_fails_readiness_closed(self) -> None:
        controller = load_controller()
        (self.runtime / controller.CHATGPT_MONITOR_STATE_FILE).write_text(
            json.dumps(
                {
                    "schema_version": 1,
                    "updated_at": "2000-01-01T00:00:00Z",
                    "degraded": False,
                    "action": "no_banner",
                    "sent": False,
                    "progress_confirmed": False,
                    "failure_reason": "",
                }
            ),
            encoding="utf-8",
        )
        with (
            patch.object(controller.watchdog, "run_once", return_value={}),
            patch.object(controller.nudge_dispatcher, "run_once", return_value={}),
            patch.object(controller.dispatcher, "run_once", return_value={}),
        ):
            result = controller.run_once(runtime_dir=self.runtime, owner_id="stale-monitor")

        self.assertFalse(result["continuity_ready"])
        self.assertIn("chatgpt_browser_monitor_stale", result["degraded_reasons"])
        self.assertFalse(result["chatgpt_monitor"]["fresh"])
        self.assertGreater(result["chatgpt_monitor"]["age_seconds"], result["chatgpt_monitor"]["max_age_seconds"])

    def test_missing_claude_pointer_fails_readiness_closed(self) -> None:
        controller = load_controller()
        self.claude_pointer.unlink()
        with (
            patch.object(controller.watchdog, "run_once", return_value={}),
            patch.object(controller.nudge_dispatcher, "run_once", return_value={}),
            patch.object(controller.dispatcher, "run_once", return_value={}),
        ):
            result = controller.run_once(runtime_dir=self.runtime, owner_id="missing-claude-pointer")

        self.assertFalse(result["continuity_ready"])
        self.assertIn("claude_remote_control_pointer_missing", result["degraded_reasons"])
        self.assertFalse(result["claude_remote_control"]["pointer_present"])

    def test_dead_claude_remote_control_process_fails_readiness_closed(self) -> None:
        controller = load_controller()
        payload = json.loads(self.claude_pointer.read_text(encoding="utf-8"))
        payload["pid"] = 99999999
        self.claude_pointer.write_text(json.dumps(payload), encoding="utf-8")
        with (
            patch.object(controller.watchdog, "run_once", return_value={}),
            patch.object(controller.nudge_dispatcher, "run_once", return_value={}),
            patch.object(controller.dispatcher, "run_once", return_value={}),
        ):
            result = controller.run_once(runtime_dir=self.runtime, owner_id="dead-claude-process")

        self.assertFalse(result["continuity_ready"])
        self.assertIn("claude_remote_control_process_missing", result["degraded_reasons"])
        self.assertFalse(result["claude_remote_control"]["process_alive"])

    def test_stale_claude_pointer_fails_readiness_closed(self) -> None:
        controller = load_controller()
        payload = json.loads(self.claude_pointer.read_text(encoding="utf-8"))
        payload["procStart"] = "0"
        self.claude_pointer.write_text(json.dumps(payload), encoding="utf-8")
        with (
            patch.object(controller.watchdog, "run_once", return_value={}),
            patch.object(controller.nudge_dispatcher, "run_once", return_value={}),
            patch.object(controller.dispatcher, "run_once", return_value={}),
        ):
            result = controller.run_once(runtime_dir=self.runtime, owner_id="stale-claude-pointer")

        self.assertFalse(result["continuity_ready"])
        self.assertIn("claude_remote_control_pointer_stale", result["degraded_reasons"])
        self.assertTrue(result["claude_remote_control"]["process_alive"])
        self.assertFalse(result["claude_remote_control"]["identity_match"])

    def test_claude_health_state_is_connected_and_sanitized(self) -> None:
        controller = load_controller()
        health = controller._claude_remote_control_health()

        self.assertTrue(health["connected"])
        self.assertTrue(health["pointer_present"])
        self.assertTrue(health["process_alive"])
        self.assertTrue(health["identity_match"])
        self.assertTrue(health["session_present"])
        self.assertTrue(health["environment_present"])
        self.assertNotIn("sessionId", health)
        self.assertNotIn("environmentId", health)
        self.assertNotIn("pid", health)
        self.assertNotIn("procStart", health)

    def test_unresolved_browser_stall_fails_readiness_closed(self) -> None:
        controller = load_controller()
        (self.runtime / controller.CHATGPT_MONITOR_STATE_FILE).write_text(
            json.dumps(
                {
                    "schema_version": 1,
                    "updated_at": "2026-10-02T08:53:45Z",
                    "degraded": True,
                    "action": "sent_unconfirmed",
                    "sent": True,
                    "progress_confirmed": False,
                    "failure_reason": "request_timeout",
                }
            ),
            encoding="utf-8",
        )
        with (
            patch.object(controller.watchdog, "run_once", return_value={"scanned": 1, "eligible": 0, "dispatched": 0}),
            patch.object(controller.nudge_dispatcher, "run_once", return_value={}),
            patch.object(controller.dispatcher, "run_once", return_value={}),
        ):
            result = controller.run_once(runtime_dir=self.runtime, owner_id="browser-stall")

        self.assertFalse(result["ok"])
        self.assertFalse(result["continuity_ready"])
        self.assertTrue(result["degraded"])
        self.assertIn("chatgpt_browser_stall_unresolved", result["degraded_reasons"])
        self.assertTrue(result["chatgpt_monitor"]["degraded"])
        self.assertEqual(result["chatgpt_monitor"]["action"], "sent_unconfirmed")

    def test_newer_worker_fallback_health_supersedes_stale_runtime_health(self) -> None:
        controller = load_controller()
        self.assertTrue(hasattr(controller, "CHATGPT_MONITOR_FALLBACK_FILE"))
        primary = self.runtime / controller.CHATGPT_MONITOR_STATE_FILE
        primary.write_text(
            json.dumps({
                "schema_version": 1,
                "updated_at": "2026-10-02T12:52:25.493Z",
                "degraded": True,
                "action": "send_failed",
                "progress_confirmed": False,
            }),
            encoding="utf-8",
        )
        fallback = self.runtime / "worker-fallback-health.json"
        fallback.write_text(
            json.dumps({
                "schema_version": 1,
                "updated_at": "2026-10-02T15:04:51.000Z",
                "degraded": False,
                "action": "self_resolved",
                "progress_confirmed": True,
            }),
            encoding="utf-8",
        )
        original = controller.CHATGPT_MONITOR_FALLBACK_FILE
        controller.CHATGPT_MONITOR_FALLBACK_FILE = fallback
        try:
            health = controller._chatgpt_monitor_health(self.runtime)
        finally:
            controller.CHATGPT_MONITOR_FALLBACK_FILE = original
        self.assertFalse(health["degraded"])
        self.assertEqual(health["action"], "self_resolved")
        self.assertEqual(health["updated_at"], "2026-10-02T15:04:51.000Z")

    def test_idle_cycle_does_not_spam_event_ledger(self) -> None:
        controller = load_controller()
        with (
            patch.object(controller.watchdog, "run_once", return_value={"scanned": 8, "eligible": 0, "dispatched": 0}),
            patch.object(
                controller.nudge_dispatcher,
                "run_once",
                return_value={
                    "scanned": 0,
                    "eligible": 0,
                    "dispatched": 0,
                    "skipped_no_token": 0,
                    "skipped_stale_checkpoint": 0,
                },
            ),
            patch.object(
                controller.dispatcher,
                "run_once",
                return_value={
                    "scanned": 0,
                    "eligible": 0,
                    "executed": 0,
                    "progressed": 0,
                    "terminal": 0,
                    "no_progress": 0,
                    "failed": 0,
                    "deferred_chatgpt": 0,
                },
            ),
        ):
            result = controller.run_once(runtime_dir=self.runtime, owner_id="idle-owner")

        self.assertTrue(result["ok"])
        events = self.runtime / controller.EVENTS_FILE
        self.assertFalse(events.exists(), "idle 30-second cycles must not grow an unbounded event ledger")

    def test_in_flight_resume_does_not_block_or_degrade_controller_cycle(self) -> None:
        controller = load_controller()
        with (
            patch.object(controller.watchdog, "run_once", return_value={"scanned": 1, "eligible": 1, "dispatched": 0}),
            patch.object(controller.nudge_dispatcher, "run_once", return_value={
                "scanned": 0, "eligible": 0, "dispatched": 0, "skipped_no_token": 0,
                "skipped_stale_checkpoint": 0, "failed": 0, "skipped_attempt_limit": 0,
            }),
            patch.object(controller.dispatcher, "run_once", return_value={
                "scanned": 1, "eligible": 1, "executed": 0, "launched": 1,
                "in_flight": 1, "reconciled": 0, "recovered": 0,
                "progressed": 0, "terminal": 0, "no_progress": 0, "failed": 0,
                "deferred_chatgpt": 0,
            }),
        ):
            result = controller.run_once(runtime_dir=self.runtime, owner_id="in-flight-owner")

        self.assertTrue(result["continuity_ready"])
        self.assertFalse(result["degraded"])
        self.assertEqual(result["degraded_reasons"], [])
        self.assertEqual(result["dispatcher"]["launched"], 1)
        self.assertEqual(result["dispatcher"]["in_flight"], 1)

    def test_worker_failure_degrades_without_staling_controller_health(self) -> None:
        controller = load_controller()
        with (
            patch.object(controller.watchdog, "run_once", return_value={"scanned": 1, "eligible": 0, "dispatched": 0}),
            patch.object(controller.nudge_dispatcher, "run_once", return_value={
                "scanned": 0, "eligible": 0, "dispatched": 0, "skipped_no_token": 0,
                "skipped_stale_checkpoint": 0, "failed": 0, "skipped_attempt_limit": 0,
            }),
            patch.object(controller.dispatcher, "run_once", return_value={
                "scanned": 1, "eligible": 1, "executed": 0, "launched": 0,
                "in_flight": 0, "reconciled": 1, "recovered": 0,
                "progressed": 0, "terminal": 0, "no_progress": 0, "failed": 1,
                "deferred_chatgpt": 0,
            }),
        ):
            result = controller.run_once(runtime_dir=self.runtime, owner_id="worker-failure-owner")

        self.assertFalse(result["continuity_ready"])
        self.assertIn("dispatcher_failed", result["degraded_reasons"])
        self.assertTrue(result["generated_at"])
        self.assertEqual(result["dispatcher"]["reconciled"], 1)

    def test_no_progress_cycle_is_live_but_not_continuity_ready(self) -> None:
        controller = load_controller()
        with (
            patch.object(controller.watchdog, "run_once", return_value={"scanned": 1, "eligible": 1, "dispatched": 0}),
            patch.object(
                controller.nudge_dispatcher,
                "run_once",
                return_value={
                    "scanned": 1,
                    "eligible": 0,
                    "dispatched": 0,
                    "skipped_no_token": 0,
                    "skipped_stale_checkpoint": 0,
                },
            ),
            patch.object(
                controller.dispatcher,
                "run_once",
                return_value={
                    "scanned": 1,
                    "eligible": 1,
                    "executed": 1,
                    "progressed": 0,
                    "terminal": 0,
                    "no_progress": 1,
                    "failed": 0,
                    "deferred_chatgpt": 0,
                },
            ),
        ):
            result = controller.run_once(runtime_dir=self.runtime, owner_id="no-progress-owner")

        self.assertFalse(result["ok"])
        self.assertTrue(result["liveness_ok"])
        self.assertFalse(result["continuity_ready"])
        self.assertTrue(result["degraded"])
        self.assertEqual(result["degraded_reasons"], ["dispatcher_no_progress"])

    def test_failed_cycle_is_not_continuity_ready(self) -> None:
        controller = load_controller()
        with (
            patch.object(controller.watchdog, "run_once", return_value={"scanned": 1, "eligible": 0, "dispatched": 0}),
            patch.object(
                controller.nudge_dispatcher,
                "run_once",
                return_value={
                    "scanned": 0,
                    "eligible": 0,
                    "dispatched": 0,
                    "skipped_no_token": 0,
                    "skipped_stale_checkpoint": 0,
                },
            ),
            patch.object(
                controller.dispatcher,
                "run_once",
                return_value={
                    "scanned": 1,
                    "eligible": 1,
                    "executed": 1,
                    "progressed": 0,
                    "terminal": 0,
                    "no_progress": 0,
                    "failed": 1,
                    "deferred_chatgpt": 0,
                },
            ),
        ):
            result = controller.run_once(runtime_dir=self.runtime, owner_id="failed-owner")

        self.assertFalse(result["ok"])
        self.assertTrue(result["liveness_ok"])
        self.assertFalse(result["continuity_ready"])
        self.assertEqual(result["degraded_reasons"], ["dispatcher_failed"])

    def test_material_cycle_keeps_forensic_event(self) -> None:
        controller = load_controller()
        with (
            patch.object(controller.watchdog, "run_once", return_value={"scanned": 1, "eligible": 1, "dispatched": 1}),
            patch.object(
                controller.nudge_dispatcher,
                "run_once",
                return_value={
                    "scanned": 0,
                    "eligible": 0,
                    "dispatched": 0,
                    "skipped_no_token": 0,
                    "skipped_stale_checkpoint": 0,
                },
            ),
            patch.object(
                controller.dispatcher,
                "run_once",
                return_value={
                    "scanned": 0,
                    "eligible": 0,
                    "executed": 0,
                    "progressed": 0,
                    "terminal": 0,
                    "no_progress": 0,
                    "failed": 0,
                    "deferred_chatgpt": 0,
                },
            ),
        ):
            controller.run_once(runtime_dir=self.runtime, owner_id="active-owner")

        rows = [
            json.loads(line)
            for line in (self.runtime / controller.EVENTS_FILE).read_text(encoding="utf-8").splitlines()
            if line.strip()
        ]
        self.assertEqual(rows[-1]["event"], "cycle_completed")
        self.assertEqual(rows[-1]["watchdog"]["dispatched"], 1)

    def test_controller_atomic_json_fsyncs_parent_directory(self) -> None:
        source = SCRIPT.read_text(encoding="utf-8")
        self.assertIn("os.replace(temp, path)", source)
        self.assertIn("_fsync_dir(path.parent)", source)

    def test_watchdog_and_chatgpt_nudge_have_independent_systemd_timers(self) -> None:
        watchdog_service = ROOT / "deploy" / "systemd" / "shopvivaliz-continuity-watchdog.service"
        watchdog_timer = ROOT / "deploy" / "systemd" / "shopvivaliz-continuity-watchdog.timer"
        nudge_service = ROOT / "deploy" / "systemd" / "shopvivaliz-chatgpt-nudge-dispatcher.service"
        nudge_timer = ROOT / "deploy" / "systemd" / "shopvivaliz-chatgpt-nudge-dispatcher.timer"
        installer = (ROOT / "scripts" / "install-gemini-24x7-controller.sh").read_text(encoding="utf-8")

        for path in (watchdog_service, watchdog_timer, nudge_service, nudge_timer):
            self.assertTrue(path.is_file(), f"independent continuity unit missing: {path.name}")

        watchdog_body = watchdog_service.read_text(encoding="utf-8")
        nudge_body = nudge_service.read_text(encoding="utf-8")
        watchdog_timer_body = watchdog_timer.read_text(encoding="utf-8")
        nudge_timer_body = nudge_timer.read_text(encoding="utf-8")

        self.assertIn("SHOPVIVALIZ_CONTINUITY_WATCHDOG_ENTRY", watchdog_body)
        self.assertIn("--stale-seconds 120", watchdog_body)
        self.assertIn("SHOPVIVALIZ_CHATGPT_NUDGE_ENTRY", nudge_body)
        self.assertIn("OnUnitInactiveSec=30s", watchdog_timer_body)
        self.assertIn("OnUnitInactiveSec=30s", nudge_timer_body)
        self.assertIn("shopvivaliz-continuity-watchdog.timer", installer)
        self.assertIn("shopvivaliz-chatgpt-nudge-dispatcher.timer", installer)
        self.assertIn("SHOPVIVALIZ_CONTINUITY_WATCHDOG_ENTRY=", installer)
        self.assertIn("SHOPVIVALIZ_CHATGPT_NUDGE_ENTRY=", installer)

    def test_resume_worker_service_is_installed_from_immutable_release(self) -> None:
        worker_unit = ROOT / "deploy" / "systemd" / "shopvivaliz-task-resume-worker.service"
        installer_path = ROOT / "scripts" / "install-gemini-24x7-controller.sh"
        installer = installer_path.read_text(encoding="utf-8")

        self.assertTrue(worker_unit.is_file(), "detached resume worker unit missing")
        body = worker_unit.read_text(encoding="utf-8")
        self.assertIn("User=ubuntu", body)
        self.assertIn("Group=ubuntu", body)
        self.assertIn("EnvironmentFile=/etc/shopvivaliz-gemini-24x7-controller.env", body)
        self.assertIn("${SHOPVIVALIZ_RESUME_WORKER_ENTRY} --daemon --interval-seconds 5", body)
        self.assertIn("Restart=on-failure", body)
        self.assertIn("KillMode=control-group", body)
        self.assertNotIn("PartOf=shopvivaliz-gemini-24x7-controller.service", body)

        self.assertIn('resume_worker_service_name="shopvivaliz-task-resume-worker.service"', installer)
        self.assertIn('task_resume_worker.py', installer)
        self.assertIn('SHOPVIVALIZ_RESUME_WORKER_ENTRY=', installer)
        self.assertIn('sudo install -o root -g root -m 0644 "$resume_worker_service_source" "$resume_worker_service_target"', installer)
        self.assertIn('"$resume_worker_service_target"', installer)
        self.assertIn('sudo systemctl enable "$resume_worker_service_name"', installer)
        self.assertIn('sudo systemctl restart "$resume_worker_service_name"', installer)
        self.assertIn('sudo systemctl is-active --quiet "$resume_worker_service_name"', installer)

    def test_controller_immutable_release_bundles_continuity_dependencies(self) -> None:
        installer = (ROOT / "scripts" / "install-gemini-24x7-controller.sh").read_text(encoding="utf-8")
        self.assertIn('sudo install -d -o root -g root -m 0755 "$stage_dir/scripts/continuity"', installer)
        self.assertIn('for source in "$release_dir"/scripts/continuity/*.py', installer)
        self.assertIn('"$stage_dir/scripts/continuity/$(basename "$source")"', installer)
        self.assertIn('sudo chmod 0755 "$stage_dir" "$stage_dir/scripts" "$stage_dir/scripts/continuity"', installer)

    def test_controller_installer_clears_systemd_start_limit_before_restart(self) -> None:
        installer = (ROOT / "scripts" / "install-gemini-24x7-controller.sh").read_text(encoding="utf-8")
        reset = 'sudo systemctl reset-failed "$unit_name" "$resume_worker_service_name"'
        self.assertIn(reset, installer)
        self.assertLess(installer.index(reset), installer.index('sudo systemctl restart "$unit_name"'))
        self.assertLess(installer.index(reset), installer.index('sudo systemctl restart "$resume_worker_service_name"'))

    def test_controller_installer_repairs_shared_runtime_lock_permissions(self) -> None:
        installer = (ROOT / "scripts" / "install-gemini-24x7-controller.sh").read_text(encoding="utf-8")
        self.assertIn('runtime_lock_dir="$runtime_dir/_runtime-lock"', installer)
        self.assertIn('sudo install -d -o ubuntu -g ubuntu -m 2770 "$runtime_lock_dir"', installer)
        self.assertIn('for artifact in lock.json lock.lock; do', installer)
        self.assertIn('sudo chown ubuntu:ubuntu "$runtime_lock_dir/$artifact"', installer)
        self.assertIn('sudo chmod 0660 "$runtime_lock_dir/$artifact"', installer)

    def test_controller_installer_consumes_same_durable_handoff_flag_and_preserves_state(self) -> None:
        installer = (ROOT / "scripts" / "install-gemini-24x7-controller.sh").read_text(encoding="utf-8")
        self.assertIn('durable_handoff="${SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF:-1}"', installer)
        self.assertIn('case "$durable_handoff" in 0|1)', installer)
        self.assertIn('SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF=%s', installer)
        self.assertIn('"$durable_handoff"', installer)
        self.assertNotIn('rm -rf "$runtime_dir/_conversation-leases"', installer)
        self.assertNotIn('rm -rf "$runtime_dir/_runtime-lock"', installer)


    def test_controller_service_and_installer_are_backend_safe(self) -> None:
        unit = (ROOT / "deploy" / "systemd" / "shopvivaliz-gemini-24x7-controller.service").read_text(encoding="utf-8")
        installer = (ROOT / "scripts" / "install-gemini-24x7-controller.sh").read_text(encoding="utf-8")
        self.assertIn("${SHOPVIVALIZ_GEMINI_CONTROLLER_ENTRY} --daemon", unit)
        self.assertIn("Restart=always", unit)
        self.assertIn("shopvivaliz-gemini-24x7-controller", installer)
        self.assertIn('base_dir="/opt/shopvivaliz-gemini-24x7-controller"', installer)
        self.assertIn('releases_dir="$base_dir/releases"', installer)
        self.assertNotIn("current/", installer)
        self.assertIn("CHATGPT_CONTINUITY_BRIDGE_URL=http://127.0.0.1:18081", installer)
        self.assertIn("CHATGPT_CONTINUITY_MONITOR_REQUIRED=1", installer)
        self.assertIn("CHATGPT_CONTINUITY_BRIDGE_TOKEN_FILE=/home/ubuntu/.config/shopvivaliz-chatgpt-continuity/bridge.token", installer)
        self.assertIn("GEMINI_ENV_FILE=/home/ubuntu/.config/shopvivaliz-gemini-24x7/gemini.env", installer)
        self.assertIn('gemini_cli_version="${SHOPVIVALIZ_GEMINI_CLI_VERSION:-0.62.0}"', installer)
        self.assertIn('gemini_cli_bin="/home/ubuntu/.local/bin/gemini"', installer)
        self.assertIn('gemini_cli_package_json="/home/ubuntu/.local/lib/node_modules/@google/gemini-cli/package.json"', installer)
        self.assertIn("read_gemini_package_version()", installer)
        self.assertNotIn('"$gemini_cli_bin" --version', installer)
        self.assertIn('npm install -g "@google/gemini-cli@$gemini_cli_version" --prefix /home/ubuntu/.local', installer)
        self.assertIn("SHOPVIVALIZ_BACKGROUND_CODEX_FALLBACK=1", installer)
        self.assertIn("CODEX_AUTO_BIN=/home/ubuntu/.local/bin/codex-auto", installer)
        self.assertIn('runtime_dir="/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state"', installer)
        self.assertIn('e2e_failures_dir="/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state-e2e-failures"', installer)
        self.assertIn('sudo install -d -o ubuntu -g ubuntu -m 0700 "$runtime_dir" "$e2e_failures_dir"', installer)
        self.assertIn('compile_cache="$(mktemp -d)"', installer)
        self.assertIn('PYTHONPYCACHEPREFIX="$compile_cache" python3 -m py_compile', installer)
        self.assertIn('rm -rf "$compile_cache"', installer)

    def test_controller_installer_printf_keeps_environment_arguments_attached(self) -> None:
        installer = (ROOT / "scripts" / "install-gemini-24x7-controller.sh").read_text(encoding="utf-8")
        lines = installer.splitlines()
        index = next(i for i, line in enumerate(lines) if line.startswith("printf 'SHOPVIVALIZ_GEMINI_CONTROLLER_ENTRY="))
        self.assertTrue(lines[index].rstrip().endswith(chr(92)))
        self.assertEqual(lines[index + 1].strip(), '"$target_dir/scripts/gemini_24x7_controller.py" ' + chr(92))


    def test_durable_handoff_controller_requests_proactive_convergence(self) -> None:
        controller = load_controller()
        calls = []

        def fake_watchdog(**kwargs):
            calls.append(kwargs)
            return {"scanned": 1, "eligible": 0, "dispatched": 0, "mode": "proactive"}

        with (
            patch.dict(os.environ, {"SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF": "1"}, clear=False),
            patch.object(controller.watchdog, "run_once", side_effect=fake_watchdog),
            patch.object(controller.nudge_dispatcher, "run_once", return_value={}),
            patch.object(controller.dispatcher, "run_once", return_value={}),
        ):
            result = controller.run_once(runtime_dir=self.runtime, owner_id="proactive-owner")

        self.assertEqual(len(calls), 1)
        self.assertTrue(calls[0]["proactive"])
        self.assertTrue(result["durable_handoff_enabled"])

    def test_controller_terminalizes_ready_to_complete_checkpoint_each_cycle(self) -> None:
        controller = load_controller()
        from scripts import agent_task_state as task_state

        previous_runtime = task_state.RUNTIME_DIR
        task_state.RUNTIME_DIR = self.runtime
        try:
            task_state.start_task("ready-cycle", "finalizar tarefa verificada", "gpt")
            task_state.mark_ready(
                "ready-cycle",
                evidence=["validacao fresca PASS"],
                verification="objetivo original verificado",
            )
        finally:
            task_state.RUNTIME_DIR = previous_runtime

        with (
            patch.object(controller.watchdog, "run_once", return_value={"scanned": 1, "eligible": 0, "dispatched": 0}),
            patch.object(controller.nudge_dispatcher, "run_once", return_value={}),
            patch.object(controller.dispatcher, "run_once", return_value={}),
        ):
            result = controller.run_once(runtime_dir=self.runtime, owner_id="terminalize-owner")

        current = json.loads((self.runtime / "ready-cycle.json").read_text(encoding="utf-8"))
        self.assertEqual(current["status"], "CONCLUIDO")
        self.assertEqual(result["completion_sweep"]["completed"], 1)


    def test_controller_defers_ready_terminalization_while_foreground_lease_is_live(self) -> None:
        controller = load_controller()
        from scripts import agent_task_state as task_state

        previous_runtime = task_state.RUNTIME_DIR
        task_state.RUNTIME_DIR = self.runtime
        try:
            task_state.start_task("ready-foreground", "finalizar sem competir com foreground", "gpt")
            task_state.bind_conversation(
                "ready-foreground",
                conversation_id="conversation_ready_foreground",
            )
            task_state.bind_browser_session(
                "ready-foreground",
                browser_session="atendimento",
            )
            task_state.acquire_foreground_lease_for_task(
                "ready-foreground",
                owner_id="interactive-turn",
                ttl_seconds=300,
            )
            task_state.mark_ready(
                "ready-foreground",
                evidence=["validacao foreground PASS"],
                verification="objetivo verificado pelo turno ativo",
            )
        finally:
            task_state.RUNTIME_DIR = previous_runtime

        with (
            patch.dict(os.environ, {"SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF": "1"}, clear=False),
            patch.object(controller.watchdog, "run_once", return_value={"scanned": 1, "eligible": 0, "dispatched": 0}),
            patch.object(controller.nudge_dispatcher, "run_once", return_value={}),
            patch.object(controller.dispatcher, "run_once", return_value={}),
        ):
            result = controller.run_once(runtime_dir=self.runtime, owner_id="terminalize-foreground-owner")

        current = json.loads((self.runtime / "ready-foreground.json").read_text(encoding="utf-8"))
        self.assertEqual(current["status"], "READY_TO_COMPLETE")
        self.assertEqual(result["completion_sweep"]["deferred_foreground"], 1)
        self.assertEqual(result["completion_sweep"]["completed"], 0)


if __name__ == "__main__":
    unittest.main()
