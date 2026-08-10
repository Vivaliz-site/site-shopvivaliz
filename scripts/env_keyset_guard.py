#!/usr/bin/env python3
"""Monotonic key-set guard for production .env files.

The guard stores only environment variable NAMES, never values. Once a key name
has been sealed, future states must keep that key. New key names may be added.
Existing values may still rotate through authorized runtime flows.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import tempfile
from datetime import datetime, timezone
from pathlib import Path

KEY_RE = re.compile(r"^[A-Za-z_][A-Za-z0-9_]*$")
LOCK_VERSION = 1


class KeysetReductionError(RuntimeError):
    pass


def keyset_from_text(text: str) -> set[str]:
    keys: set[str] = set()
    for raw in text.splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        if line.startswith("export "):
            line = line[7:].strip()
        key = line.split("=", 1)[0].strip()
        if KEY_RE.fullmatch(key):
            keys.add(key)
    return keys


def assert_monotonic_text(before: str, after: str) -> set[str]:
    before_keys = keyset_from_text(before)
    after_keys = keyset_from_text(after)
    missing = sorted(before_keys - after_keys)
    if missing:
        raise KeysetReductionError(
            "environment key-set reduction blocked: " + ",".join(missing)
        )
    return after_keys - before_keys


def read_env_keys(path: Path) -> set[str]:
    if not path.is_file():
        raise FileNotFoundError(path)
    return keyset_from_text(path.read_text(encoding="utf-8", errors="strict"))


def read_lock(path: Path) -> set[str]:
    if not path.is_file():
        raise FileNotFoundError(path)
    payload = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(payload, dict) or payload.get("version") != LOCK_VERSION:
        raise ValueError("invalid keyset lock format")
    keys = payload.get("keys")
    if not isinstance(keys, list) or not all(isinstance(key, str) and KEY_RE.fullmatch(key) for key in keys):
        raise ValueError("invalid keyset lock keys")
    return set(keys)


def write_lock(path: Path, keys: set[str]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    payload = {
        "version": LOCK_VERSION,
        "keys": sorted(keys),
        "key_count": len(keys),
        "updated_at": datetime.now(timezone.utc).isoformat(),
    }
    fd, temp_name = tempfile.mkstemp(prefix=f".{path.name}.", dir=str(path.parent))
    temp = Path(temp_name)
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as handle:
            json.dump(payload, handle, ensure_ascii=True, indent=2, sort_keys=True)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temp, 0o444)
        os.replace(temp, path)
        os.chmod(path, 0o444)
    finally:
        temp.unlink(missing_ok=True)


def verify(env_path: Path, lock_path: Path) -> tuple[int, int]:
    current = read_env_keys(env_path)
    locked = read_lock(lock_path)
    missing = sorted(locked - current)
    if missing:
        raise KeysetReductionError(
            "sealed environment keys disappeared: " + ",".join(missing)
        )
    return len(locked), len(current)


def seal(env_path: Path, lock_path: Path) -> tuple[int, int, int]:
    current = read_env_keys(env_path)
    if not current:
        raise ValueError("refusing to seal empty environment key-set")

    if lock_path.exists():
        locked = read_lock(lock_path)
        missing = sorted(locked - current)
        if missing:
            raise KeysetReductionError(
                "sealed environment keys disappeared: " + ",".join(missing)
            )
    else:
        locked = set()

    added = current - locked
    if added or not lock_path.exists():
        write_lock(lock_path, current)
    return len(locked), len(current), len(added)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Protect a .env key-set from deletion")
    sub = parser.add_subparsers(dest="command", required=True)
    for command in ("seal", "verify"):
        child = sub.add_parser(command)
        child.add_argument("--env", required=True, dest="env_path")
        child.add_argument("--lock", required=True, dest="lock_path")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    env_path = Path(args.env_path)
    lock_path = Path(args.lock_path)
    try:
        if args.command == "seal":
            previous_count, current_count, added_count = seal(env_path, lock_path)
            print(f"keyset_lock_previous_count={previous_count}")
            print(f"keyset_lock_current_count={current_count}")
            print(f"keyset_lock_added_count={added_count}")
            print("keyset_reduction_blocked=true")
            return 0
        locked_count, current_count = verify(env_path, lock_path)
        print(f"keyset_lock_count={locked_count}")
        print(f"keyset_current_count={current_count}")
        print("keyset_reduction_detected=false")
        return 0
    except (OSError, ValueError, json.JSONDecodeError, KeysetReductionError) as exc:
        print(f"env_keyset_guard_error={type(exc).__name__}")
        print(str(exc))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
