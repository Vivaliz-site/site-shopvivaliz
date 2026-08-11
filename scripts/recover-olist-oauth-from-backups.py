#!/usr/bin/env python3
"""Recover a working Olist OAuth tuple from private production runtime history.

No credential value is printed. Client pairs and refresh tokens are collected
from the current shared env and private backups, then tested only against the
official OAuth token endpoint. Nothing is written until the provider issues a
new access token for one exact combination.
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
CLIENT_REJECTION_ERRORS = frozenset({"invalid_client", "unauthorized_client"})
TRANSIENT_ERRORS = frozenset({"temporarily_unavailable", "server_error", "transport_or_decode_error"})
PLACEHOLDERS = frozenset({"changeme", "change-me", "placeholder", "***", "replace_me"})
ASSIGNMENT = re.compile(r"^[A-Za-z_][A-Za-z0-9_]*$")
MAX_PROVIDER_ATTEMPTS = 96


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


def backup_files(backup_dir: Path) -> list[Path]:
    candidates: dict[Path, int] = {}
    for pattern in BACKUP_PATTERNS:
        for path in backup_dir.glob(pattern):
            if not path.is_file():
                continue
            try:
                _, values = parse_env(path)
            except (OSError, UnicodeError):
                continue
            relevant = any(
                values.get(key, "").strip()
                for key in (
                    "OLIST_CLIENT_ID",
                    "OLIST_CLIENT_SECRET",
                    "OLIST_REFRESH_TOKEN",
                    "TINY_CLIENT_ID",
                    "TINY_CLIENT_SECRET",
                )
            )
            if relevant:
                candidates[path] = int(path.stat().st_mtime)
    return [path for path, _ in sorted(candidates.items(), key=lambda item: item[1], reverse=True)]


def candidate_backups(backup_dir: Path) -> list[Path]:
    result: list[Path] = []
    for path in backup_files(backup_dir):
        try:
            _, values = parse_env(path)
        except (OSError, UnicodeError):
            continue
        if complete_olist_tuple(values):
            result.append(path)
    return result


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


def _source_material(env_path: Path, backup_dir: Path) -> list[tuple[str, dict[str, str]]]:
    sources: list[tuple[str, dict[str, str]]] = []
    _, current = parse_env(env_path)
    sources.append(("current", current))
    for path in backup_files(backup_dir):
        try:
            _, values = parse_env(path)
        except (OSError, UnicodeError):
            continue
        sources.append((path.name, values))
    return sources


def _unique_clients(sources: list[tuple[str, dict[str, str]]]) -> list[dict[str, str]]:
    clients: list[dict[str, str]] = []
    seen: set[tuple[str, str]] = set()
    for source, values in sources:
        for namespace in ("OLIST", "TINY"):
            client_id = values.get(f"{namespace}_CLIENT_ID", "").strip()
            client_secret = values.get(f"{namespace}_CLIENT_SECRET", "").strip()
            if not client_id or not client_secret:
                continue
            identity = (client_id, client_secret)
            if identity in seen:
                continue
            seen.add(identity)
            clients.append(
                {
                    "source": source,
                    "namespace": namespace.lower(),
                    "client_id": client_id,
                    "client_secret": client_secret,
                }
            )
    return clients


def _unique_refresh_tokens(sources: list[tuple[str, dict[str, str]]]) -> list[dict[str, str]]:
    tokens: list[dict[str, str]] = []
    seen: set[str] = set()
    for source, values in sources:
        token = values.get("OLIST_REFRESH_TOKEN", "").strip()
        if not token or token in seen:
            continue
        seen.add(token)
        tokens.append({"source": source, "refresh_token": token})
    return tokens


def _ordered_tokens_for_client(client: dict[str, str], tokens: list[dict[str, str]]) -> list[dict[str, str]]:
    same_source = [token for token in tokens if token["source"] == client["source"]]
    current = [token for token in tokens if token["source"] == "current" and token not in same_source]
    others = [token for token in tokens if token not in same_source and token not in current]
    return same_source + current + others


def recover(
    env_path: Path,
    backup_dir: Path,
    requester: Callable[[dict[str, str]], tuple[dict[str, Any] | None, str]] = request_token,
) -> dict[str, Any]:
    if not env_path.is_file():
        raise RecoveryError("shared_env_missing")

    sources = _source_material(env_path, backup_dir)
    clients = _unique_clients(sources)
    refresh_tokens = _unique_refresh_tokens(sources)
    print(f"olist_runtime_source_count={len(sources)}")
    print(f"olist_unique_client_pair_count={len(clients)}")
    print(f"olist_unique_refresh_token_count={len(refresh_tokens)}")
    if not clients:
        raise RecoveryError("no_oauth_client_pair_in_runtime_history")
    if not refresh_tokens:
        raise RecoveryError("no_refresh_token_in_runtime_history")

    attempted: list[dict[str, str]] = []
    attempt_count = 0
    for client in clients:
        for token in _ordered_tokens_for_client(client, refresh_tokens):
            attempt_count += 1
            if attempt_count > MAX_PROVIDER_ATTEMPTS:
                raise RecoveryError("provider_attempt_limit_reached")

            candidate = {
                "OLIST_CLIENT_ID": client["client_id"],
                "OLIST_CLIENT_SECRET": client["client_secret"],
                "OLIST_REFRESH_TOKEN": token["refresh_token"],
            }
            result, classification = requester(candidate)
            attempted.append(
                {
                    "client_source": client["source"],
                    "client_namespace": client["namespace"],
                    "refresh_source": token["source"],
                    "result": classification,
                }
            )
            print(
                "olist_crossmatch_attempt="
                f"{attempt_count} client_source={client['source']} "
                f"client_namespace={client['namespace']} "
                f"refresh_source={token['source']} result={classification}"
            )

            if classification in TRANSIENT_ERRORS:
                raise RecoveryError("oauth_provider_temporarily_unavailable")

            if result is None:
                if classification in CLIENT_REJECTION_ERRORS:
                    # A client rejection is independent of the refresh token;
                    # do not hammer the provider with the same rejected pair.
                    break
                continue

            access_token = result.get("access_token")
            refresh_token = result.get("refresh_token") or token["refresh_token"]
            if not isinstance(access_token, str) or not access_token:
                continue
            if not isinstance(refresh_token, str) or not refresh_token:
                continue

            replacements = {
                "OLIST_CLIENT_ID": client["client_id"],
                "OLIST_CLIENT_SECRET": client["client_secret"],
                "OLIST_ACCESS_TOKEN": access_token,
                "OLIST_REFRESH_TOKEN": refresh_token,
            }
            safety = atomic_replace_values(env_path, replacements)
            print(f"olist_recovery_client_source={client['source']}")
            print(f"olist_recovery_client_namespace={client['namespace']}")
            print(f"olist_recovery_refresh_source={token['source']}")
            print(f"olist_recovery_safety_backup={safety.name}")
            print("olist_recovery_updated_keys=OLIST_CLIENT_ID,OLIST_CLIENT_SECRET,OLIST_ACCESS_TOKEN,OLIST_REFRESH_TOKEN")
            print("secret_values_printed=false")
            return {
                "ok": True,
                "client_source": client["source"],
                "client_namespace": client["namespace"],
                "refresh_source": token["source"],
                "attempt_count": attempt_count,
                "attempted": attempted,
                "safety_backup": safety.name,
            }

    raise RecoveryError("no_historical_client_refresh_combination_accepted_by_provider")


def main() -> int:
    parser = argparse.ArgumentParser(description="Recover Olist OAuth from provider-validated private runtime history")
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
