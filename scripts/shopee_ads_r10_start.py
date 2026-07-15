#!/usr/bin/env python3
"""Shopee Ads R$10 starter.

Safe-by-default helper for GitHub Actions.

What it does:
- validates required Shopee Open Platform credentials from environment variables;
- performs a non-mutating shop auth smoke test;
- prepares a Shopee Ads manual product ad request with a daily budget of R$10 by default;
- only sends the Ads creation request when live mode is explicitly enabled and item IDs are provided.

No secret values are printed.
"""

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
import sys
import time
import urllib.parse
from dataclasses import dataclass
from typing import Any, Dict, Iterable, List, Optional

try:
    import requests
except ImportError as exc:  # pragma: no cover
    print("ERROR: missing dependency 'requests'. Install with: pip install requests", file=sys.stderr)
    raise SystemExit(2) from exc


DEFAULT_HOST = "https://partner.shopeemobile.com"
DEFAULT_SHOP_INFO_PATH = "/api/v2/shop/get_shop_info"
DEFAULT_ADS_CREATE_PATH = "/api/v2/ads/create_manual_product_ads"


@dataclass(frozen=True)
class ShopeeConfig:
    host: str
    partner_id: int
    partner_key: str
    shop_id: int
    access_token: str
    shop_info_path: str
    ads_create_path: str


def env_required(name: str) -> str:
    value = os.getenv(name, "").strip()
    if not value:
        raise ValueError(f"Missing required environment variable: {name}")
    return value


def load_config() -> ShopeeConfig:
    return ShopeeConfig(
        host=os.getenv("SHOPEE_HOST", DEFAULT_HOST).rstrip("/"),
        partner_id=int(env_required("SHOPEE_PARTNER_ID")),
        partner_key=env_required("SHOPEE_PARTNER_KEY"),
        shop_id=int(env_required("SHOPEE_SHOP_ID")),
        access_token=env_required("SHOPEE_ACCESS_TOKEN"),
        shop_info_path=os.getenv("SHOPEE_SHOP_INFO_PATH", DEFAULT_SHOP_INFO_PATH),
        ads_create_path=os.getenv("SHOPEE_ADS_CREATE_PATH", DEFAULT_ADS_CREATE_PATH),
    )


def parse_bool(value: str) -> bool:
    return value.strip().lower() in {"1", "true", "yes", "y", "sim", "on"}


def parse_item_ids(value: str) -> List[int]:
    item_ids: List[int] = []
    for raw in value.replace(";", ",").split(","):
        raw = raw.strip()
        if not raw:
            continue
        item_ids.append(int(raw))
    return item_ids


def sign(config: ShopeeConfig, path: str, timestamp: int) -> str:
    base = f"{config.partner_id}{path}{timestamp}{config.access_token}{config.shop_id}"
    return hmac.new(config.partner_key.encode("utf-8"), base.encode("utf-8"), hashlib.sha256).hexdigest()


def build_url(config: ShopeeConfig, path: str, timestamp: int) -> str:
    query = {
        "partner_id": config.partner_id,
        "timestamp": timestamp,
        "access_token": config.access_token,
        "shop_id": config.shop_id,
        "sign": sign(config, path, timestamp),
    }
    return f"{config.host}{path}?{urllib.parse.urlencode(query)}"


def scrub(value: Any) -> Any:
    if isinstance(value, dict):
        cleaned: Dict[str, Any] = {}
        for key, inner in value.items():
            if key.lower() in {"access_token", "refresh_token", "partner_key", "sign"}:
                cleaned[key] = "***"
            else:
                cleaned[key] = scrub(inner)
        return cleaned
    if isinstance(value, list):
        return [scrub(item) for item in value]
    return value


def call_shopee(config: ShopeeConfig, path: str, method: str = "GET", payload: Optional[Dict[str, Any]] = None) -> Dict[str, Any]:
    timestamp = int(time.time())
    url = build_url(config, path, timestamp)
    timeout = int(os.getenv("SHOPEE_HTTP_TIMEOUT", "30"))
    headers = {"Content-Type": "application/json"}

    if method.upper() == "GET":
        response = requests.get(url, headers=headers, timeout=timeout)
    elif method.upper() == "POST":
        response = requests.post(url, headers=headers, json=payload or {}, timeout=timeout)
    else:
        raise ValueError(f"Unsupported HTTP method: {method}")

    try:
        body = response.json()
    except ValueError:
        body = {"raw_text": response.text[:1000]}

    return {
        "status_code": response.status_code,
        "ok": response.ok,
        "body": scrub(body),
    }


