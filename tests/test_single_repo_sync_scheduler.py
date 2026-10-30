from __future__ import annotations

import os
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INSTALLER = ROOT / "scripts" / "install-auto-sync-oracle.sh"


class SingleRepoSyncSchedulerTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix="sv-single-sync-")
        self.addCleanup(self.tmp.cleanup)
        self.path = Path(self.tmp.name)
        scripts = self.path / "bin"
        scripts.mkdir()
        self.cron = self.path / "crontab.txt"
        self.cron.write_text(
            "0 3 * * * /bin/echo unrelated-maintenance\n"
            "*/5 * * * * /usr/bin/bash /opt/shopvivaliz/scripts/auto-sync-oracle.sh\n",
            encoding="utf-8",
        )
        wrapper = scripts / "crontab"
        wrapper.write_text(
            '#!/bin/sh\n'
            'if [ "$1" = "-l" ]; then cat "$SV_TEST_CRON"; '
            'else cat "$1" > "$SV_TEST_CRON"; fi\n',
            encoding="utf-8",
        )
        wrapper.chmod(0o755)
        systemctl = scripts / "systemctl"
        systemctl.write_text(
            '#!/bin/sh\n'
            'if [ "$SV_TEST_SYSTEMD_ACTIVE" = "yes" ] && '
            '{ [ "$1" = "is-active" ] || [ "$1" = "is-enabled" ]; }; then exit 0; fi\n'
            'exit 3\n',
            encoding="utf-8",
        )
        systemctl.chmod(0o755)
        self.env = {
            **os.environ,
            "PATH": str(scripts) + os.pathsep + os.environ.get("PATH", ""),
            "SV_TEST_CRON": str(self.cron),
            "ROOT": str(ROOT),
            "SHARED_ROOT": str(self.path / "shared"),
            "CURRENT_ROOT": str(ROOT),
        }

    def install(self, active: bool):
        env = dict(self.env, SV_TEST_SYSTEMD_ACTIVE="yes" if active else "no")
        result = subprocess.run(["bash", str(INSTALLER)], env=env,
                                capture_output=True, text=True, timeout=12)
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        return result.stdout

    def test_systemd_primary_removes_duplicate_cron_and_is_idempotent(self):
        out = self.install(active=True)
        self.assertIn("SAFE_SYNC_CRON_DUPLICATE_REMOVED=YES", out)
        self.assertIn("SAFE_SYNC_SYSTEMD_CANONICAL=PASS", out)
        text = self.cron.read_text(encoding="utf-8")
        self.assertNotIn("auto-sync-oracle.sh", text)
        self.assertIn("unrelated-maintenance", text)
        second = self.install(active=True)
        self.assertIn("SAFE_SYNC_SYSTEMD_CANONICAL=PASS", second)
        self.assertEqual(text, self.cron.read_text(encoding="utf-8"))

    def test_cron_remains_fallback_without_healthy_systemd_timer(self):
        self.cron.write_text("0 3 * * * /bin/echo unrelated-maintenance\n", encoding="utf-8")
        out = self.install(active=False)
        text = self.cron.read_text(encoding="utf-8")
        self.assertIn("instalado a cada 5 minutos", out)
        self.assertEqual(text.count("auto-sync-oracle.sh"), 1)
        self.assertIn("unrelated-maintenance", text)
        self.install(active=False)
        self.assertEqual(self.cron.read_text(encoding="utf-8").count("auto-sync-oracle.sh"), 1)


if __name__ == "__main__":
    unittest.main()
