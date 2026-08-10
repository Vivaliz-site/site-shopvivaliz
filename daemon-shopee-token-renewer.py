#!/usr/bin/env python3
"""Refresh Shopee OAuth tokens without exposing or partially writing secrets."""

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

from scripts.env_keyset_guard import assert_monotonic_text

ENV_PATH = Path(os.environ.get("SHOPVIVALIZ_ENV_PATH", "c:/site-shopvivaliz/.env" if os.name == "nt" else "/home/ubuntu/shopvivaliz-deploy/current/.env"))
BASE_URL = "https://partner.shopeemobile.com/api/v2"

def get_config() -> dict[str, str]:
    config: dict[str, str] = {}
    if not ENV_PATH.is_file(): return config
    for raw in ENV_PATH.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line: continue
        key, value = line.split("=", 1); config[key.strip()] = value.strip().strip('"').strip("'")
    return config

def sign(partner_id: str, partner_key: str, path: str, timestamp: int) -> str:
    api_path = path if path.startswith("/api/") else f"/api/v2{path}"
    return hmac.new(partner_key.encode(), f"{partner_id}{api_path}{timestamp}".encode(), hashlib.sha256).hexdigest()

def renew_token(config: dict[str, str]) -> dict[str, Any] | None:
    partner_id, partner_key = config.get("SHOPEE_PARTNER_ID", ""), config.get("SHOPEE_PARTNER_KEY", "")
    shop_id, refresh_token = config.get("SHOPEE_SHOP_ID", ""), config.get("SHOPEE_REFRESH_TOKEN", "")
    if not all((partner_id, partner_key, shop_id, refresh_token)):
        print("[!] Credenciais Shopee incompletas"); return None
    path = "/auth/access_token/get"; timestamp = int(time.time())
    query = f"partner_id={partner_id}&timestamp={timestamp}&sign={sign(partner_id, partner_key, path, timestamp)}"
    body = json.dumps({"refresh_token": refresh_token, "shop_id": int(shop_id), "partner_id": int(partner_id)}).encode()
    try:
        with urllib.request.urlopen(urllib.request.Request(f"{BASE_URL}{path}?{query}", data=body, headers={"Content-Type":"application/json"}), timeout=30) as response:
            result = json.loads(response.read())
    except (urllib.error.URLError, TimeoutError, json.JSONDecodeError) as exc:
        print(f"[!] Renovação Shopee falhou: {type(exc).__name__}"); return None
    if isinstance(result, dict) and result.get("error"):
        print(f"[!] Renovação Shopee falhou: {result.get('error')}"); return None
    return result if isinstance(result, dict) else None

def update_env(new_token: str, new_refresh_token: str) -> None:
    target = ENV_PATH.resolve(strict=True); content = target.read_text(encoding="utf-8")
    replacements = {"SHOPEE_ACCESS_TOKEN": new_token, "SHOPEE_REFRESH_TOKEN": new_refresh_token}
    found: set[str] = set(); lines: list[str] = []
    for line in content.splitlines():
        key = line.split("=", 1)[0].strip() if "=" in line else ""
        if key in replacements: lines.append(f"{key}={replacements[key]}"); found.add(key)
        else: lines.append(line)
    for key, value in replacements.items():
        if key not in found: lines.append(f"{key}={value}")
    candidate_text = "\n".join(lines).rstrip("\n") + "\n"
    assert_monotonic_text(content, candidate_text)
    original = target.stat(); mode = original.st_mode & 0o777
    descriptor, temporary_name = tempfile.mkstemp(prefix=".env.", dir=target.parent); temporary = Path(temporary_name)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8", newline="\n") as handle:
            handle.write(candidate_text); handle.flush(); os.fsync(handle.fileno())
        os.chmod(temporary, mode)
        if os.name != "nt": os.chown(temporary, original.st_uid, original.st_gid)
        os.replace(temporary, target)
        updated = target.stat()
        if (updated.st_mode & 0o777) != mode or (os.name != "nt" and (updated.st_uid != original.st_uid or updated.st_gid != original.st_gid)):
            raise RuntimeError("metadados do .env mudaram durante renovacao Shopee")
    finally: temporary.unlink(missing_ok=True)

def renew_once() -> bool:
    config = get_config(); result = renew_token(config); response = result.get("response") if isinstance(result, dict) else None
    if not isinstance(response, dict): response = result if isinstance(result, dict) else {}
    access_token = response.get("access_token"); refresh_token = response.get("refresh_token") or config.get("SHOPEE_REFRESH_TOKEN")
    if not isinstance(access_token, str) or not access_token or not isinstance(refresh_token, str) or not refresh_token: return False
    update_env(access_token, refresh_token); print(f"[+] Token Shopee renovado em {datetime.now(timezone.utc).isoformat()}"); return True

def main() -> int:
    parser = argparse.ArgumentParser(); parser.add_argument("--once", action="store_true"); parser.add_argument("--interval", type=int, default=10800); parser.add_argument("--retry-interval", type=int, default=900); args = parser.parse_args()
    while True:
        try: ok = renew_once()
        except KeyboardInterrupt: return 130
        except Exception as exc: print(f"[!] Renovação falhou com segurança: {type(exc).__name__}"); ok = False
        if args.once: return 0 if ok else 1
        time.sleep(max(60, args.interval if ok else args.retry_interval))

if __name__ == "__main__": raise SystemExit(main())
