from __future__ import annotations

import importlib.util
from pathlib import Path


MODULE_PATH = Path(__file__).resolve().parents[1] / "scripts" / "recover-olist-oauth-from-backups.py"
SPEC = importlib.util.spec_from_file_location("recover_olist_oauth", MODULE_PATH)
recovery = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(recovery)


def write_env(path: Path, **values: str) -> None:
    path.write_text("".join(f"{key}={value}\n" for key, value in values.items()), encoding="utf-8")


def test_recovery_tries_newest_first_and_only_writes_provider_accepted_tuple(tmp_path: Path) -> None:
    current = tmp_path / ".env"
    write_env(
        current,
        UNCHANGED="keep-me",
        OLIST_CLIENT_ID="bad-current-id",
        OLIST_CLIENT_SECRET="bad-current-secret",
        OLIST_ACCESS_TOKEN="expired-access",
        OLIST_REFRESH_TOKEN="expired-refresh",
    )

    older = tmp_path / ".env.backup.100"
    newer = tmp_path / ".env.backup.200"
    write_env(
        older,
        OLIST_CLIENT_ID="good-id",
        OLIST_CLIENT_SECRET="good-secret",
        OLIST_REFRESH_TOKEN="good-refresh",
    )
    write_env(
        newer,
        OLIST_CLIENT_ID="bad-backup-id",
        OLIST_CLIENT_SECRET="bad-backup-secret",
        OLIST_REFRESH_TOKEN="bad-backup-refresh",
    )
    older.touch()
    newer.touch()
    # Explicit mtimes make ordering deterministic.
    import os
    os.utime(older, (100, 100))
    os.utime(newer, (200, 200))

    seen = []

    def requester(values):
        seen.append(values["OLIST_CLIENT_ID"])
        if values["OLIST_CLIENT_ID"] == "good-id":
            return {"access_token": "fresh-access", "refresh_token": "fresh-refresh"}, "ok"
        return None, "invalid_client"

    report = recovery.recover(current, tmp_path, requester=requester)

    assert report["ok"] is True
    assert seen == ["bad-backup-id", "good-id"]
    _, values = recovery.parse_env(current)
    assert values["UNCHANGED"] == "keep-me"
    assert values["OLIST_CLIENT_ID"] == "good-id"
    assert values["OLIST_CLIENT_SECRET"] == "good-secret"
    assert values["OLIST_ACCESS_TOKEN"] == "fresh-access"
    assert values["OLIST_REFRESH_TOKEN"] == "fresh-refresh"
    assert list(tmp_path.glob(".env.pre-olist-recovery-*"))


def test_recovery_does_not_modify_current_env_when_no_backup_is_accepted(tmp_path: Path) -> None:
    current = tmp_path / ".env"
    original = (
        "OLIST_CLIENT_ID=current-id\n"
        "OLIST_CLIENT_SECRET=current-secret\n"
        "OLIST_ACCESS_TOKEN=current-access\n"
        "OLIST_REFRESH_TOKEN=current-refresh\n"
    )
    current.write_text(original, encoding="utf-8")
    backup = tmp_path / ".env.backup.100"
    write_env(
        backup,
        OLIST_CLIENT_ID="backup-id",
        OLIST_CLIENT_SECRET="backup-secret",
        OLIST_REFRESH_TOKEN="backup-refresh",
    )

    def requester(values):
        return None, "invalid_client"

    try:
        recovery.recover(current, tmp_path, requester=requester)
    except recovery.RecoveryError as exc:
        assert str(exc) == "no_backup_tuple_accepted_by_provider"
    else:
        raise AssertionError("RecoveryError expected")

    assert current.read_text(encoding="utf-8") == original
    assert not list(tmp_path.glob(".env.pre-olist-recovery-*"))


def test_incomplete_or_placeholder_backups_are_ignored(tmp_path: Path) -> None:
    write_env(
        tmp_path / ".env.backup.100",
        OLIST_CLIENT_ID="client",
        OLIST_CLIENT_SECRET="placeholder",
        OLIST_REFRESH_TOKEN="refresh",
    )
    write_env(
        tmp_path / ".env.backup.200",
        OLIST_CLIENT_ID="client",
        OLIST_CLIENT_SECRET="secret",
    )

    assert recovery.candidate_backups(tmp_path) == []