def build_default_ads_payload(item_ids: Iterable[int], budget_brl: float, bid_price_brl: float) -> Dict[str, Any]:
    """Build a conservative payload shape.

    Shopee Ads payloads may vary by account/region/API permission. If the default shape does not match
    the enabled Ads API contract for this app, set SHOPEE_ADS_RAW_PAYLOAD with the exact JSON payload
    required by Shopee and this script will use it instead.
    """
    budget_cents = int(round(budget_brl * 100))
    bid_cents = int(round(bid_price_brl * 100))
    return {
        "product_ads_list": [
            {
                "item_id": item_id,
                "daily_budget": budget_cents,
                "bid_price": bid_cents,
            }
            for item_id in item_ids
        ]
    }


def load_ads_payload(item_ids: List[int], budget_brl: float, bid_price_brl: float) -> Dict[str, Any]:
    raw_payload = os.getenv("SHOPEE_ADS_RAW_PAYLOAD", "").strip()
    if raw_payload:
        return json.loads(raw_payload)
    return build_default_ads_payload(item_ids, budget_brl, bid_price_brl)


def print_json(title: str, data: Dict[str, Any]) -> None:
    print(f"\n## {title}")
    print(json.dumps(data, ensure_ascii=False, indent=2))


def main() -> int:
    parser = argparse.ArgumentParser(description="Start/check Shopee Ads with a small R$10 daily budget.")
    parser.add_argument("--live", action="store_true", help="Actually send the Shopee Ads creation request.")
    parser.add_argument("--item-ids", default=os.getenv("SHOPEE_ADS_ITEM_IDS", ""), help="Comma-separated Shopee item IDs.")
    parser.add_argument("--daily-budget-brl", type=float, default=float(os.getenv("SHOPEE_ADS_DAILY_BUDGET_BRL", "10")))
    parser.add_argument("--bid-price-brl", type=float, default=float(os.getenv("SHOPEE_ADS_BID_PRICE_BRL", "0.20")))
    args = parser.parse_args()

    try:
        config = load_config()
    except Exception as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 2

    print("Shopee Ads R$10 starter")
    print(f"Host: {config.host}")
    print(f"Shop ID: {config.shop_id}")
    print(f"Daily budget target: R$ {args.daily_budget_brl:.2f}")
    print(f"Live mode requested: {args.live}")

    print("\nChecking Shopee auth/shop access with a non-mutating endpoint...")
    shop_result = call_shopee(config, config.shop_info_path, method="GET")
    print_json("Shop auth smoke test", shop_result)

    body = shop_result.get("body", {})
    error_text = json.dumps(body, ensure_ascii=False).lower()
    if shop_result["status_code"] >= 400 or "error_permission" in error_text or "permission" in error_text:
        print("\nERROR: Shopee credentials responded with an HTTP or permission error. Ads will not be created.", file=sys.stderr)
        return 1

    item_ids = parse_item_ids(args.item_ids)
    if not item_ids:
        print("\nNo SHOPEE_ADS_ITEM_IDS / --item-ids provided. Credentials were checked, but no ads were created.")
        print("Set item IDs and rerun the workflow in live mode to start ads with the R$10 daily budget.")
        return 0

    payload = load_ads_payload(item_ids, args.daily_budget_brl, args.bid_price_brl)
    print_json("Prepared Ads payload", scrub(payload))

    live_from_env = parse_bool(os.getenv("SHOPEE_ENABLE_LIVE_ADS", "false"))
    live = args.live or live_from_env
    if not live:
        print("\nDry-run only. Set live=true in workflow_dispatch or SHOPEE_ENABLE_LIVE_ADS=true to create ads.")
        return 0

    print("\nCreating Shopee Ads now...")
    ads_result = call_shopee(config, config.ads_create_path, method="POST", payload=payload)
    print_json("Ads create response", ads_result)

    ads_error_text = json.dumps(ads_result.get("body", {}), ensure_ascii=False).lower()
    if ads_result["status_code"] >= 400 or "error" in ads_error_text:
        print("\nERROR: Shopee Ads request returned an error. Check permission, endpoint path, payload contract, item eligibility and token scope.", file=sys.stderr)
        return 1

    print("\nShopee Ads request completed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
