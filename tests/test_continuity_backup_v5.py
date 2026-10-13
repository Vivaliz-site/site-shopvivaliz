#!/usr/bin/env python3
"""Regression tests for fail-closed canonical task-state backups."""
from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/continuity/backup_state_v5.py"
SERVICE = ROOT / "ops/systemd/shopvivaliz-continuity-state-backup.service"
TIMER = ROOT / "ops/systemd/shopvivaliz-continuity-state-backup.timer"


class BackupStateV5Tests(unittest.TestCase):
    def setUp(self):
        self.workspace = tempfile.TemporaryDirectory()
        self.addCleanup(self.workspace.cleanup)
        root = Path(self.workspace.name)
        self.state = root / "agent-task-state"
        self.backups = root / "backups"
        self.state.mkdir(mode=0o700)
        self.backups.mkdir(mode=0o700)
        (self.state / "_agent-task-state.lock").touch(mode=0o600)
        self.record = {
            "task_id": "v5-test", "status": "RUNNING",
            "next_action": "preserve progress", "created_at": "2026-10-13T00:00:00Z",
        }
        (self.state / "v5-test.json").write_text(json.dumps(self.record))

    def run_backup(self):
        return subprocess.run(
            [sys.executable, str(SCRIPT),
             "--state-dir", str(self.state), "--backup-dir", str(self.backups)],
            text=True, capture_output=True, timeout=25, check=False,
        )

    def archives(self):
        return list(self.backups.glob("continuity-v5-*.tar.gz"))

    def test_archive_hash_and_readback(self):
        result = self.run_backup()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(len(self.archives()), 1)
        archive = self.archives()[0]
        self.assertEqual(archive.stat().st_mode & 0o777, 0o600)
        sidecar = (self.backups / (archive.name + ".sha256")).read_text()
        self.assertEqual(sidecar.split()[0], hashlib.sha256(archive.read_bytes()).hexdigest())
        with tarfile.open(archive, "r:gz") as tf:
            self.assertEqual(json.load(tf.extractfile("v5-test.json")), self.record)

    def test_transient_files_excluded(self):
        (self.state / ".resume-private.txt").write_text("never include ephemeral prompts")
        (self.state / "checkpoint.tmp.1").write_text("partial")
        result = self.run_backup()
        self.assertEqual(result.returncode, 0, result.stderr)
        with tarfile.open(self.archives()[0], "r:gz") as tf:
            names = set(tf.getnames())
        self.assertIn("v5-test.json", names)
        self.assertNotIn(".resume-private.txt", names)
        self.assertNotIn("checkpoint.tmp.1", names)

    def test_invalid_task_json_blocks_publication(self):
        (self.state / "damaged.json").write_text("{broken")
        result = self.run_backup()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(self.archives(), [])

    def test_symlink_blocks_publication(self):
        outside = Path(self.workspace.name) / "outside"
        outside.write_text("never archive outside data")
        (self.state / "outside-link").symlink_to(outside)
        result = self.run_backup()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(self.archives(), [])

    def test_public_backup_directory_is_rejected(self):
        self.backups.chmod(0o755)
        result = self.run_backup()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(self.archives(), [])

    def test_systemd_timer_and_service_are_readonly_to_source(self):
        service = SERVICE.read_text()
        timer = TIMER.read_text()
        self.assertIn("ProtectSystem=strict", service)
        self.assertIn("ProtectHome=read-only", service)
        self.assertIn("ReadWritePaths=/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state-backups", service)
        self.assertIn("NoNewPrivileges=true", service)
        self.assertIn("Persistent=true", timer)
        self.assertIn("OnCalendar=", timer)


if __name__ == "__main__":
    unittest.main()
