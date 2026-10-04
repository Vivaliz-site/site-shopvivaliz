import hashlib
import importlib.util
import unittest
from pathlib import Path
from unittest import mock

MODULE = Path(__file__).parents[1] / "ops" / "db_backup.py"
spec = importlib.util.spec_from_file_location("db_backup", MODULE)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

class DbBackupManifestTests(unittest.TestCase):
    def test_main_profile_covers_three_persistent_databases(self):
        targets = mod.targets_for("main")
        self.assertEqual([t["name"] for t in targets], [
            "solange-production", "solange-staging", "mei-email"
        ])
        self.assertEqual(targets[0]["container"], "supabase_db_solange-rolla-consultorio")
        self.assertEqual(targets[1]["container"], "supabase_db_solange-client-demo")

    def test_secondary_profile_only_covers_persistent_mlrr_database(self):
        targets = mod.targets_for("secondary")
        self.assertEqual(len(targets), 1)
        self.assertEqual(targets[0]["container"], "mlrr-pg-prod")
        self.assertEqual(targets[0]["user"], "postgres")
        self.assertEqual(targets[0]["database"], "mlrr_phase3")
        self.assertEqual(targets[0]["mode"], "container")

    def test_verify_remote_copy_rejects_corrupted_download(self):
        payload = b"expected remote dump bytes"
        expected_digest = hashlib.sha256(payload).hexdigest()

        def fake_run(args, *, env=None, stdin=None, stdout=None):
            if "object" in args and "get" in args:
                path = Path(args[args.index("--file") + 1])
                if args[args.index("--name") + 1].endswith(".sha256"):
                    path.write_text(f"{expected_digest}  mei-email-latest.dump\n")
                else:
                    path.write_bytes(b"X" * len(payload))
                return
            if args[0] == mod.PG_RESTORE:
                stdout.write("1 TABLE a\n2 TABLE b\n3 TABLE c\n4 TABLE d\n5 TABLE e\n6 TABLE f\n")
                return
            raise AssertionError(args)

        with mock.patch.object(mod, "run_checked", side_effect=fake_run):
            with self.assertRaisesRegex(RuntimeError, "remote sha256 mismatch"):
                mod.verify_remote_copy("db-backups/main/mei-email-latest.dump", len(payload), expected_digest)


    def test_systemd_backup_uses_canonical_versioned_helper(self):
        root = Path(__file__).parents[1]
        wrapper = root / "ops" / "host" / "shopvivaliz-db-backup"
        service = root / "deploy" / "systemd" / "shopvivaliz-db-backup.service"
        timer = root / "deploy" / "systemd" / "shopvivaliz-db-backup.timer"
        installer = root / "scripts" / "install-db-backup-service.sh"

        self.assertTrue(wrapper.exists())
        wrapper_text = wrapper.read_text()
        self.assertIn("HELPER=/home/ubuntu/.local/bin/shopvivaliz-db-backup.py", wrapper_text)
        self.assertIn("/usr/sbin/runuser -u ubuntu", wrapper_text)
        self.assertIn("--profile main --target mei-email", wrapper_text)
        self.assertIn("ExecStart=/usr/local/sbin/shopvivaliz-db-backup", service.read_text())
        self.assertIn("OnCalendar=Sun *-*-* 04:30:00 UTC", timer.read_text())
        self.assertIn("RandomizedDelaySec=10m", timer.read_text())
        self.assertIn("Persistent=true", timer.read_text())
        installer_text = installer.read_text()
        self.assertIn("ops/db_backup.py", installer_text)
        self.assertIn("shopvivaliz-db-backup.timer", installer_text)

    def test_object_names_are_host_scoped(self):
        obj = mod.object_name("secondary", "mlrr-phase3")
        self.assertEqual(obj, "db-backups/secondary/mlrr-phase3-latest.dump")

if __name__ == "__main__":
    unittest.main()
