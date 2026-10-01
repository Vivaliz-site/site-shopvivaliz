from __future__ import annotations

import importlib.util
import json
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


if __name__ == "__main__":
    unittest.main()
