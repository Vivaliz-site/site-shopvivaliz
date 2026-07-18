#!/usr/bin/env python3
"""Refresh Shopee OAuth tokens without exposing or partially writing secrets.

Mesmo padrao de daemon-token-renewer.py (Olist), adaptado pra assinatura
HMAC-SHA256 da Shopee Open API v2. Token de acesso da Shopee expira em
~4h (14400s) -- renova a cada 3h por padrao pra nunca deixar expirar.
"""

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
import tempfile
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


ENV_PATH = Path(".env")
BASE_URL = "https://partner.shopeemobile.com/api/v2"


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


def sign(partner_id: str, partner_key: str, path: str, timestamp: int) -> str:
    api_path = path if path.startswith("/api/") else f"/api/v2{path}"
    base = f"{partner_id}{api_path}{timestamp}"
    return hmac.new(partner_key.encode("utf-8"), base.encode("utf-8"), hashlib.sha256).hexdigest()


def renew_token(config: dict[str, str]) -> dict[str, Any] | None:
    partner_id = config.get("SHOPEE_PARTNER_ID", "")
    partner_key = config.get("SHOPEE_PARTNER_KEY", "")
    shop_id = config.get("SHOPEE_SHOP_ID", "")
    refresh_token = config.get("SHOPEE_REFRESH_TOKEN", "")
    if not all((partner_id, partner_key, shop_id, refresh_token)):
        print("[!] Credenciais Shopee incompletas")
        return None

    path = "/auth/access_token/get"
    timestamp = int(time.time())
    query = (
        f"partner_id={partner_id}"
        f"&timestamp={timestamp}"
        f"&sign={sign(partner_id, partner_key, path, timestamp)}"
    )
    body = json.dumps({
        "refresh_token": refresh_token,
        "shop_id": int(shop_id),
        "partner_id": int(partner_id),
    }).encode("utf-8")
    request = urllib.request.Request(
        f"{BASE_URL}{path}?{query}",
        data=body,
        headers={"Content-Type": "application/json"},
    )
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            result = json.loads(response.read())
    except (urllib.error.URLError, TimeoutError, json.JSONDecodeError) as exc:
        print(f"[!] Renovação Shopee falhou: {type(exc).__name__}")
        return None
    if isinstance(result, dict) and result.get("error"):
        print(f"[!] Renovação Shopee falhou: {result.get('error')} {result.get('message', '')}")
        return None
    return result if isinstance(result, dict) else None


def update_env(new_token: str, new_refresh_token: str) -> None:
    content = ENV_PATH.read_text(encoding="utf-8")
    replacements = {
        "SHOPEE_ACCESS_TOKEN": new_token,
        "SHOPEE_REFRESH_TOKEN": new_refresh_token,
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
    response = result.get("response") if isinstance(result, dict) else None
    if not isinstance(response, dict):
        response = result if isinstance(result, dict) else {}
    access_token = response.get("access_token")
    if not isinstance(access_token, str) or not access_token:
        return False
    refresh_token = response.get("refresh_token") or config.get("SHOPEE_REFRESH_TOKEN")
    if not isinstance(refresh_token, str) or not refresh_token:
        return False
    update_env(access_token, refresh_token)
    print(f"[+] Token Shopee renovado em {datetime.now(timezone.utc).isoformat()}")
    return True


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--once", action="store_true", help="Renova uma vez e encerra")
    parser.add_argument("--interval", type=int, default=10800, help="Intervalo após sucesso (3h)")
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
