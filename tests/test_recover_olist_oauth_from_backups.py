from __future__ import annotations

import importlib.util
import os
import tempfile
import unittest
from pathlib import Path


MODULE_PATH = Path(__file__).resolve().parents[1] / "scripts" / "recover-olist-oauth-from-backups.py"
SPEC = importlib.util.spec_from_file_location("recover_olist_oauth", MODULE_PATH)
recovery = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(recovery)


def write_env(path: Path, **values: str) -> None:
    path.write_text("".join(f"{key}={value}\n" for key, value in values.items()), encoding="utf-8")


class RecoverOlistOauthFromBackupsTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)

    def tearDown(self) -> None:
        self.temp.cleanup()

    def test_recovery_cross_matches_historical_client_with_current_refresh_token(self) -> None:
        current = self.root / ".env"
        write_env(
            current,
            UNCHANGED="keep-me",
            OLIST_CLIENT_ID="bad-current-id",
            OLIST_CLIENT_SECRET="bad-current-secret",
            OLIST_ACCESS_TOKEN="expired-access",
            OLIST_REFRESH_TOKEN="fresh-refresh-from-current",
        )
        backup = self.root / ".env.backup.100"
        write_env(
            backup,
            OLIST_CLIENT_ID="provider-recognized-id",
            OLIST_CLIENT_SECRET="provider-recognized-secret",
            OLIST_REFRESH_TOKEN="stale-refresh-from-backup",
        )
        os.utime(backup, (100, 100))

        seen: list[tuple[str, str]] = []

        def requester(values):
            pair = (values["OLIST_CLIENT_ID"], values["OLIST_REFRESH_TOKEN"])
            seen.append(pair)
            if values["OLIST_CLIENT_ID"] == "bad-current-id":
                return None, "invalid_client"
            if values["OLIST_REFRESH_TOKEN"] == "stale-refresh-from-backup":
                return None, "invalid_grant"
            if pair == ("provider-recognized-id", "fresh-refresh-from-current"):
                return {"access_token": "fresh-access", "refresh_token": "rotated-refresh"}, "ok"
            return None, "invalid_grant"

        report = recovery.recover(current, self.root, requester=requester)

        self.assertTrue(report["ok"])
        self.assertEqual(report["client_source"], backup.name)
        self.assertEqual(report["refresh_source"], "current")
        self.assertIn(("provider-recognized-id", "fresh-refresh-from-current"), seen)
        _, values = recovery.parse_env(current)
        self.assertEqual(values["UNCHANGED"], "keep-me")
        self.assertEqual(values["OLIST_CLIENT_ID"], "provider-recognized-id")
        self.assertEqual(values["OLIST_CLIENT_SECRET"], "provider-recognized-secret")
        self.assertEqual(values["OLIST_ACCESS_TOKEN"], "fresh-access")
        self.assertEqual(values["OLIST_REFRESH_TOKEN"], "rotated-refresh")
        self.assertTrue(list(self.root.glob(".env.pre-olist-recovery-*")))

    def test_current_tiny_client_pair_can_be_promoted_only_when_provider_accepts_it(self) -> None:
        current = self.root / ".env"
        write_env(
            current,
            OLIST_CLIENT_ID="rejected-olist-id",
            OLIST_CLIENT_SECRET="rejected-olist-secret",
            OLIST_REFRESH_TOKEN="current-refresh",
            TINY_CLIENT_ID="accepted-tiny-id",
            TINY_CLIENT_SECRET="accepted-tiny-secret",
        )

        def requester(values):
            if values["OLIST_CLIENT_ID"] == "rejected-olist-id":
                return None, "invalid_client"
            if (
                values["OLIST_CLIENT_ID"] == "accepted-tiny-id"
                and values["OLIST_REFRESH_TOKEN"] == "current-refresh"
            ):
                return {"access_token": "tiny-access", "refresh_token": "tiny-rotated-refresh"}, "ok"
            return None, "invalid_grant"

        report = recovery.recover(current, self.root, requester=requester)

        self.assertTrue(report["ok"])
        self.assertEqual(report["client_namespace"], "tiny")
        _, values = recovery.parse_env(current)
        self.assertEqual(values["OLIST_CLIENT_ID"], "accepted-tiny-id")
        self.assertEqual(values["OLIST_CLIENT_SECRET"], "accepted-tiny-secret")
        self.assertEqual(values["OLIST_ACCESS_TOKEN"], "tiny-access")
        self.assertEqual(values["OLIST_REFRESH_TOKEN"], "tiny-rotated-refresh")

    def test_recovery_does_not_modify_current_env_when_no_combination_is_accepted(self) -> None:
        current = self.root / ".env"
        original = (
            "OLIST_CLIENT_ID=current-id\n"
            "OLIST_CLIENT_SECRET=current-secret\n"
            "OLIST_ACCESS_TOKEN=current-access\n"
            "OLIST_REFRESH_TOKEN=current-refresh\n"
        )
        current.write_text(original, encoding="utf-8")
        backup = self.root / ".env.backup.100"
        write_env(
            backup,
            OLIST_CLIENT_ID="backup-id",
            OLIST_CLIENT_SECRET="backup-secret",
            OLIST_REFRESH_TOKEN="backup-refresh",
        )

        def requester(values):
            if values["OLIST_CLIENT_ID"] == "current-id":
                return None, "invalid_client"
            return None, "invalid_grant"

        with self.assertRaisesRegex(
            recovery.RecoveryError,
            "no_historical_client_refresh_combination_accepted_by_provider",
        ):
            recovery.recover(current, self.root, requester=requester)

        self.assertEqual(current.read_text(encoding="utf-8"), original)
        self.assertFalse(list(self.root.glob(".env.pre-olist-recovery-*")))

    def test_incomplete_or_placeholder_backups_are_ignored_by_complete_tuple_view(self) -> None:
        write_env(
            self.root / ".env.backup.100",
            OLIST_CLIENT_ID="client",
            OLIST_CLIENT_SECRET="placeholder",
            OLIST_REFRESH_TOKEN="refresh",
        )
        write_env(
            self.root / ".env.backup.200",
            OLIST_CLIENT_ID="client",
            OLIST_CLIENT_SECRET="secret",
        )

        self.assertEqual(recovery.candidate_backups(self.root), [])


if __name__ == "__main__":
    unittest.main()
