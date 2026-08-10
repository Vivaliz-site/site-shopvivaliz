#!/usr/bin/env python3
"""Refresh Olist OAuth tokens without exposing or partially writing secrets."""

from __future__ import annotations

import argparse
import json
import os
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from scripts.env_keyset_guard import assert_monotonic_text


ENV_PATH = Path(
    os.environ.get(
        "SHOPVIVALIZ_ENV_PATH",
        "c:/site-shopvivaliz/.env" if os.name == "nt" else "/home/ubuntu/shopvivaliz-deploy/current/.env",
    )
)
TOKEN_URL = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token"
SAFE_OAUTH_ERROR_CODES = frozenset(
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


def get_config() -> dict[str, str]:
    config: dict[str, str] = {}
    if not ENV_PATH.is_file():
        return config
    for raw in ENV_PATH.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        config[key.strip()] = value.strip().strip('"').strip("'")
    return config


def safe_oauth_error_code(exc: urllib.error.HTTPError) -> str:
    """Return only a whitelisted OAuth error class from an HTTP error body.

    Error descriptions and arbitrary provider payload fields are deliberately
    ignored because they are not guaranteed to be free of credential material.
    """
    try:
        raw = exc.read(4096)
        data = json.loads(raw.decode("utf-8", errors="replace"))
    except (OSError, UnicodeError, json.JSONDecodeError, AttributeError, TypeError):
        return ""
    candidate = data.get("error") if isinstance(data, dict) else None
    if isinstance(candidate, str) and candidate in SAFE_OAUTH_ERROR_CODES:
        return candidate
    return ""


def renew_token(config: dict[str, str]) -> dict[str, Any] | None:
    client_id = config.get("OLIST_CLIENT_ID", "")
    client_secret = config.get("OLIST_CLIENT_SECRET", "")
    refresh_token = config.get("OLIST_REFRESH_TOKEN", "")
    if not all((client_id, client_secret, refresh_token)):
        print("[!] Credenciais Olist incompletas")
        return None

    payload = urllib.parse.urlencode({
        "grant_type": "refresh_token",
        "client_id": client_id,
        "client_secret": client_secret,
        "refresh_token": refresh_token,
    }).encode("utf-8")
    request = urllib.request.Request(
        TOKEN_URL,
        data=payload,
        headers={"Content-Type": "application/x-www-form-urlencoded"},
    )
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            result = json.loads(response.read())
    except urllib.error.HTTPError as exc:
        error_code = safe_oauth_error_code(exc)
        status = int(getattr(exc, "code", 0) or 0)
        suffix = f" oauth_error={error_code}" if error_code else ""
        print(f"[!] Renovação Olist recusada: HTTP {status}{suffix}")
        return None
    except (urllib.error.URLError, TimeoutError, json.JSONDecodeError) as exc:
        print(f"[!] Renovação Olist falhou: {type(exc).__name__}")
        return None
    return result if isinstance(result, dict) else None


def update_env(new_token: str, new_refresh_token: str) -> None:
    target = ENV_PATH.resolve(strict=True)
    content = target.read_text(encoding="utf-8")
    replacements = {
        "OLIST_ACCESS_TOKEN": new_token,
        "OLIST_REFRESH_TOKEN": new_refresh_token,
    }
    found: set[str] = set()
    lines: list[str] = []
    for line in content.splitlines():
        key = line.split("=", 1)[0].strip() if "=" in line else ""
        if key in replacements:
            lines.append(f"{key}={replacements[key]}")
            found.add(key)
        else:
            lines.append(line)
    for key, value in replacements.items():
        if key not in found:
            lines.append(f"{key}={value}")

    candidate_text = "\n".join(lines).rstrip("\n") + "\n"
    assert_monotonic_text(content, candidate_text)

    original = target.stat()
    mode = original.st_mode & 0o777
    descriptor, temporary_name = tempfile.mkstemp(prefix=".env.", dir=target.parent)
    temporary = Path(temporary_name)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8", newline="\n") as handle:
            handle.write(candidate_text)
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temporary, mode)
        if os.name != "nt":
            os.chown(temporary, original.st_uid, original.st_gid)
        os.replace(temporary, target)
        updated = target.stat()
        if (updated.st_mode & 0o777) != mode:
            raise RuntimeError("permissao do .env mudou durante renovacao Olist")
        if os.name != "nt" and (updated.st_uid != original.st_uid or updated.st_gid != original.st_gid):
            raise RuntimeError("owner/group do .env mudou durante renovacao Olist")
    finally:
        temporary.unlink(missing_ok=True)


def renew_once() -> bool:
    config = get_config()
    result = renew_token(config)
    access_token = result.get("access_token") if isinstance(result, dict) else None
    if not isinstance(access_token, str) or not access_token:
        return False
    refresh_token = result.get("refresh_token") or config.get("OLIST_REFRESH_TOKEN")
    if not isinstance(refresh_token, str) or not refresh_token:
        return False
    update_env(access_token, refresh_token)
    print(f"[+] Token Olist renovado em {datetime.now(timezone.utc).isoformat()}")
    return True


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--once", action="store_true", help="Renova uma vez e encerra")
    parser.add_argument("--interval", type=int, default=7200, help="Intervalo após sucesso")
    parser.add_argument("--retry-interval", type=int, default=900, help="Intervalo após falha")
    args = parser.parse_args()

    while True:
        try:
            ok = renew_once()
        except KeyboardInterrupt:
            return 130
        except Exception as exc:
            print(f"[!] Renovação falhou com segurança: {type(exc).__name__}")
            ok = False
        if args.once:
            return 0 if ok else 1
        time.sleep(max(60, args.interval if ok else args.retry_interval))


if __name__ == "__main__":
    raise SystemExit(main())
