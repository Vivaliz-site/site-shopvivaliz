#!/usr/bin/env python3
"""Merge a verified OAuth seed into the production env without logging values."""

from __future__ import annotations

import os
import sys
import tempfile
from pathlib import Path

try:
    from env_keyset_guard import assert_monotonic_text
except ModuleNotFoundError:  # pragma: no cover - import path used by test loaders
    from scripts.env_keyset_guard import assert_monotonic_text

OAUTH_KEYS = (
    "OLIST_CLIENT_ID",
    "OLIST_CLIENT_SECRET",
    "OLIST_ACCESS_TOKEN",
    "OLIST_REFRESH_TOKEN",
    "TINY_CLIENT_ID",
    "TINY_CLIENT_SECRET",
    "TINY_ACCESS_TOKEN",
    "TINY_REFRESH_TOKEN",
)
PLACEHOLDER_MARKERS = (
    "changeme",
    "placeholder",
    "replace_me",
    "example",
    "dummy",
    "token_here",
    "(do github)",
)


def read_env(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip('"').strip("'")
    return values


def validate_seed(values: dict[str, str]) -> None:
    unknown = sorted(set(values) - set(OAUTH_KEYS))
    if unknown:
        raise ValueError("unknown_seed_keys=" + ",".join(unknown))
    missing = [key for key in OAUTH_KEYS if not values.get(key, "").strip()]
    if missing:
        raise ValueError("missing_oauth_keys=" + ",".join(missing))
    for key in OAUTH_KEYS:
        value = values[key]
        normalized = value.strip().lower()
        if "\r" in value or "\n" in value or "\x00" in value:
            raise ValueError(f"invalid_control_character={key}")
        if any(marker in normalized for marker in PLACEHOLDER_MARKERS):
            raise ValueError(f"placeholder_refused={key}")


def merge_oauth(target: Path, seed: Path) -> tuple[int, int]:
    if not target.is_file():
        raise FileNotFoundError(f"target_env_missing={target}")
    if not seed.is_file():
        raise FileNotFoundError(f"oauth_seed_missing={seed}")

    values = read_env(seed)
    validate_seed(values)

    resolved = target.resolve(strict=True)
    original = resolved.stat()
    original_text = resolved.read_text(encoding="utf-8")
    output: list[str] = []
    seen: set[str] = set()
    for line in original_text.splitlines():
        key = line.split("=", 1)[0].strip() if "=" in line else ""
        if key in values:
            if key not in seen:
                output.append(f"{key}={values[key]}")
                seen.add(key)
            continue
        output.append(line)
    for key in OAUTH_KEYS:
        if key not in seen:
            output.append(f"{key}={values[key]}")

    candidate = "\n".join(output).rstrip("\n") + "\n"
    added = assert_monotonic_text(original_text, candidate)

    descriptor, temporary_name = tempfile.mkstemp(prefix=".env.oauth.", dir=resolved.parent)
    temporary = Path(temporary_name)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8", newline="\n") as handle:
            handle.write(candidate)
            handle.flush()
            os.fsync(handle.fileno())
        mode = original.st_mode & 0o777
        os.chmod(temporary, mode)
        if os.name != "nt":
            os.chown(temporary, original.st_uid, original.st_gid)
        os.replace(temporary, resolved)
        updated = resolved.stat()
        if (updated.st_mode & 0o777) != mode:
            raise RuntimeError("target_env_mode_changed")
        if os.name != "nt" and (updated.st_uid != original.st_uid or updated.st_gid != original.st_gid):
            raise RuntimeError("target_env_owner_changed")
    finally:
        temporary.unlink(missing_ok=True)

    return len(OAUTH_KEYS), len(added)


def main() -> int:
    if len(sys.argv) != 3:
        print("usage: bootstrap-olist-oauth-runtime.py TARGET_ENV VERIFIED_SEED", file=sys.stderr)
        return 2
    try:
        updated_count, added_count = merge_oauth(Path(sys.argv[1]), Path(sys.argv[2]))
    except (OSError, UnicodeError, ValueError, RuntimeError) as exc:
        print(str(exc), file=sys.stderr)
        return 1
    print("oauth_runtime_seed_merged=true")
    print(f"oauth_key_count={updated_count}")
    print(f"oauth_added_key_count={added_count}")
    print("oauth_values_logged=false")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
