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


ENV_PATH = Path(".env")
TOKEN_URL = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token"


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
    except (urllib.error.URLError, TimeoutError, json.JSONDecodeError) as exc:
        print(f"[!] Renovação Olist falhou: {type(exc).__name__}")
        return None
    return result if isinstance(result, dict) else None


def update_env(new_token: str, new_refresh_token: str) -> None:
    content = ENV_PATH.read_text(encoding="utf-8")
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

    mode = ENV_PATH.stat().st_mode & 0o777
    descriptor, temporary_name = tempfile.mkstemp(prefix=".env.", dir=ENV_PATH.parent)
    temporary = Path(temporary_name)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8", newline="\n") as handle:
            handle.write("\n".join(lines).rstrip("\n") + "\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temporary, mode)
        os.replace(temporary, ENV_PATH)
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
