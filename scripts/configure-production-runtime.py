#!/usr/bin/env python3
"""Atomically update the production shared environment from a NUL payload."""

from __future__ import annotations

import os
import shutil
import sys
import tempfile
import time
from pathlib import Path


KEYS = (
    "DB_HOST",
    "DB_PORT",
    "DB_NAME",
    "DB_USER",
    "DB_PASS",
    "OLIST_ACCESS_TOKEN",
    "OLIST_REFRESH_TOKEN",
    "OLIST_CLIENT_ID",
    "OLIST_CLIENT_SECRET",
    "TINY_ACCESS_TOKEN",
    "TINY_REFRESH_TOKEN",
    "TINY_CLIENT_ID",
    "TINY_CLIENT_SECRET",
    "SHOPVIVALIZ_AGENT_KEY",
)

PRIVATE_MODE = 0o600


def validate_database_tuple(values: dict[str, str]) -> str | None:
    required = ("DB_HOST", "DB_PORT", "DB_NAME", "DB_USER", "DB_PASS")
    missing = [key for key in required if not values.get(key, "").strip()]
    if missing:
        return "database tuple is incomplete: " + ",".join(missing)
    if values["DB_USER"].strip().lower() == "root":
        return "root database user is forbidden in production runtime"
    if not values["DB_PORT"].strip().isdigit():
        return "database port must be numeric"
    return None


def main() -> int:
    if len(sys.argv) != 2:
        print("usage: configure-production-runtime.py SHARED_ENV", file=sys.stderr)
        return 2

    raw_values = sys.stdin.buffer.read().split(b"\0")
    if raw_values and raw_values[-1] == b"":
        raw_values.pop()
    if len(raw_values) != len(KEYS):
        print("payload field count mismatch", file=sys.stderr)
        return 2

    values: dict[str, str] = {}
    for key, raw_value in zip(KEYS, raw_values):
        value = raw_value.decode("utf-8")
        if "\x00" in value or "\r" in value or "\n" in value:
            print(f"invalid control character in {key}", file=sys.stderr)
            return 2
        if value:
            values[key] = value

    database_error = validate_database_tuple(values)
    if database_error is not None:
        print(database_error, file=sys.stderr)
        return 2

    path = Path(sys.argv[1])
    if not path.is_file():
        print(f"shared env does not exist: {path}", file=sys.stderr)
        return 1

    backup = path.with_name(f"{path.name}.backup.{int(time.time())}")
    shutil.copy2(path, backup)
    os.chmod(backup, PRIVATE_MODE)

    lines = path.read_text(encoding="utf-8").splitlines()
    seen: set[str] = set()
    output: list[str] = []
    for line in lines:
        key = line.split("=", 1)[0].strip() if "=" in line else ""
        if key in values:
            output.append(f"{key}={values[key]}")
            seen.add(key)
        else:
            output.append(line)
    for key, value in values.items():
        if key not in seen:
            output.append(f"{key}={value}")

    descriptor, temporary_name = tempfile.mkstemp(prefix=".env.", dir=path.parent)
    temporary = Path(temporary_name)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8", newline="\n") as handle:
            handle.write("\n".join(output).rstrip("\n") + "\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temporary, PRIVATE_MODE)
        os.replace(temporary, path)
        os.chmod(path, PRIVATE_MODE)
    finally:
        temporary.unlink(missing_ok=True)

    print("updated_keys=" + ",".join(sorted(values)))
    print("backup_created=true")
    print("shared_env_mode=600")
    print("database_user_safe=true")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
