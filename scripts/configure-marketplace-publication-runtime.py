#!/usr/bin/env python3
"""Securely merge marketplace publication credentials into production .env.

The command receives a fixed NUL-delimited payload on stdin. Empty fields are
ignored and therefore never erase an existing credential. It refuses obvious
placeholders, writes atomically, preserves uid/gid/mode, and never prints values.
"""
from __future__ import annotations

import os
import shutil
import sys
import tempfile
import time
from pathlib import Path

KEYS = (
    "ML_CLIENT_ID", "ML_CLIENT_SECRET", "ML_ACCESS_TOKEN", "ML_REFRESH_TOKEN", "ML_SELLER_ID",
    "SHOPEE_PARTNER_ID", "SHOPEE_PARTNER_KEY", "SHOPEE_SHOP_ID", "SHOPEE_ACCESS_TOKEN", "SHOPEE_REFRESH_TOKEN",
    "TIKTOK_APP_KEY", "TIKTOK_APP_SECRET", "TIKTOK_ACCESS_TOKEN", "TIKTOK_REFRESH_TOKEN", "TIKTOK_SHOP_CIPHER", "TIKTOK_SHOP_ID",
    "AMAZON_LWA_CLIENT_ID", "AMAZON_LWA_CLIENT_SECRET", "AMAZON_LWA_REFRESH_TOKEN",
    "AMAZON_SELLER_ID", "AMAZON_ACCOUNT_ID", "AMAZON_MARKETPLACE_ID", "AMAZON_SP_API_ENDPOINT", "AMAZON_SP_API_REGION",
    "OLIST_API_KEY", "OLIST_ACCESS_TOKEN",
)

PLACEHOLDER_MARKERS = (
    "changeme", "placeholder", "your_", "replace_me", "example", "dummy",
    "token_here", "client_id_here", "client_secret_here", "undefined", "null",
)


def validate_value(key: str, value: str) -> None:
    if any(character in value for character in ("\x00", "\r", "\n")):
        raise ValueError(f"invalid control character in {key}")
    normalized = value.strip().lower()
    if normalized and any(marker in normalized for marker in PLACEHOLDER_MARKERS):
        raise ValueError(f"placeholder value refused for {key}")
    if normalized and key.endswith(("TOKEN", "SECRET", "KEY")) and len(value.strip()) < 8:
        raise ValueError(f"suspiciously short secret refused for {key}")


def group_status(values: dict[str, str]) -> dict[str, bool]:
    return {
        "ml": all(values.get(key) for key in ("ML_CLIENT_ID", "ML_CLIENT_SECRET"))
        and bool(values.get("ML_ACCESS_TOKEN") or values.get("ML_REFRESH_TOKEN")),
        "shopee": all(values.get(key) for key in ("SHOPEE_PARTNER_ID", "SHOPEE_PARTNER_KEY", "SHOPEE_SHOP_ID"))
        and bool(values.get("SHOPEE_ACCESS_TOKEN") or values.get("SHOPEE_REFRESH_TOKEN")),
        "tiktok": all(values.get(key) for key in ("TIKTOK_APP_KEY", "TIKTOK_APP_SECRET"))
        and bool(values.get("TIKTOK_SHOP_CIPHER") or values.get("TIKTOK_SHOP_ID"))
        and bool(values.get("TIKTOK_ACCESS_TOKEN") or values.get("TIKTOK_REFRESH_TOKEN")),
        "amazon": all(values.get(key) for key in (
            "AMAZON_LWA_CLIENT_ID", "AMAZON_LWA_CLIENT_SECRET", "AMAZON_LWA_REFRESH_TOKEN", "AMAZON_MARKETPLACE_ID"
        )) and bool(values.get("AMAZON_SELLER_ID") or values.get("AMAZON_ACCOUNT_ID")),
        "tiny_images": bool(values.get("OLIST_API_KEY") or values.get("OLIST_ACCESS_TOKEN")),
    }


def main() -> int:
    if len(sys.argv) != 2:
        print("usage: configure-marketplace-publication-runtime.py SHARED_ENV", file=sys.stderr)
        return 2

    raw_values = sys.stdin.buffer.read().split(b"\0")
    if raw_values and raw_values[-1] == b"":
        raw_values.pop()
    if len(raw_values) != len(KEYS):
        print(f"payload field count mismatch: received={len(raw_values)} expected={len(KEYS)}", file=sys.stderr)
        return 2

    values: dict[str, str] = {}
    try:
        for key, raw in zip(KEYS, raw_values):
            value = raw.decode("utf-8").strip()
            validate_value(key, value)
            if value:
                values[key] = value
    except (UnicodeDecodeError, ValueError) as exc:
        print(str(exc), file=sys.stderr)
        return 2

    if not values:
        print("no non-empty marketplace credentials supplied", file=sys.stderr)
        return 2

    path = Path(sys.argv[1])
    if not path.is_file() or not os.access(path, os.R_OK | os.W_OK):
        print(f"shared env is not readable and writable: {path}", file=sys.stderr)
        return 1

    original = path.stat()
    original_mode = original.st_mode & 0o777
    backup = path.with_name(f"{path.name}.marketplace-backup.{int(time.time())}")
    shutil.copy2(path, backup)
    os.chmod(backup, original_mode)

    lines = path.read_text(encoding="utf-8").splitlines()
    output: list[str] = []
    seen: set[str] = set()
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

    descriptor, temporary_name = tempfile.mkstemp(prefix=".marketplace-env.", dir=path.parent)
    temporary = Path(temporary_name)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8", newline="\n") as handle:
            handle.write("\n".join(output).rstrip("\n") + "\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temporary, original_mode)
        if hasattr(os, "chown"):
            os.chown(temporary, original.st_uid, original.st_gid)
        os.replace(temporary, path)
        final = path.stat()
        if final.st_uid != original.st_uid or final.st_gid != original.st_gid or (final.st_mode & 0o777) != original_mode:
            raise RuntimeError("shared env metadata changed unexpectedly")
    finally:
        temporary.unlink(missing_ok=True)

    groups = group_status(values)
    print("updated_keys=" + ",".join(sorted(values)))
    print("payload_groups=" + ",".join(key for key, ready in groups.items() if ready))
    print("backup_created=true")
    print(f"shared_env_mode={original_mode:o}")
    print("shared_env_owner_preserved=true")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
