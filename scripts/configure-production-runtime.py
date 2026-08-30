#!/usr/bin/env python3
"""Atomically update static production settings without touching managed OAuth."""

from __future__ import annotations

import os
import shutil
import sys
import tempfile
import time
from pathlib import Path

try:
    from env_keyset_guard import assert_monotonic_text
except ModuleNotFoundError:  # pragma: no cover - import path used by test loaders
    from scripts.env_keyset_guard import assert_monotonic_text


# This utility is used by the generic runtime workflow. OAuth credentials are
# deliberately absent: access/refresh tokens may only be written by a
# successful OAuth exchange or the interactive callback flow.
KEYS = (
    "DB_HOST",
    "DB_PORT",
    "DB_NAME",
    "DB_USER",
    "DB_PASS",
    "SHOPVIVALIZ_AGENT_KEY",
    "MERCADOPAGO_ACCESS_TOKEN",
    "MERCADOPAGO_PUBLIC_KEY",
    "MERCADOPAGO_WEBHOOK_SECRET",
)

MANAGED_OAUTH_KEYS = (
    "OLIST_ACCESS_TOKEN",
    "OLIST_REFRESH_TOKEN",
    "OLIST_CLIENT_ID",
    "OLIST_CLIENT_SECRET",
    "TINY_ACCESS_TOKEN",
    "TINY_REFRESH_TOKEN",
    "TINY_CLIENT_ID",
    "TINY_CLIENT_SECRET",
    "OLIST_ACCESS_TOKEN",
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


def validate_no_placeholders(values: dict[str, str]) -> str | None:
    markers = ("changeme", "placeholder", "your_", "replace_me", "example", "dummy", "token_here")
    for key, value in values.items():
        normalized = value.strip().lower()
        if any(marker in normalized for marker in markers):
            return f"placeholder value refused for {key}"
    return None


def read_payload() -> dict[str, str]:
    raw_values = sys.stdin.buffer.read().split(b"\0")
    if raw_values and raw_values[-1] == b"":
        raw_values.pop()
    if len(raw_values) != len(KEYS):
        raise ValueError("payload field count mismatch")

    values: dict[str, str] = {}
    for key, raw_value in zip(KEYS, raw_values):
        value = raw_value.decode("utf-8")
        if "\x00" in value or "\r" in value or "\n" in value:
            raise ValueError(f"invalid control character in {key}")
        if value:
            values[key] = value
    return values


def main() -> int:
    if len(sys.argv) != 2:
        print("usage: configure-production-runtime.py SHARED_ENV", file=sys.stderr)
        return 2

    try:
        values = read_payload()
    except (UnicodeDecodeError, ValueError) as exc:
        print(str(exc), file=sys.stderr)
        return 2

    database_error = validate_database_tuple(values)
    if database_error is not None:
        print(database_error, file=sys.stderr)
        return 2
    placeholder_error = validate_no_placeholders(values)
    if placeholder_error is not None:
        print(placeholder_error, file=sys.stderr)
        return 2

    path = Path(sys.argv[1])
    if not path.is_file():
        print(f"shared env does not exist: {path}", file=sys.stderr)
        return 1

    original = path.stat()
    original_text = path.read_text(encoding="utf-8")
    backup = path.with_name(f"{path.name}.backup.{int(time.time())}")
    shutil.copy2(path, backup)
    os.chmod(backup, PRIVATE_MODE)

    lines = original_text.splitlines()
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

    candidate_text = "\n".join(output).rstrip("\n") + "\n"
    added_keys = assert_monotonic_text(original_text, candidate_text)

    descriptor, temporary_name = tempfile.mkstemp(prefix=".env.", dir=path.parent)
    temporary = Path(temporary_name)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8", newline="\n") as handle:
            handle.write(candidate_text)
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temporary, PRIVATE_MODE)
        if hasattr(os, "chown"):
            os.chown(temporary, original.st_uid, original.st_gid)
        os.replace(temporary, path)
        os.chmod(path, PRIVATE_MODE)
        if os.name != "nt":
            updated = path.stat()
            if updated.st_uid != original.st_uid or updated.st_gid != original.st_gid or (updated.st_mode & 0o777) != PRIVATE_MODE:
                raise RuntimeError("shared env metadata changed unexpectedly")
    finally:
        temporary.unlink(missing_ok=True)

    print("updated_keys=" + ",".join(sorted(values)))
    print("added_key_count=" + str(len(added_keys)))
    print("env_key_deletion_blocked=true")
    print("managed_oauth_mutation=blocked")
    print("backup_created=true")
    print("shared_env_mode=600")
    print("shared_env_owner_preserved=true")
    print("database_user_safe=true")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
