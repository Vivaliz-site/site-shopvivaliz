#!/usr/bin/env python3
"""Atomically merge a strict set of static production settings from JSON stdin."""

from __future__ import annotations

import json
import os
import stat
import sys
import tempfile
from pathlib import Path


ALLOWED_KEYS = {
    "MELHORENVIO_ACCESS_TOKEN",
    "MELHORENVIO_CLIENTE_ID",
    "MELHORENVIO_CLIENTE_SECRET",
    "MELHORENVIO_FROM_POSTAL_CODE",
    "MERCADOPAGO_ACCESS_TOKEN",
    "MERCADOPAGO_PUBLIC_KEY",
    "MERCADOPAGO_WEBHOOK_SECRET",
    "ML_CLIENT_ID",
    "ML_CLIENT_SECRET",
    "ML_REDIRECT_URI",
    "SHOPVIVALIZ_BASE_URL",
    "APP_URL",
    "SITE_URL",
    "BASE_URL",
    "MELHORENVIO_REDIRECT_URI",
    "OLIST_CLIENT_ID",
    "OLIST_CLIENT_SECRET",
    "OLIST_REDIRECT_URI",
    "URL_REDIRCT_OLIST",
    "URL_TINY_OLIST",
    "TINY_REDIRECT_URI",
    "SHOPEE_REDIRECT_URI",
    "TIKTOK_REDIRECT_URL",
}

def normalize_value(key: str, value: object) -> str:
    return str(value).replace("https://www.shopvivaliz.com.br", "https://shopvivaliz.com.br")


def merge_env(path: Path, incoming: dict[str, object]) -> list[str]:
    invalid = sorted(set(incoming) - ALLOWED_KEYS)
    if invalid:
        raise ValueError("unsupported environment keys: " + ", ".join(invalid))

    updates = {
        key: normalize_value(key, value)
        for key, value in incoming.items()
        if key in ALLOWED_KEYS and value is not None and str(value) != ""
    }
    if not updates:
        return []

    original = path.read_text(encoding="utf-8") if path.exists() else ""
    lines = original.splitlines()
    seen: set[str] = set()
    output: list[str] = []
    for line in lines:
        key = line.split("=", 1)[0] if "=" in line else ""
        if key in updates:
            output.append(f"{key}={updates[key]}")
            seen.add(key)
        else:
            output.append(line)
    for key in sorted(updates):
        if key not in seen:
            output.append(f"{key}={updates[key]}")

    mode = stat.S_IMODE(path.stat().st_mode) if path.exists() else 0o640
    fd, temp_name = tempfile.mkstemp(prefix=".env.", dir=path.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as handle:
            handle.write("\n".join(output) + "\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temp_name, mode)
        os.replace(temp_name, path)
    finally:
        if os.path.exists(temp_name):
            os.unlink(temp_name)
    return sorted(updates)


def main() -> int:
    try:
        payload = json.load(sys.stdin)
        if not isinstance(payload, dict):
            raise ValueError("JSON payload must be an object")
        changed = merge_env(Path(".env"), payload)
    except (OSError, ValueError, json.JSONDecodeError) as exc:
        print(f"environment update failed: {exc}", file=sys.stderr)
        return 1
    print(f"environment updated atomically: {len(changed)} static keys")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
