#!/usr/bin/env python3
"""Reclaim only old Docker-created anonymous volumes with no container references.

This is intentionally NOT docker volume prune. Named volumes, recent volumes,
unknown database clusters, large volumes and volumes with references are kept.
The default mode is read-only. Production --apply is called by the disk guard.
"""
from __future__ import annotations

import argparse
import datetime as dt
import fcntl
import json
import os
from pathlib import Path
import re
import subprocess
import sys

ANONYMOUS_LABEL = "com.docker.volume.anonymous"
ANON_NAME = re.compile(r"^[a-f0-9]{64}$")
MAX_NON_DATABASE_BYTES = 300_000_000
MAX_TEST_PG16_BYTES = 70_000_000
MIN_TEST_PG16_AGE_HOURS = 72
LOCK = "/run/lock/shopvivaliz-anonymous-volume-reclaim.lock"
LOG = "/var/log/shopvivaliz-anonymous-volume-reclaim.log"


def docker(*arguments: str, timeout: int = 20) -> str:
    result = subprocess.run(
        ["docker", *arguments],
        capture_output=True,
        text=True,
        timeout=timeout,
        check=False,
    )
    if result.returncode != 0:
        raise RuntimeError("Docker operation failed: " + arguments[0])
    return result.stdout.strip()


def creation_age_hours(metadata: dict, now: dt.datetime) -> float:
    raw = str(metadata.get("CreatedAt") or "")
    created = dt.datetime.fromisoformat(raw.replace("Z", "+00:00"))
    if created.tzinfo is None:
        raise ValueError("volume created_at missing timezone")
    return (now - created).total_seconds() / 3600


def metadata_reason(
    metadata: dict,
    referenced: set[str],
    now: dt.datetime,
    root: Path,
    min_age_hours: int,
) -> str:
    name = str(metadata.get("Name") or "")
    if not ANON_NAME.fullmatch(name):
        return "not_engine_anonymous"
    if ANONYMOUS_LABEL not in (metadata.get("Labels") or {}):
        return "not_engine_anonymous"
    if metadata.get("Driver") != "local":
        return "nonlocal_driver"
    if name in referenced:
        return "referenced"
    try:
        age = creation_age_hours(metadata, now)
    except (ValueError, TypeError, OverflowError):
        return "invalid_age"
    if age < min_age_hours:
        return "recent"
    target = root / name / "_data"
    mounted = str(metadata.get("Mountpoint") or "")
    if mounted != str(target) or not target.is_dir() or target.is_symlink():
        return "unexpected_mountpoint"
    if os.path.ismount(target):
        return "host_mounted"
    return "eligible"


def content_reason(size: int, pg_version: str | None, age_hours: float) -> str:
    if size < 0:
        return "size_unknown"
    if pg_version is None:
        return "eligible" if size <= MAX_NON_DATABASE_BYTES else "oversized"
    if pg_version != "16":
        return "database_preserved"
    if age_hours < MIN_TEST_PG16_AGE_HOURS:
        return "test_postgres_quarantine"
    return "eligible" if size <= MAX_TEST_PG16_BYTES else "database_oversized"


def referenced_volume_names() -> set[str]:
    ids = docker("ps", "-aq").splitlines()
    if not ids:
        return set()
    containers = json.loads(docker("inspect", *ids, timeout=35))
    return {
        str(mount.get("Name"))
        for container in containers
        for mount in container.get("Mounts", [])
        if mount.get("Type") == "volume" and mount.get("Name")
    }


def disk_bytes(path: Path) -> int:
    result = subprocess.run(
        ["du", "-sx", "-B1", str(path)],
        capture_output=True,
        text=True,
        timeout=12,
        check=False,
    )
    if result.returncode != 0:
        raise RuntimeError("disk usage unavailable")
    return int(result.stdout.split()[0])


def reclaim(apply: bool, min_age_hours: int, max_removals: int) -> int:
    if apply and os.geteuid() != 0:
        raise RuntimeError("apply requires root")
    with open(LOCK, "a", encoding="utf-8") as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            print("ANONYMOUS_VOLUME_RECLAIM=ALREADY_RUNNING")
            return 0
        docker_root = docker("info", "--format", "{{.DockerRootDir}}")
        if docker_root != "/var/lib/docker":
            raise RuntimeError("unexpected Docker data root")
        root = Path(docker_root) / "volumes"
        now = dt.datetime.now(dt.timezone.utc)
        listed = docker("volume", "ls", "-q", "--filter", "label=" + ANONYMOUS_LABEL)
        removed = 0
        estimated = 0
        eligible = 0
        retained: dict[str, int] = {}
        for name in listed.splitlines():
            if removed >= max_removals:
                retained["max_removals"] = retained.get("max_removals", 0) + 1
                break
            if not ANON_NAME.fullmatch(name):
                retained["nonrandom"] = retained.get("nonrandom", 0) + 1
                continue
            info = json.loads(docker("volume", "inspect", name))[0]
            # Reinspect ALL containers immediately before each candidate.
            # The daemon also refuses a removal if a container races to use it.
            refs = referenced_volume_names()
            reason = metadata_reason(info, refs, now, root, min_age_hours)
            if reason != "eligible":
                retained[reason] = retained.get(reason, 0) + 1
                continue
            path = root / name / "_data"
            try:
                size = disk_bytes(path)
                version_file = path / "PG_VERSION"
                pg_version = version_file.read_text().strip() if version_file.is_file() else None
                reason = content_reason(size, pg_version, creation_age_hours(info, now))
            except (OSError, ValueError, RuntimeError, subprocess.TimeoutExpired):
                reason = "unverified_data"
            if reason != "eligible":
                retained[reason] = retained.get(reason, 0) + 1
                continue
            eligible += 1
            if not apply:
                continue
            # Do not use -f, and never invoke broad docker volume prune.
            result = subprocess.run(
                ["docker", "volume", "rm", name],
                capture_output=True,
                text=True,
                timeout=25,
                check=False,
            )
            if result.returncode != 0:
                retained["daemon_refused"] = retained.get("daemon_refused", 0) + 1
                continue
            removed += 1
            estimated += size
            with open(LOG, "a", encoding="utf-8") as log:
                log.write(json.dumps({
                    "utc": now.isoformat(),
                    "name_prefix": name[:12],
                    "bytes_estimate": size,
                    "age_hours": round(creation_age_hours(info, now), 1),
                    "pg_version": pg_version,
                    "reason": "old_anonymous_volume_without_container_refs",
                }, sort_keys=True) + "\n")
        print(
            "ANONYMOUS_VOLUME_RECLAIM=" + ("APPLIED" if apply else "DRY_RUN")
            + " eligible=" + str(eligible)
            + " removed=" + str(removed)
            + " estimated_bytes=" + str(estimated)
            + " preserved=" + json.dumps(retained, sort_keys=True)
        )
        return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true", help="Perform guarded removals")
    parser.add_argument("--min-age-hours", type=int, default=24)
    parser.add_argument("--max-removals", type=int, default=20)
    args = parser.parse_args()
    if not 1 <= args.max_removals <= 30:
        parser.error("max-removals must be between 1 and 30")
    try:
        return reclaim(args.apply, max(24, args.min_age_hours), args.max_removals)
    except (OSError, ValueError, RuntimeError, subprocess.TimeoutExpired) as exc:
        # No credentials, names or Docker error output in the operator log.
        print("ANONYMOUS_VOLUME_RECLAIM=ERROR class=" + type(exc).__name__, file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
