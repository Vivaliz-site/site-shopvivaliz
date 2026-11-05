"""Installer for the Marina edge smoke timer: units, drop-in and failure paths. No root, no systemd."""
from __future__ import annotations

import os
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INSTALLER = ROOT / "scripts" / "install-marina-edge-smoke-timer.sh"


def run_installer(unit_dir: Path, smoke_dir: str | None, release: Path = ROOT) -> subprocess.CompletedProcess:
    env = {
        "PATH": os.environ.get("PATH", "/usr/bin:/bin"),
        "SHOPVIVALIZ_RELEASE": str(release),
        "SHOPVIVALIZ_UNIT_DIR": str(unit_dir),
        "SHOPVIVALIZ_SUDO": "",
        "SHOPVIVALIZ_SKIP_SYSTEMCTL": "1",
    }
    if smoke_dir is not None:
        env["SHOPVIVALIZ_SMOKE_DIR"] = smoke_dir
    return subprocess.run(["bash", str(INSTALLER)], env=env, capture_output=True, text=True, timeout=60)


class InstallMarinaEdgeSmokeTimerTest(unittest.TestCase):
    def test_default_release_installs_units_without_dropin(self):
        with tempfile.TemporaryDirectory() as tmp:
            result = run_installer(Path(tmp), None)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertTrue((Path(tmp) / "shopvivaliz-marina-edge-smoke.timer").is_file())
            self.assertTrue((Path(tmp) / "shopvivaliz-marina-edge-smoke.service").is_file())
            self.assertFalse((Path(tmp) / "shopvivaliz-marina-edge-smoke.service.d").exists())

    def test_smoke_dir_writes_dropin_pointing_at_checkout(self):
        with tempfile.TemporaryDirectory() as tmp, tempfile.TemporaryDirectory() as smoke:
            (Path(smoke) / "scripts").mkdir()
            (Path(smoke) / "scripts" / "marina_mcp_edge_smoke.py").write_text("# stub\n")
            result = run_installer(Path(tmp), smoke)
            self.assertEqual(result.returncode, 0, result.stderr)
            dropin = (Path(tmp) / "shopvivaliz-marina-edge-smoke.service.d" / "override.conf").read_text()
            self.assertIn(f"WorkingDirectory={smoke}", dropin)
            self.assertIn(f"ExecStart=/usr/bin/python3 {smoke}/scripts/marina_mcp_edge_smoke.py", dropin)
            self.assertEqual(dropin.count("ExecStart="), 2, "must clear the inherited ExecStart before setting it")

    def test_missing_smoke_script_fails_closed(self):
        with tempfile.TemporaryDirectory() as tmp, tempfile.TemporaryDirectory() as empty:
            result = run_installer(Path(tmp), empty)
            self.assertEqual(result.returncode, 2)
            self.assertIn("missing smoke script", result.stderr)
            self.assertFalse((Path(tmp) / "shopvivaliz-marina-edge-smoke.service.d").exists())


if __name__ == "__main__":
    unittest.main()
