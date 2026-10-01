from __future__ import annotations

import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path


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

    def test_controller_service_and_installer_are_backend_safe(self) -> None:
        unit = (ROOT / "deploy" / "systemd" / "shopvivaliz-gemini-24x7-controller.service").read_text(encoding="utf-8")
        installer = (ROOT / "scripts" / "install-gemini-24x7-controller.sh").read_text(encoding="utf-8")
        self.assertIn("${SHOPVIVALIZ_GEMINI_CONTROLLER_ENTRY} --daemon", unit)
        self.assertIn("Restart=on-failure", unit)
        self.assertIn("shopvivaliz-gemini-24x7-controller", installer)
        self.assertIn('base_dir="/opt/shopvivaliz-gemini-24x7-controller"', installer)
        self.assertIn('releases_dir="$base_dir/releases"', installer)
        self.assertNotIn("current/", installer)


if __name__ == "__main__":
    unittest.main()
