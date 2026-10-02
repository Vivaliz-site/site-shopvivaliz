from __future__ import annotations

import importlib.util
import json
import subprocess
import sys
import tempfile
import unittest
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

    def tearDown(self) -> None:
        self.temp.cleanup()

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

    def test_controller_service_and_installer_are_backend_safe(self) -> None:
        unit = (ROOT / "deploy" / "systemd" / "shopvivaliz-gemini-24x7-controller.service").read_text(encoding="utf-8")
        installer = (ROOT / "scripts" / "install-gemini-24x7-controller.sh").read_text(encoding="utf-8")
        self.assertIn("${SHOPVIVALIZ_GEMINI_CONTROLLER_ENTRY} --daemon", unit)
        self.assertIn("Restart=on-failure", unit)
        self.assertIn("shopvivaliz-gemini-24x7-controller", installer)
        self.assertIn('base_dir="/opt/shopvivaliz-gemini-24x7-controller"', installer)
        self.assertIn('releases_dir="$base_dir/releases"', installer)
        self.assertNotIn("current/", installer)
        self.assertIn("CHATGPT_CONTINUITY_BRIDGE_URL=http://127.0.0.1:18081", installer)
        self.assertIn("CHATGPT_CONTINUITY_BRIDGE_TOKEN_FILE=/home/ubuntu/.config/shopvivaliz-chatgpt-continuity/bridge.token", installer)
        self.assertIn("GEMINI_ENV_FILE=/home/ubuntu/.config/shopvivaliz-gemini-24x7/gemini.env", installer)
        self.assertIn('gemini_cli_version="${SHOPVIVALIZ_GEMINI_CLI_VERSION:-0.62.0}"', installer)
        self.assertIn('gemini_cli_bin="/home/ubuntu/.local/bin/gemini"', installer)
        self.assertIn('npm install -g "@google/gemini-cli@$gemini_cli_version" --prefix /home/ubuntu/.local', installer)
        self.assertIn("SHOPVIVALIZ_BACKGROUND_CODEX_FALLBACK=1", installer)
        self.assertIn("CODEX_AUTO_BIN=/home/ubuntu/.local/bin/codex-auto", installer)
        self.assertIn('runtime_dir="/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state"', installer)
        self.assertIn('e2e_failures_dir="/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state-e2e-failures"', installer)
        self.assertIn('sudo install -d -o ubuntu -g ubuntu -m 0700 "$runtime_dir" "$e2e_failures_dir"', installer)
        self.assertIn('compile_cache="$(mktemp -d)"', installer)
        self.assertIn('PYTHONPYCACHEPREFIX="$compile_cache" python3 -m py_compile', installer)
        self.assertIn('rm -rf "$compile_cache"', installer)


if __name__ == "__main__":
    unittest.main()
