#!/usr/bin/env python3
"""Private, fail-closed, lock-consistent backup of durable agent checkpoints."""
from __future__ import annotations

import argparse
from datetime import datetime, timezone
import fcntl
import hashlib
import json
import os
from pathlib import Path
import stat
import sys
import tarfile
import tempfile
import time

DEFAULT_STATE = Path("/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state")
DEFAULT_BACKUPS = Path("/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state-backups")


class BackupError(RuntimeError):
    pass


def _require_dir(path: Path, *, private: bool = False) -> None:
    if path.is_symlink() or not path.is_dir():
        raise BackupError("required_directory_unavailable_or_symlink")
    if private and stat.S_IMODE(path.stat().st_mode) != 0o700:
        raise BackupError("backup_directory_must_be_mode_0700")


def _safe_members(root: Path) -> tuple[list[Path], int]:
    members: list[Path] = []
    task_count = 0
    for parent, directories, filenames in os.walk(root, topdown=True, followlinks=False):
        base = Path(parent)
        for name in sorted(directories):
            path = base / name
            if path.is_symlink():
                raise BackupError("symlink_in_checkpoint_tree")
        for name in sorted(filenames):
            path = base / name
            if path.is_symlink() or not path.is_file():
                raise BackupError("unsafe_checkpoint_member")
            if name.startswith(".resume-") or ".tmp" in name or name.endswith(".partial") or name.endswith(".lock"):
                continue
            if path.suffix == ".json":
                try:
                    item = json.loads(path.read_text(encoding="utf-8"))
                except (OSError, ValueError) as exc:
                    raise BackupError("invalid_json_checkpoint") from exc
                if not isinstance(item, dict):
                    raise BackupError("invalid_json_checkpoint_type")
                if base == root and not name.startswith("_"):
                    if str(item.get("task_id", "")).strip() != path.stem:
                        raise BackupError("checkpoint_task_id_mismatch")
                    task_count += 1
            members.append(path)
    if not task_count:
        raise BackupError("no_task_checkpoints")
    return members, task_count


def _digest(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            h.update(block)
    return h.hexdigest()


def _verify_archive(path: Path, expected_members: int, expected_tasks: int) -> None:
    count = 0
    tasks = 0
    with tarfile.open(path, "r:gz") as archive:
        for member in archive:
            name = member.name
            if (not member.isfile() or name.startswith("/")
                    or ".." in Path(name).parts or member.issym() or member.islnk()):
                raise BackupError("archive_has_unsafe_member")
            count += 1
            if name.endswith(".json"):
                extracted = archive.extractfile(member)
                if extracted is None:
                    raise BackupError("missing_json_archive_content")
                try:
                    data = json.load(extracted)
                except (ValueError, UnicodeError) as exc:
                    raise BackupError("invalid_json_in_archive") from exc
                if not isinstance(data, dict):
                    raise BackupError("invalid_json_in_archive")
                if "/" not in name and not name.startswith("_"):
                    tasks += 1
    if count != expected_members or tasks != expected_tasks:
        raise BackupError("backup_readback_mismatch")


def backup(state: Path, backups: Path) -> dict:
    _require_dir(state)
    _require_dir(backups, private=True)
    state_lock = state / "_agent-task-state.lock"
    if state_lock.is_symlink() or not state_lock.is_file():
        raise BackupError("state_lock_missing")
    guard_fd = os.open(backups / ".continuity-v5-backup.lock",
                       os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    try:
        fcntl.flock(guard_fd, fcntl.LOCK_EX)
        lock_fd = os.open(state_lock, os.O_RDONLY | os.O_NOFOLLOW)
        try:
            fcntl.flock(lock_fd, fcntl.LOCK_EX)
            members, task_count = _safe_members(state)
            temp_fd, temp_name = tempfile.mkstemp(prefix=".continuity-v5-", suffix=".partial", dir=backups)
            os.close(temp_fd)
            temp = Path(temp_name)
            checksum_temp: Path | None = None
            published: Path | None = None
            try:
                os.chmod(temp, 0o600)
                with tarfile.open(temp, "w:gz", dereference=False) as archive:
                    for path in members:
                        archive.add(path, arcname=str(path.relative_to(state)), recursive=False)
                with temp.open("rb") as handle:
                    os.fsync(handle.fileno())
                _verify_archive(temp, len(members), task_count)
                sha = _digest(temp)
                label = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S")
                target = backups / ("continuity-v5-" + label + "-" + str(time.time_ns()) + ".tar.gz")
                if target.exists():
                    raise BackupError("archive_name_collision")
                checksum_fd, checksum_name = tempfile.mkstemp(prefix=".continuity-v5-sha-", suffix=".partial", dir=backups)
                checksum_temp = Path(checksum_name)
                with os.fdopen(checksum_fd, "w", encoding="ascii") as out:
                    out.write(sha + "  " + target.name + "\n")
                    out.flush()
                    os.fsync(out.fileno())
                os.chmod(checksum_temp, 0o600)
                os.replace(temp, target)
                published = target
                os.replace(checksum_temp, backups / (target.name + ".sha256"))
                directory_fd = os.open(backups, os.O_RDONLY | os.O_DIRECTORY)
                try:
                    os.fsync(directory_fd)
                finally:
                    os.close(directory_fd)
                return {"ok": True, "archive": target.name, "sha256": sha, "task_records": task_count}
            except BaseException:
                if published is not None and not (backups / (published.name + ".sha256")).exists():
                    published.unlink(missing_ok=True)
                raise
            finally:
                temp.unlink(missing_ok=True)
                if checksum_temp is not None:
                    checksum_temp.unlink(missing_ok=True)
        finally:
            os.close(lock_fd)
    finally:
        os.close(guard_fd)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--state-dir", type=Path, default=DEFAULT_STATE)
    parser.add_argument("--backup-dir", type=Path, default=DEFAULT_BACKUPS)
    args = parser.parse_args()
    try:
        print(json.dumps(backup(args.state_dir, args.backup_dir), sort_keys=True))
    except (BackupError, OSError, tarfile.TarError) as exc:
        print(json.dumps({"ok": False, "reason": str(exc)}), file=sys.stderr)
        return 75
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
