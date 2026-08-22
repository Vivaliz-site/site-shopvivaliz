#!/usr/bin/env python3
"""Restore only static ERP OAuth app credentials in the shared production env.

Access and refresh tokens are deliberately protected from this utility. The
purpose is to repair missing Olist/Tiny client credentials without rolling back
rotating tokens that may have been refreshed directly in production.
"""
from __future__ import annotations

import os
import shutil
import sys
import tempfile
import time
from pathlib import Path

KEYS = (
    "OLIST_CLIENT_ID",
    "OLIST_CLIENT_SECRET",
    "TINY_CLIENT_ID",
    "TINY_CLIENT_SECRET",
)
PROTECTED_TOKEN_KEYS = (
    "OLIST_ACCESS_TOKEN",
    "OLIST_REFRESH_TOKEN",
    "TINY_ACCESS_TOKEN",
    "TINY_REFRESH_TOKEN",
    "OLIST_ACCESS_TOKEN",
)
PLACEHOLDER_MARKERS = (
    "changeme",
    "placeholder",
    "your_",
    "replace_me",
    "example",
    "dummy",
    "token_here",
    "client_id_here",
    "client_secret_here",
    "undefined",
    "null",
)


def parse_env(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    for raw in path.read_text(encoding="utf-8-sig").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        if line.startswith("export "):
            line = line[7:].strip()
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in {"'", '"'}:
            value = value[1:-1]
        values[key] = value
    return values


def validate_value(key: str, value: str) -> None:
    if any(character in value for character in ("\x00", "\r", "\n")):
        raise ValueError(f"invalid control character in {key}")
    normalized = value.strip().lower()
    if normalized and any(marker in normalized for marker in PLACEHOLDER_MARKERS):
        raise ValueError(f"placeholder value refused for {key}")


def read_payload() -> dict[str, str]:
    raw_values = sys.stdin.buffer.read().split(b"\0")
    if raw_values and raw_values[-1] == b"":
        raw_values.pop()
    if len(raw_values) != len(KEYS):
        raise ValueError(
            f"payload field count mismatch: received={len(raw_values)} expected={len(KEYS)}"
        )

    values: dict[str, str] = {}
    for key, raw in zip(KEYS, raw_values):
        value = raw.decode("utf-8").strip()
        validate_value(key, value)
        if value:
            values[key] = value
    return values


def validate_groups(values: dict[str, str]) -> None:
    if not values.get("OLIST_CLIENT_ID") or not values.get("OLIST_CLIENT_SECRET"):
        raise ValueError("complete Olist client credential pair is required")
    tiny_count = sum(bool(values.get(key)) for key in ("TINY_CLIENT_ID", "TINY_CLIENT_SECRET"))
    if tiny_count not in (0, 2):
        raise ValueError("Tiny client credentials must be supplied as a complete pair")


def main() -> int:
    if len(sys.argv) != 2:
        print("usage: configure-erp-oauth-static-runtime.py SHARED_ENV", file=sys.stderr)
        return 2

    try:
        values = read_payload()
        validate_groups(values)
    except (UnicodeDecodeError, ValueError) as exc:
        print(str(exc), file=sys.stderr)
        return 2

    path = Path(sys.argv[1])
    if not path.is_file() or not os.access(path, os.R_OK | os.W_OK):
        print(f"shared env is not readable and writable: {path}", file=sys.stderr)
        return 1

    original = path.stat()
    original_mode = original.st_mode & 0o777
    before = parse_env(path)
    protected_before = {key: before.get(key, "") for key in PROTECTED_TOKEN_KEYS}

    backup = path.with_name(f"{path.name}.erp-oauth-backup.{time.time_ns()}")
    shutil.copy2(path, backup)
    os.chmod(backup, original_mode)

    lines = path.read_text(encoding="utf-8").splitlines()
    deprecated_static_token_key = "TOKEN_" + "API_OLIST"
    output: list[str] = []
    seen: set[str] = set()
    for line in lines:
        candidate = line.strip()
        if candidate.startswith("export "):
            candidate = candidate[7:].strip()
        key = candidate.split("=", 1)[0].strip() if "=" in candidate else ""
        if key == deprecated_static_token_key:
            continue
        if key in values:
            output.append(f"{key}={values[key]}")
            seen.add(key)
        else:
            output.append(line)
    for key, value in values.items():
        if key not in seen:
            output.append(f"{key}={value}")

    descriptor, temporary_name = tempfile.mkstemp(prefix=".erp-oauth-env.", dir=path.parent)
    temporary = Path(temporary_name)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8", newline="\n") as handle:
            handle.write("\n".join(output).rstrip("\n") + "\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temporary, original_mode)
        if os.name != "nt":
            os.chown(temporary, original.st_uid, original.st_gid)
        os.replace(temporary, path)
        final = path.stat()
        if os.name != "nt" and (final.st_uid != original.st_uid or final.st_gid != original.st_gid):
            raise RuntimeError("shared env owner/group changed unexpectedly")
        if (final.st_mode & 0o777) != original_mode:
            raise RuntimeError("shared env mode changed unexpectedly")
    finally:
        temporary.unlink(missing_ok=True)

    after = parse_env(path)
    for key, expected in values.items():
        if after.get(key) != expected:
            raise RuntimeError(f"static credential write verification failed for {key}")
    protected_after = {key: after.get(key, "") for key in PROTECTED_TOKEN_KEYS}
    if protected_after != protected_before:
        raise RuntimeError("rotating ERP token changed during static credential repair")

    print("updated_keys=" + ",".join(sorted(values)))
    print("protected_rotating_tokens_preserved=true")
    print("backup_created=true")
    print(f"shared_env_mode={original_mode:o}")
    print("shared_env_owner_preserved=true")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
