#!/usr/bin/env python3
"""Atomically update the production shared environment from a NUL payload.

Supports the established 14-field production workflow and an extended payload
containing all marketplace credentials. Empty optional fields are ignored, so
an execution cannot erase existing credentials. Values are never printed.
"""
from __future__ import annotations

import os
import shutil
import sys
import tempfile
import time
from pathlib import Path

LEGACY_KEYS = (
    "DB_HOST", "DB_PORT", "DB_NAME", "DB_USER", "DB_PASS",
    "OLIST_ACCESS_TOKEN", "OLIST_REFRESH_TOKEN", "OLIST_CLIENT_ID", "OLIST_CLIENT_SECRET",
    "TINY_ACCESS_TOKEN", "TINY_REFRESH_TOKEN", "TINY_CLIENT_ID", "TINY_CLIENT_SECRET",
    "SHOPVIVALIZ_AGENT_KEY",
)

EXTENDED_KEYS = (
    "DB_HOST", "DB_PORT", "DB_NAME", "DB_USER", "DB_PASS",
    "OLIST_ACCESS_TOKEN", "OLIST_REFRESH_TOKEN", "OLIST_CLIENT_ID", "OLIST_CLIENT_SECRET", "OLIST_API_KEY",
    "TINY_ACCESS_TOKEN", "TINY_REFRESH_TOKEN", "TINY_CLIENT_ID", "TINY_CLIENT_SECRET", "TOKEN_API_OLIST",
    "ML_CLIENT_ID", "ML_CLIENT_SECRET", "ML_ACCESS_TOKEN", "ML_REFRESH_TOKEN", "ML_SELLER_ID",
    "SHOPEE_PARTNER_ID", "SHOPEE_PARTNER_KEY", "SHOPEE_SHOP_ID", "SHOPEE_ACCESS_TOKEN", "SHOPEE_REFRESH_TOKEN",
    "TIKTOK_APP_KEY", "TIKTOK_APP_SECRET", "TIKTOK_ACCESS_TOKEN", "TIKTOK_REFRESH_TOKEN", "TIKTOK_SHOP_CIPHER", "TIKTOK_SHOP_ID",
    "AMAZON_LWA_CLIENT_ID", "AMAZON_LWA_CLIENT_SECRET", "AMAZON_LWA_REFRESH_TOKEN",
    "AMAZON_SELLER_ID", "AMAZON_ACCOUNT_ID", "AMAZON_MARKETPLACE_ID", "AMAZON_SP_API_ENDPOINT", "AMAZON_SP_API_REGION",
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


def validate_no_placeholders(values: dict[str, str]) -> str | None:
    markers = ("changeme", "placeholder", "your_", "replace_me", "example", "dummy", "token_here")
    for key, value in values.items():
        normalized = value.strip().lower()
        if any(marker in normalized for marker in markers):
            return f"placeholder value refused for {key}"
    return None


def main() -> int:
    if len(sys.argv) != 2:
        print("usage: configure-production-runtime.py SHARED_ENV", file=sys.stderr)
        return 2

    raw_values = sys.stdin.buffer.read().split(b"\0")
    if raw_values and raw_values[-1] == b"":
        raw_values.pop()
    if len(raw_values) == len(LEGACY_KEYS):
        keys = LEGACY_KEYS
        payload_mode = "legacy"
    elif len(raw_values) == len(EXTENDED_KEYS):
        keys = EXTENDED_KEYS
        payload_mode = "extended"
    else:
        print(
            f"payload field count mismatch: received={len(raw_values)} expected={len(LEGACY_KEYS)} or {len(EXTENDED_KEYS)}",
            file=sys.stderr,
        )
        return 2

    values: dict[str, str] = {}
    for key, raw_value in zip(keys, raw_values):
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
    placeholder_error = validate_no_placeholders(values)
    if placeholder_error is not None:
        print(placeholder_error, file=sys.stderr)
        return 2

    path = Path(sys.argv[1])
    if not path.is_file():
        print(f"shared env does not exist: {path}", file=sys.stderr)
        return 1

    original = path.stat()
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
        if hasattr(os, "chown"):
            os.chown(temporary, original.st_uid, original.st_gid)
        os.replace(temporary, path)
        os.chmod(path, PRIVATE_MODE)
        updated = path.stat()
        if updated.st_uid != original.st_uid or updated.st_gid != original.st_gid or (updated.st_mode & 0o777) != PRIVATE_MODE:
            raise RuntimeError("shared env metadata changed unexpectedly")
    finally:
        temporary.unlink(missing_ok=True)

    print("payload_mode=" + payload_mode)
    print("updated_keys=" + ",".join(sorted(values)))
    print("backup_created=true")
    print("shared_env_mode=600")
    print("shared_env_owner_preserved=true")
    print("database_user_safe=true")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
