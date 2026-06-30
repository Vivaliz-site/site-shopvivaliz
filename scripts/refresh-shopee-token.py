#!/usr/bin/env python3
"""
Renova o access token da Shopee via refresh_token e salva nos GitHub Secrets.

Uso:
    python scripts/refresh-shopee-token.py

Variaveis esperadas no ambiente:
    SHOPEE_PARTNER_ID ou SHOPEE_TEST_PARTNER_ID
    SHOPEE_PARTNER_KEY ou SHOPEE_TEST_PARTNER_KEY
    SHOPEE_SHOP_ID
    SHOPEE_REFRESH_TOKEN
    SHOPEE_BASE_URL (opcional)
"""
import hashlib
import hmac
import json
import os
import subprocess
import sys
import time
from pathlib import Path

import requests

DEFAULT_BASE_URL = "https://partner.shopeemobile.com/api/v2"
SANDBOX_BASE_URL = "https://openplatform.sandbox.test-stable.shopee.sg/api/v2"


def get_env(name: str) -> str:
    return os.environ.get(name, "").strip()


def save_secret(name: str, value: str) -> None:
    result = subprocess.run(
        ["gh", "secret", "set", name, "--body", value],
        capture_output=True,
        text=True,
        cwd=str(Path(__file__).parent.parent),
    )
    if result.returncode == 0:
        print(f"  OK: {name} salvo")
    else:
        print(f"  ERRO ao salvar {name}: {result.stderr.strip()}")


def resolve_creds() -> tuple[str, str, str, str]:
    partner_id = get_env("SHOPEE_PARTNER_ID") or get_env("SHOPEE_TEST_PARTNER_ID")
    partner_key = get_env("SHOPEE_PARTNER_KEY") or get_env("SHOPEE_TEST_PARTNER_KEY")
    shop_id = get_env("SHOPEE_SHOP_ID")
    refresh_token = get_env("SHOPEE_REFRESH_TOKEN")
    if not partner_id or not partner_key or not shop_id or not refresh_token:
        print("ERRO: faltam variaveis obrigatorias da Shopee no ambiente.")
        sys.exit(1)
    return partner_id, partner_key, shop_id, refresh_token


def resolve_base_url() -> str:
    configured = get_env("SHOPEE_BASE_URL").rstrip("/")
    if configured:
        return configured
    if get_env("SHOPEE_TEST_PARTNER_ID") or get_env("SHOPEE_TEST_PARTNER_KEY"):
        return SANDBOX_BASE_URL
    return DEFAULT_BASE_URL


def auth_sign(partner_id: str, partner_key: str, path: str, timestamp: int) -> str:
    base = f"{partner_id}/api/v2{path}{timestamp}"
    return hmac.new(
        partner_key.encode("utf-8"),
        base.encode("utf-8"),
        hashlib.sha256,
    ).hexdigest()


def main() -> int:
    partner_id, partner_key, shop_id, refresh_token = resolve_creds()
    base_url = resolve_base_url()
    path = "/auth/access_token/get"
    timestamp = int(time.time())

    params = {
        "partner_id": partner_id,
        "timestamp": timestamp,
        "sign": auth_sign(partner_id, partner_key, path, timestamp),
    }
    body = {
        "refresh_token": refresh_token,
        "shop_id": int(shop_id),
        "partner_id": int(partner_id),
    }

    print("Renovando token Shopee...")
    print(f"  Base URL: {base_url}")

    resp = requests.post(f"{base_url}{path}", params=params, json=body, timeout=30)
    try:
        data = resp.json()
    except ValueError:
        data = {"raw": resp.text[:800]}

    print(json.dumps(data, indent=2, ensure_ascii=False)[:1200])

    response = data.get("response", {})
    access_token = response.get("access_token")
    new_refresh_token = response.get("refresh_token")

    if not access_token:
        print("ERRO: Shopee nao retornou access_token novo.")
        return 1

    save_secret("SHOPEE_ACCESS_TOKEN", access_token)
    if new_refresh_token:
        save_secret("SHOPEE_REFRESH_TOKEN", new_refresh_token)

    print("OK: tokens Shopee atualizados.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
