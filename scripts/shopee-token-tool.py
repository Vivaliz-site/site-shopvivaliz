#!/usr/bin/env python3
"""Ferramenta segura para OAuth Shopee Open API v2.

Uso:
  python scripts/shopee-token-tool.py auth-url
  python scripts/shopee-token-tool.py exchange-code --code CODE --shop-id SHOP_ID

Nao imprime tokens completos por padrao.
"""
from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
import time
from urllib.parse import quote

import requests
from dotenv import load_dotenv

load_dotenv()


DEFAULT_BASE_URL = "https://partner.shopeemobile.com/api/v2"
DEFAULT_REDIRECT = "https://shopvivaliz.com.br"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="OAuth Shopee token helper")
    parser.add_argument("--base-url", default=os.environ.get("SHOPEE_BASE_URL", DEFAULT_BASE_URL))
    parser.add_argument("--redirect", default=os.environ.get("SHOPEE_REDIRECT_URL", DEFAULT_REDIRECT))
    parser.add_argument("--show-tokens", action="store_true", help="Imprime tokens completos. Use somente localmente.")
    sub = parser.add_subparsers(dest="command", required=True)

    sub.add_parser("auth-url", help="Gera URL de autorizacao live")

    exchange = sub.add_parser("exchange-code", help="Troca code por access_token e refresh_token")
    exchange.add_argument("--code", required=True)
    exchange.add_argument("--shop-id", default=os.environ.get("SHOPEE_SHOP_ID", ""))
    return parser.parse_args()


def env_required(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise SystemExit(f"Missing env: {name}")
    return value


def sign(partner_id: str, partner_key: str, path: str, timestamp: int) -> str:
    api_path = path if path.startswith("/api/") else f"/api/v2{path}"
    base = f"{partner_id}{api_path}{timestamp}"
    return hmac.new(partner_key.encode("utf-8"), base.encode("utf-8"), hashlib.sha256).hexdigest()


def mask(value: str) -> str:
    if len(value) <= 12:
        return "***"
    return f"{value[:6]}...{value[-6:]}"


def auth_url(args: argparse.Namespace) -> int:
    partner_id = env_required("SHOPEE_PARTNER_ID")
    partner_key = env_required("SHOPEE_PARTNER_KEY")
    path = "/shop/auth_partner"
    timestamp = int(time.time())
    signature = sign(partner_id, partner_key, path, timestamp)
    url = (
        f"{args.base_url.rstrip('/')}{path}"
        f"?partner_id={partner_id}"
        f"&timestamp={timestamp}"
        f"&sign={signature}"
        f"&redirect={quote(args.redirect, safe='')}"
    )
    print(url)
    return 0


def exchange_code(args: argparse.Namespace) -> int:
    partner_id = env_required("SHOPEE_PARTNER_ID")
    partner_key = env_required("SHOPEE_PARTNER_KEY")
    shop_id = str(args.shop_id).strip()
    if not shop_id:
        raise SystemExit("Missing --shop-id or SHOPEE_SHOP_ID")

    path = "/auth/token/get"
    timestamp = int(time.time())
    params = {
        "partner_id": partner_id,
        "timestamp": timestamp,
        "sign": sign(partner_id, partner_key, path, timestamp),
    }
    body = {
        "code": args.code,
        "shop_id": int(shop_id),
        "partner_id": int(partner_id),
    }
    resp = requests.post(f"{args.base_url.rstrip()}{path}", params=params, json=body, timeout=30)
    try:
        payload = resp.json()
    except ValueError:
        payload = {"raw": resp.text}
    if resp.status_code >= 400 or payload.get("error"):
        print(json.dumps(payload, ensure_ascii=False, indent=2))
        return 2

    response = payload.get("response") or payload
    access_token = str(response.get("access_token") or "")
    refresh_token = str(response.get("refresh_token") or "")
    result = {
        "ok": bool(access_token and refresh_token),
        "shop_id": response.get("shop_id") or int(shop_id),
        "expire_in": response.get("expire_in"),
        "access_token": access_token if args.show_tokens else mask(access_token),
        "refresh_token": refresh_token if args.show_tokens else mask(refresh_token),
    }
    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 0 if result["ok"] else 2


def main() -> int:
    args = parse_args()
    if args.command == "auth-url":
        return auth_url(args)
    if args.command == "exchange-code":
        return exchange_code(args)
    raise SystemExit(f"Unknown command: {args.command}")


if __name__ == "__main__":
    raise SystemExit(main())
