import importlib.util
import unittest
from pathlib import Path

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

    def test_object_names_are_host_scoped(self):
        obj = mod.object_name("secondary", "mlrr-phase3")
        self.assertEqual(obj, "db-backups/secondary/mlrr-phase3-latest.dump")

if __name__ == "__main__":
    unittest.main()
