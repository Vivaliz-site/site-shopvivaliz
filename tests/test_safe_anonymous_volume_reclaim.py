#!/usr/bin/env python3
"""Regression tests: anonymous-volume reclaim must fail closed."""
import datetime as dt
import importlib.util
from pathlib import Path
import tempfile
import unittest

SOURCE = Path(__file__).resolve().parents[1] / "ops/host/shopvivaliz-safe-anonymous-volume-reclaim.py"
SPEC = importlib.util.spec_from_file_location("safe_volume_reclaim", SOURCE)
assert SPEC and SPEC.loader
reclaim = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(reclaim)


class VolumeEligibilityTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / "volumes"
        self.root.mkdir()
        self.name = "a" * 64
        self.path = self.root / self.name / "_data"
        self.path.mkdir(parents=True)
        self.now = dt.datetime(2026, 10, 14, tzinfo=dt.timezone.utc)
        self.meta = {
            "Name": self.name,
            "Labels": {"com.docker.volume.anonymous": "true"},
            "Driver": "local",
            "CreatedAt": "2026-10-12T00:00:00+00:00",
            "Mountpoint": str(self.path),
        }

    def reason(self, referenced=None, meta=None):
        return reclaim.metadata_reason(
            meta or self.meta, referenced or set(), self.now, self.root, 24
        )

    def test_old_unreferenced_anonymous_local_volume_is_candidate(self):
        self.assertEqual(self.reason(), "eligible")

    def test_preserves_named_volumes_even_when_unreferenced(self):
        self.meta["Name"] = "mei_mg_email_pgdata"
        self.assertEqual(self.reason(), "not_engine_anonymous")

    def test_preserves_volumes_without_engine_anonymous_label(self):
        self.meta["Labels"] = {"com.docker.compose.project": "app"}
        self.assertEqual(self.reason(), "not_engine_anonymous")

    def test_preserves_container_referenced_volume(self):
        self.assertEqual(self.reason({self.name}), "referenced")

    def test_preserves_recent_volume(self):
        self.meta["CreatedAt"] = "2026-10-13T23:30:00+00:00"
        self.assertEqual(self.reason(), "recent")

    def test_preserves_volume_with_invalid_timestamp(self):
        self.meta["CreatedAt"] = "invalid"
        self.assertEqual(self.reason(), "invalid_age")

    def test_preserves_foreign_mountpoint(self):
        self.meta["Mountpoint"] = "/some/other/location"
        self.assertEqual(self.reason(), "unexpected_mountpoint")

    def test_preserves_missing_mountpoint(self):
        self.path.rmdir()
        self.assertEqual(self.reason(), "unexpected_mountpoint")

    def test_preserves_any_pg15_cluster(self):
        self.assertEqual(reclaim.content_reason(4_000_000, "15", 500), "database_preserved")

    def test_pg16_test_cluster_requires_72_hours(self):
        self.assertEqual(
            reclaim.content_reason(49_000_000, "16", 30),
            "test_postgres_quarantine",
        )

    def test_small_old_pg16_anonymous_test_cluster_can_be_reclaimed(self):
        self.assertEqual(reclaim.content_reason(49_000_000, "16", 90), "eligible")

    def test_large_pg16_cluster_is_preserved(self):
        self.assertEqual(
            reclaim.content_reason(90_000_000, "16", 100),
            "database_oversized",
        )

    def test_oversized_or_unknown_data_is_preserved(self):
        self.assertEqual(reclaim.content_reason(301_000_000, None, 100), "oversized")
        self.assertEqual(reclaim.content_reason(-1, None, 100), "size_unknown")

    def test_no_broad_volume_prune_or_force_removal(self):
        text = SOURCE.read_text()
        self.assertNotIn('["docker", "volume", "prune"', text)
        self.assertNotIn('"volume", "rm", "--force"', text)
        self.assertIn('"ps", "-aq"', text)
        self.assertIn('"docker", "volume", "rm", name', text)
        self.assertIn('"--apply", action="store_true"', text)


if __name__ == "__main__":
    unittest.main()
