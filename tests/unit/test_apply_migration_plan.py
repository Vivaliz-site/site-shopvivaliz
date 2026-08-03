import json
import tempfile
import unittest
from pathlib import Path

from scripts.maintenance.apply_migration_plan import (
    Migration,
    MigrationError,
    apply_plan,
    normalized_relative,
    parse_plan,
    preflight,
    validate_applied,
)


class SafeMigrationPlanTest(unittest.TestCase):
    def test_rejects_path_traversal(self):
        for value in ("../secret.txt", "/tmp/file", "a/../../b"):
            with self.assertRaises(MigrationError):
                normalized_relative(value)

    def test_rejects_active_workflow_move(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            plan = Path(temp_dir) / "plan.json"
            plan.write_text(
                json.dumps(
                    {
                        "migrations": [
                            {
                                "source": ".github/workflows/deploy.yml",
                                "target": ".github/workflows-archive/paused/deploy.yml",
                                "kind": "raw",
                                "keep_compatibility": False,
                            }
                        ]
                    }
                ),
                encoding="utf-8",
            )
            with self.assertRaisesRegex(MigrationError, "dedicated reviewed PR"):
                parse_plan(plan)

    def test_python_migration_moves_content_and_creates_wrapper(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            source = root / "scripts" / "legacy.py"
            source.parent.mkdir(parents=True)
            source.write_text("VALUE = 42\n", encoding="utf-8")
            migration = Migration(
                source="scripts/legacy.py",
                target="scripts/maintenance/legacy.py",
                kind="python",
            )
            preflight(root, [migration])
            results = apply_plan(root, [migration])
            validate_applied(root, [migration])
            self.assertEqual(results[0]["target"], "scripts/maintenance/legacy.py")
            self.assertEqual(
                (root / "scripts" / "maintenance" / "legacy.py").read_text(encoding="utf-8"),
                "VALUE = 42\n",
            )
            wrapper = source.read_text(encoding="utf-8")
            self.assertIn("runpy.run_path", wrapper)
            self.assertIn("maintenance/legacy.py", wrapper)

    def test_rejects_target_collision_before_moving_anything(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            source = root / "scripts" / "one.py"
            target = root / "scripts" / "ai" / "one.py"
            source.parent.mkdir(parents=True)
            target.parent.mkdir(parents=True)
            source.write_text("one\n", encoding="utf-8")
            target.write_text("existing\n", encoding="utf-8")
            migration = Migration("scripts/one.py", "scripts/ai/one.py", "python")
            with self.assertRaisesRegex(MigrationError, "target already exists"):
                preflight(root, [migration])
            self.assertEqual(source.read_text(encoding="utf-8"), "one\n")

    def test_blocks_document_with_likely_secret(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            source = root / "OLD.md"
            source.write_text("token=abcdefghijklmnopqrstuvwxyz1234567890\n", encoding="utf-8")
            migration = Migration("OLD.md", "docs/audits/OLD.md", "document")
            with self.assertRaisesRegex(MigrationError, "requires sanitization"):
                preflight(root, [migration])

    def test_raw_archive_requires_source_removal(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            source = root / "temporary.txt"
            source.write_text("artifact\n", encoding="utf-8")
            migration = Migration(
                "temporary.txt",
                "archive/2026/temporary.txt",
                "raw",
                keep_compatibility=False,
            )
            apply_plan(root, [migration])
            validate_applied(root, [migration])
            self.assertFalse(source.exists())
            self.assertTrue((root / "archive" / "2026" / "temporary.txt").is_file())


if __name__ == "__main__":
    unittest.main()
