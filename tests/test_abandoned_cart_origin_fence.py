#!/usr/bin/env python3
"""Single-writer recovery fencing regression (no mail/DB/network calls)."""
from __future__ import annotations

import subprocess
import tempfile
import unittest
from pathlib import Path
import shutil
import os

ROOT = Path(__file__).resolve().parents[1]
SENDER = ROOT / "scripts/send-abandoned-cart-emails.php"
UNIT = ROOT / "deploy/systemd/shopvivaliz-abandoned-cart-recovery.service"
INSTALLER = ROOT / "scripts/install-abandoned-cart-recovery-service.sh"
CRON = ROOT / ".github/workflows/abandoned-cart-cron-install.yml"
FENCE = "/etc/shopvivaliz/migration/origin-workers-fenced"
LOCK = "/home/ubuntu/shopvivaliz-deploy/shared/locks/abandoned-cart-recovery.lock"


def bash_executable() -> str:
    candidates = [shutil.which("bash")]
    if os.name == "nt":
        candidates.extend([
            r"C:\Program Files\Git\bin\bash.exe",
            r"C:\Program Files\Git\usr\bin\bash.exe",
        ])
    for candidate in candidates:
        if not candidate:
            continue
        try:
            probe = subprocess.run(
                [candidate, "--version"],
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                timeout=5,
                check=False,
            )
        except (OSError, subprocess.SubprocessError):
            continue
        if probe.returncode == 0:
            return candidate
    return "bash"


BASH = bash_executable()


class CartOriginFenceTests(unittest.TestCase):
    def test_guard_runs_before_any_db_or_mailer_code(self):
        code = SENDER.read_text(encoding="utf8")
        before_bootstrap, bootstrap = code.split("require_once", 1)
        self.assertIn("is_file('" + FENCE + "')", before_bootstrap)
        self.assertIn("ABANDONED_CART_ORIGIN_FENCED=SKIP", before_bootstrap)
        self.assertIn("exit(0)", before_bootstrap)
        self.assertNotIn("sv_pdo(", before_bootstrap)
        self.assertNotIn("send_email(", before_bootstrap)
        self.assertIn("send_email(", bootstrap)

    def test_unit_and_privileged_installer_are_fence_aware(self):
        unit = UNIT.read_text(encoding="utf8")
        setup = INSTALLER.read_text(encoding="utf8")
        self.assertIn("ConditionPathExists=!" + FENCE, unit)
        self.assertIn("User=ubuntu", unit)
        self.assertIn("NoNewPrivileges=true", unit)
        self.assertIn(LOCK, unit)
        self.assertIn("[ -e " + FENCE + " ]", setup)
        self.assertIn("ORIGIN_CART_MAINTENANCE_LEASE_REQUIRED", setup)
        self.assertIn("id -u", setup)
        self.assertIn("install_abandoned_cart_recovery_requires_root", setup)
        self.assertLess(setup.index("[ -e " + FENCE + " ]"), setup.index("id -u"))
        self.assertNotIn("systemctl disable --now", setup)

    def test_fenced_installer_rejects_before_any_privileged_operation(self):
        text = INSTALLER.read_text(encoding="utf8")
        with tempfile.TemporaryDirectory(prefix="fenced-cart-installer-") as tmp:
            base = Path(tmp)
            fence = base / "synthetic-fenced-origin"
            script = base / "installer.sh"
            fence_path = str(fence).replace("\\", "/") if os.name == "nt" else str(fence)
            script.write_text(text.replace(FENCE, fence_path), encoding="utf8")
            fence.touch()
            denied = subprocess.run(
                [BASH, str(script)], stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                text=True, timeout=5, check=False,
            )
            self.assertEqual(denied.returncode, 75)
            self.assertIn("ORIGIN_CART_MAINTENANCE_LEASE_REQUIRED", denied.stderr)
            self.assertNotIn("ABANDONED_CART_RECOVERY_INSTALL=PASS", denied.stdout)

    def test_cron_installer_cannot_reenable_fenced_origin_or_double_timer(self):
        code = CRON.read_text(encoding="utf8")
        self.assertIn("ORIGIN_ABANDONED_CART_CRON_FENCED=SKIP", code)
        self.assertIn("ABANDONED_CART_SYSTEMD_SINGLE_OWNER=PASS", code)
        self.assertIn("shopvivaliz-abandoned-cart-recovery.timer", code)
        self.assertIn("LOCK_FILE='" + LOCK + "'", code)
        self.assertIn("StrictHostKeyChecking=yes", code)
        self.assertNotIn("StrictHostKeyChecking=no", code)
        self.assertIn("VM_KNOWN_HOSTS", code)

    def test_fence_guard_on_and_off_without_loading_application_or_sending_mail(self):
        full = SENDER.read_text(encoding="utf8")
        prefix = full.split("require_once", 1)[0]
        with tempfile.TemporaryDirectory(prefix="fenced-cart-fixture-") as tmp:
            work = Path(tmp)
            marker = work / "root-owned-fence-fixture"
            witness = work / "witness"
            fragment = prefix.replace(FENCE, str(marker))
            self.assertNotIn(FENCE, fragment)
            fixture = work / "fixture.php"
            fixture.write_text(
                fragment
                + "\nfile_put_contents(" + repr(str(witness)) + ", 'would_process');\n"
                + "echo 'GUARD_PASSTHROUGH=PASS' . PHP_EOL;\n",
                encoding="utf8",
            )
            marker.touch()
            blocked = subprocess.run(
                ["php", str(fixture)], text=True,
                stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                timeout=5, check=False,
            )
            self.assertEqual(blocked.returncode, 0)
            self.assertEqual(blocked.stdout, "ABANDONED_CART_ORIGIN_FENCED=SKIP\n")
            self.assertFalse(witness.exists(), "fenced source cannot reach DB/email code")
            marker.unlink()
            allowed = subprocess.run(
                ["php", str(fixture)], text=True,
                stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                timeout=5, check=False,
            )
            self.assertEqual(allowed.returncode, 0)
            self.assertEqual(allowed.stdout, "GUARD_PASSTHROUGH=PASS\n")
            self.assertTrue(witness.exists())


if __name__ == "__main__":
    unittest.main()
