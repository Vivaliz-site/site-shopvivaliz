#!/usr/bin/env python3
"""Merge Tiny/Olist keys from a temporary env fragment into local .env."""

from __future__ import annotations

from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / ".tmp_olist_env_sync"
TARGET = ROOT / ".env"
ALLOWED = {
    "URL_REDIRCT_OLIST",
    "URL_TINY_OLIST",
    "OLIST_INTEGRADOR_ID",
    "OLIST_CLIENT_ID",
    "OLIST_CLIENT_SECRET",
    "OLIST_REFRESH_TOKEN",
    "OLIST_ACCESS_TOKEN",
    "TINY_ACCESS_TOKEN",
    "TINY_REFRESH_TOKEN",
    "OLIST_WEBHOOK_TOKEN",
    "OLIST_REDIRECT_URI",
    "TINY_REDIRECT_URI",
}


def parse_env(path: Path) -> dict[str, str]:
    found: dict[str, str] = {}
    for raw in path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = raw.strip("\ufeff").strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        if key in ALLOWED and value.strip():
            found[key] = value.strip()
    return found


def main() -> int:
    if not SOURCE.is_file():
        print("TEMP_SOURCE_MISSING")
        return 1
    updates = parse_env(SOURCE)
    if not updates:
        print("NO_ALLOWED_KEYS_FOUND")
        return 1

    original = TARGET.read_text(encoding="utf-8", errors="ignore") if TARGET.is_file() else ""
    output: list[str] = []
    seen: set[str] = set()
    for line in original.splitlines():
        key = line.split("=", 1)[0].strip() if "=" in line else ""
        if key in updates:
            output.append(key + "=" + updates[key])
            seen.add(key)
        else:
            output.append(line)
    for key in sorted(updates):
        if key not in seen:
            output.append(key + "=" + updates[key])

    TARGET.write_text("\n".join(output) + "\n", encoding="utf-8")
    SOURCE.unlink(missing_ok=True)
    print("MERGED_KEYS=" + ",".join(sorted(updates)))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
