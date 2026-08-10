#!/usr/bin/env python3
"""Recover a working Olist OAuth client/token tuple from private .env backups.

The script never prints credential values. It tests complete OLIST_* tuples from
private production backups against the official token endpoint and only writes a
candidate after the provider successfully issues a new access token.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import shutil
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Any, Callable

TOKEN_URL = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token"
BACKUP_PATTERNS = (".env.backup.*", ".env.bak-*", ".env.pre-*", ".env.restore.*")
REQUIRED_KEYS = ("OLIST_CLIENT_ID", "OLIST_CLIENT_SECRET", "OLIST_REFRESH_TOKEN")
SAFE_OAUTH_ERRORS = frozenset(
    {
        "invalid_request",
        "invalid_client",
        "invalid_grant",
        "unauthorized_client",
        "unsupported_grant_type",
        "invalid_scope",
        "temporarily_unavailable",
        "server_error",
        "access_denied",
    }
)
PLACEHOLDERS = frozenset({"changeme", "change-me", "placeholder", "***", "replace_me"})
ASSIGNMENT = re.compile(r"^[A-Za-z_][A-Za-z0-9_]*$")


class RecoveryError(RuntimeError):
    pass


def parse_env(path: Path) -> tuple[list[str], dict[str, str]]:
    lines = path.read_text(encoding="utf-8-sig", errors="strict").splitlines()
    values: dict[str, str] = {}
    for raw in lines:
        text = raw.strip()
        if not text or text.startswith("#") or "=" not in text:
            continue
        if text.startswith("export "):
            text = text[7:].strip()
        key, value = text.split("=", 1)
        key = key.strip()
        value = value.strip()
        if not ASSIGNMENT.fullmatch(key):
            continue
        if len(value) >= 2 and value[0] == value[-1] and value[0] in {'"', "'"}:
            value = value[1:-1]
        if value and value.lower() not in PLACEHOLDERS:
            values[key] = value
    return lines, values


def complete_olist_tuple(values: dict[str, str]) -> bool:
    return all(bool(values.get(key, "").strip()) for key in REQUIRED_KEYS)


def candidate_backups(backup_dir: Path) -> list[Path]:
    candidates: dict[Path, int] = {}
    for pattern in BACKUP_PATTERNS:
        for path in backup_dir.glob(pattern):
            if not path.is_file():
                continue
            try:
                _, values = parse_env(path)
                if not complete_olist_tuple(values):
                    continue
                candidates[path] = int(path.stat().st_mtime)
            except (OSError, UnicodeError):
                continue
    return [path for path, _ in sorted(candidates.items(), key=lambda item: item[1], reverse=True)]


def safe_oauth_error(exc: urllib.error.HTTPError) -> str:
    try:
        payload = json.loads(exc.read(4096).decode("utf-8", errors="replace"))
    except (OSError, UnicodeError, json.JSONDecodeError, AttributeError, TypeError):
        return ""
    error = payload.get("error") if isinstance(payload, dict) else None
    return error if isinstance(error, str) and error in SAFE_OAUTH_ERRORS else ""


def request_token(values: dict[str, str]) -> tuple[dict[str, Any] | None, str]:
    payload = urllib.parse.urlencode(
        {
            "grant_type": "refresh_token",
            "client_id": values["OLIST_CLIENT_ID"],
            "client_secret": values["OLIST_CLIENT_SECRET"],
            "refresh_token": values["OLIST_REFRESH_TOKEN"],
        }
    ).encode("utf-8")
    request = urllib.request.Request(
        TOKEN_URL,
        data=payload,
        headers={"Content-Type": "application/x-www-form-urlencoded"},
    )
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            result = json.loads(response.read())
    except urllib.error.HTTPError as exc:
        classification = safe_oauth_error(exc)
        return None, classification or f"http_{int(getattr(exc, 'code', 0) or 0)}"
    except (urllib.error.URLError, TimeoutError, json.JSONDecodeError):
        return None, "transport_or_decode_error"
    if not isinstance(result, dict):
        return None, "invalid_response"
    access = result.get("access_token")
    if not isinstance(access, str) or not access:
        return None, "missing_access_token"
    return result, "ok"


def atomic_replace_values(env_path: Path, replacements: dict[str, str]) -> Path:
    lines, _ = parse_env(env_path)
    original = env_path.stat()
    safety = env_path.parent / f".env.pre-olist-recovery-{int(time.time())}"
    shutil.copy2(env_path, safety)
    os.chmod(safety, original.st_mode & 0o777)

    output: list[str] = []
    written: set[str] = set()
    for raw in lines:
        text = raw.strip()
        probe = text[7:].strip() if text.startswith("export ") else text
        key = probe.split("=", 1)[0].strip() if probe and not probe.startswith("#") and "=" in probe else ""
        if key in replacements:
            output.append(f"{key}={replacements[key]}")
            written.add(key)
        else:
            output.append(raw)
    for key in replacements:
        if key not in written:
            output.append(f"{key}={replacements[key]}")

    fd, temp_name = tempfile.mkstemp(prefix=".env.olist-recover.", dir=str(env_path.parent))
    temp = Path(temp_name)
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as handle:
            handle.write("\n".join(output).rstrip("\n") + "\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temp, original.st_mode & 0o777)
        if hasattr(os, "chown"):
            os.chown(temp, original.st_uid, original.st_gid)
        os.replace(temp, env_path)
    finally:
        temp.unlink(missing_ok=True)
    return safety


def recover(
    env_path: Path,
    backup_dir: Path,
    requester: Callable[[dict[str, str]], tuple[dict[str, Any] | None, str]] = request_token,
) -> dict[str, Any]:
    if not env_path.is_file():
        raise RecoveryError("shared_env_missing")

    candidates = candidate_backups(backup_dir)
    print(f"olist_backup_candidate_count={len(candidates)}")
    if not candidates:
        raise RecoveryError("no_complete_olist_backup_tuple")

    attempted: list[dict[str, str]] = []
    for index, path in enumerate(candidates, 1):
        _, values = parse_env(path)
        result, classification = requester(values)
        attempted.append({"backup": path.name, "result": classification})
        print(f"olist_backup_attempt={index} backup={path.name} result={classification}")
        if result is None:
            continue

        access_token = result.get("access_token")
        refresh_token = result.get("refresh_token") or values.get("OLIST_REFRESH_TOKEN")
        if not isinstance(access_token, str) or not access_token:
            continue
        if not isinstance(refresh_token, str) or not refresh_token:
            continue

        replacements = {
            "OLIST_CLIENT_ID": values["OLIST_CLIENT_ID"],
            "OLIST_CLIENT_SECRET": values["OLIST_CLIENT_SECRET"],
            "OLIST_ACCESS_TOKEN": access_token,
            "OLIST_REFRESH_TOKEN": refresh_token,
        }
        safety = atomic_replace_values(env_path, replacements)
        print(f"olist_recovery_selected_backup={path.name}")
        print(f"olist_recovery_safety_backup={safety.name}")
        print("olist_recovery_updated_keys=OLIST_CLIENT_ID,OLIST_CLIENT_SECRET,OLIST_ACCESS_TOKEN,OLIST_REFRESH_TOKEN")
        print("secret_values_printed=false")
        return {
            "ok": True,
            "selected_backup": path.name,
            "attempt_count": index,
            "attempted": attempted,
            "safety_backup": safety.name,
        }

    raise RecoveryError("no_backup_tuple_accepted_by_provider")


def main() -> int:
    parser = argparse.ArgumentParser(description="Recover Olist OAuth from private production env backups")
    parser.add_argument("--env", default="/home/ubuntu/shopvivaliz-deploy/shared/.env")
    parser.add_argument("--backup-dir", default="/home/ubuntu/shopvivaliz-deploy/shared")
    args = parser.parse_args()
    try:
        report = recover(Path(args.env), Path(args.backup_dir))
    except RecoveryError as exc:
        print(f"olist_recovery_ok=false reason={exc}")
        return 2
    print(f"olist_recovery_ok={str(bool(report.get('ok'))).lower()}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
