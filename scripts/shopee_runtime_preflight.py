#!/usr/bin/env python3
"""Read-only operational preflight for the real Shopee runtime.

The command never prints credential values and never mutates listings. It may refresh
an OAuth access token through the canonical Shopee client when the cached token is
expired; the token cache location must therefore point to persistent private runtime
storage in production.
"""
from __future__ import annotations

import argparse
import json
import os
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

from utils.shopee_client import ShopeeClient  # noqa: E402

REQUIRED = (
    "SHOPEE_PARTNER_ID",
    "SHOPEE_PARTNER_KEY",
    "SHOPEE_SHOP_ID",
)
TOKEN_KEYS = ("SHOPEE_ACCESS_TOKEN", "SHOPEE_REFRESH_TOKEN")


def presence() -> dict[str, bool]:
    return {name: bool((os.environ.get(name) or "").strip()) for name in (*REQUIRED, *TOKEN_KEYS)}


def main() -> int:
    parser = argparse.ArgumentParser(description="Validate real Shopee credentials and read catalog without mutations")
    parser.add_argument("--max-items", type=int, default=5)
    args = parser.parse_args()
    if args.max_items < 1 or args.max_items > 100:
        print("ERROR: --max-items must be between 1 and 100", file=sys.stderr)
        return 2

    state = presence()
    missing = [name for name in REQUIRED if not state[name]]
    if not state["SHOPEE_ACCESS_TOKEN"] and not state["SHOPEE_REFRESH_TOKEN"]:
        missing.append("SHOPEE_ACCESS_TOKEN_OR_REFRESH_TOKEN")

    evidence = {
        "checked_at": datetime.now(timezone.utc).isoformat(),
        "credential_presence": state,
        "missing": missing,
        "catalog_read": False,
        "sample_item_ids": [],
        "status": "failed" if missing else "checking",
    }
    if missing:
        print(json.dumps(evidence, ensure_ascii=False, sort_keys=True))
        return 3

    try:
        client = ShopeeClient()
        items = []
        for item in client.iter_all_products(page_size=min(args.max_items, 100)):
            items.append(item)
            if len(items) >= args.max_items:
                break
        if not items:
            raise RuntimeError("Shopee returned no NORMAL products")
        item_ids = [int(item["item_id"]) for item in items if item.get("item_id") is not None]
        if not item_ids:
            raise RuntimeError("Shopee catalog response did not contain item_id")
        details = client.get_product_details(item_ids[:1])
        if len(details) != 1 or int(details[0].get("item_id") or 0) != item_ids[0]:
            raise RuntimeError("Shopee item detail read-back failed")
        evidence.update(
            {
                "catalog_read": True,
                "sample_item_ids": item_ids,
                "sample_size": len(item_ids),
                "detail_read": True,
                "status": "ok",
            }
        )
        print(json.dumps(evidence, ensure_ascii=False, sort_keys=True))
        return 0
    except Exception as exc:
        evidence["status"] = "failed"
        evidence["error"] = str(exc)
        print(json.dumps(evidence, ensure_ascii=False, sort_keys=True))
        return 4


if __name__ == "__main__":
    raise SystemExit(main())
