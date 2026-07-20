#!/usr/bin/env python3
"""Generate a Tiny/Olist sales ranking by SKU for ads planning.

The report uses real Tiny API data and local catalog price/cost data. It does
not mutate ERP data.
"""

from __future__ import annotations

import csv
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from collections import defaultdict
from datetime import date, timedelta
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
API_BASE = "https://api.tiny.com.br/public-api/v3"
TOKEN_URL = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token"
OUT_DIR = ROOT / "storage" / "reports"


def load_env() -> None:
    env_path = ROOT / ".env"
    if not env_path.is_file():
        return
    for raw in env_path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        os.environ.setdefault(key.strip(), value.strip().strip("\"'"))


def post_form(url: str, fields: dict[str, str]) -> dict:
    data = urllib.parse.urlencode(fields).encode()
    req = urllib.request.Request(url, data=data, headers={"Content-Type": "application/x-www-form-urlencoded"})
    with urllib.request.urlopen(req, timeout=30) as resp:
        return json.loads(resp.read())


def get_json(path: str, token: str, retries: int = 4) -> tuple[int, dict | list | None, str]:
    req = urllib.request.Request(
        API_BASE + path,
        headers={
            "Authorization": "Bearer " + token,
            "Accept": "application/json",
            "User-Agent": "ShopVivaliz/3.0",
        },
    )
    for attempt in range(retries):
        try:
            with urllib.request.urlopen(req, timeout=30) as resp:
                raw = resp.read().decode(errors="replace")
                return resp.status, json.loads(raw) if raw else None, raw
        except urllib.error.HTTPError as exc:
            raw = exc.read().decode(errors="replace")
            if exc.code == 429 and attempt < retries - 1:
                time.sleep(3 * (attempt + 1))
                continue
            return exc.code, None, raw
    return 0, None, ""


def resolve_token() -> str:
    static = os.getenv("TINY_ACCESS_TOKEN") or os.getenv("OLIST_ACCESS_TOKEN") or ""
    if static:
        return static
    client_id = os.getenv("TINY_CLIENT_ID") or os.getenv("OLIST_CLIENT_ID") or ""
    client_secret = os.getenv("TINY_CLIENT_SECRET") or os.getenv("OLIST_CLIENT_SECRET") or ""
    refresh = os.getenv("TINY_REFRESH_TOKEN") or os.getenv("OLIST_REFRESH_TOKEN") or ""
    if not (client_id and client_secret and refresh):
        raise RuntimeError("Tiny OAuth credentials missing")
    data = post_form(TOKEN_URL, {
        "grant_type": "refresh_token",
        "client_id": client_id,
        "client_secret": client_secret,
        "refresh_token": refresh,
    })
    token = data.get("access_token") or ""
    if not token:
        raise RuntimeError("Tiny OAuth refresh did not return access_token")
    return token


def load_catalog() -> dict[str, dict]:
    catalog_paths = [ROOT / "storage" / "products-cache.json", ROOT / "api" / "catalog" / "fallback-products.json"]
    by_id: dict[str, dict] = {}
    by_sku: dict[str, dict] = {}
    for path in catalog_paths:
        if not path.is_file():
            continue
        data = json.loads(path.read_text(encoding="utf-8", errors="ignore"))
        rows = data.get("itens") if isinstance(data, dict) else data
        if not isinstance(rows, list):
            continue
        for row in rows:
            if not isinstance(row, dict):
                continue
            pid = str(row.get("id") or row.get("olist_product_id") or "").strip()
            sku = str(row.get("sku") or "").strip().upper()
            has_cost = product_cost(row) > 0
            if pid:
                previous = by_id.get(pid)
                if previous is None or (has_cost and product_cost(previous) <= 0):
                    by_id[pid] = row
            if sku:
                previous = by_sku.get(sku)
                if previous is None or (has_cost and product_cost(previous) <= 0):
                    by_sku[sku] = row
    return {"by_id": by_id, "by_sku": by_sku}


def product_cost(row: dict) -> float:
    precos = row.get("precos") if isinstance(row.get("precos"), dict) else {}
    for key in ("precoCustoMedio", "precoCusto", "cost"):
        value = precos.get(key) if key in precos else row.get(key)
        try:
            number = float(value or 0)
        except (TypeError, ValueError):
            number = 0
        if number > 0:
            return number
    return 0.0


def product_stock(row: dict) -> float:
    for key in ("stock", "estoque_disponivel", "disponivel"):
        try:
            number = float(row.get(key) or 0)
        except (TypeError, ValueError):
            number = 0
        if number > 0:
            return number
    estoque = row.get("estoque") if isinstance(row.get("estoque"), dict) else {}
    for key in ("disponivel", "quantidade", "saldo"):
        try:
            number = float(estoque.get(key) or 0)
        except (TypeError, ValueError):
            number = 0
        if number > 0:
            return number
    return 0.0


def iter_order_ids(token: str, days: int, max_pages: int) -> list[str]:
    end = date.today()
    start = end - timedelta(days=days)
    ids: list[str] = []
    seen = set()
    limit = 100
    for page in range(1, max_pages + 1):
        offset = (page - 1) * limit
        query = urllib.parse.urlencode({
            "offset": offset,
            "limit": limit,
            "dataInicial": start.isoformat(),
            "dataFinal": end.isoformat(),
        })
        status, data, raw = get_json("/pedidos?" + query, token)
        if status != 200:
            print(f"WARN list page {page} HTTP {status}: {raw[:180]}", file=sys.stderr)
            break
        rows = []
        if isinstance(data, dict):
            rows = data.get("itens") or data.get("items") or data.get("pedidos") or []
        if not isinstance(rows, list) or not rows:
            break
        added = 0
        for row in rows:
            if not isinstance(row, dict):
                continue
            oid = str(row.get("id") or row.get("idPedido") or row.get("pedido", {}).get("id") or "").strip()
            if oid and oid not in seen:
                seen.add(oid)
                ids.append(oid)
                added += 1
        if added == 0:
            break
        if len(rows) < limit:
            break
        time.sleep(0.2)
    return ids


def extract_order_items(order: dict) -> list[dict]:
    rows = order.get("itens") or order.get("items") or []
    if not isinstance(rows, list):
        return []
    out = []
    for item in rows:
        if not isinstance(item, dict):
            continue
        product = item.get("produto") if isinstance(item.get("produto"), dict) else {}
        out.append({
            "product_id": str(product.get("id") or item.get("idProduto") or "").strip(),
            "sku": str(product.get("sku") or item.get("sku") or "").strip(),
            "name": str(product.get("descricao") or product.get("nome") or item.get("descricao") or "").strip(),
            "quantity": float(item.get("quantidade") or item.get("qtde") or 0),
            "unit_price": float(item.get("valorUnitario") or item.get("preco") or 0),
        })
    return out


def main() -> int:
    load_env()
    days = int(os.getenv("TINY_SALES_REPORT_DAYS", "30"))
    max_pages = int(os.getenv("TINY_SALES_REPORT_MAX_PAGES", "12"))
    token = resolve_token()
    catalog = load_catalog()
    order_ids = iter_order_ids(token, days, max_pages)
    if not order_ids:
        print("NO_ORDERS_FOUND")
        return 1

    totals = defaultdict(lambda: {
        "sku": "",
        "product_id": "",
        "name": "",
        "quantity": 0.0,
        "revenue": 0.0,
        "estimated_cost": 0.0,
        "stock": 0.0,
        "orders": 0,
    })

    fetched = 0
    for oid in order_ids:
        status, data, raw = get_json("/pedidos/" + urllib.parse.quote(oid), token)
        if status != 200 or not isinstance(data, dict):
            print(f"WARN order {oid} HTTP {status}: {raw[:160]}", file=sys.stderr)
            continue
        order = data.get("pedido") if isinstance(data.get("pedido"), dict) else data
        for item in extract_order_items(order):
            key = item["product_id"] or item["sku"].upper() or item["name"]
            row = totals[key]
            row["sku"] = item["sku"] or row["sku"]
            row["product_id"] = item["product_id"] or row["product_id"]
            row["name"] = item["name"] or row["name"]
            row["quantity"] += item["quantity"]
            row["revenue"] += item["quantity"] * item["unit_price"]
            row["orders"] += 1

            catalog_row = {}
            if item["product_id"]:
                catalog_row = catalog["by_id"].get(item["product_id"], {})
            if not catalog_row and item["sku"]:
                catalog_row = catalog["by_sku"].get(item["sku"].upper(), {})
            unit_cost = product_cost(catalog_row) if catalog_row else 0.0
            row["estimated_cost"] += unit_cost * item["quantity"]
            row["stock"] = max(float(row["stock"]), product_stock(catalog_row) if catalog_row else 0.0)
        fetched += 1
        if fetched % 40 == 0:
            print(f"fetched_orders={fetched}/{len(order_ids)}", file=sys.stderr)
        time.sleep(0.12)

    rows = []
    for row in totals.values():
        revenue = float(row["revenue"])
        estimated_cost = float(row["estimated_cost"])
        gross_profit = revenue - estimated_cost if estimated_cost > 0 else 0.0
        margin_percent = (gross_profit / revenue * 100) if revenue > 0 and estimated_cost > 0 else 0.0
        row["gross_profit"] = round(gross_profit, 2)
        row["margin_percent"] = round(margin_percent, 2)
        row["revenue"] = round(revenue, 2)
        row["estimated_cost"] = round(estimated_cost, 2)
        row["quantity"] = round(float(row["quantity"]), 2)
        row["stock"] = round(float(row["stock"]), 2)
        rows.append(row)

    rows.sort(key=lambda r: (r["quantity"], r["revenue"]), reverse=True)
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    stamp = time.strftime("%Y%m%d-%H%M%S")
    csv_path = OUT_DIR / f"tiny-sales-ranking-{stamp}.csv"
    json_path = OUT_DIR / f"tiny-sales-ranking-{stamp}.json"
    fields = ["sku", "product_id", "name", "quantity", "orders", "revenue", "estimated_cost", "gross_profit", "margin_percent", "stock"]
    with csv_path.open("w", newline="", encoding="utf-8") as fh:
        writer = csv.DictWriter(fh, fieldnames=fields)
        writer.writeheader()
        writer.writerows(rows)
    json_path.write_text(json.dumps({
        "ok": True,
        "days": days,
        "orders_found": len(order_ids),
        "orders_fetched": fetched,
        "rows": rows,
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
    }, ensure_ascii=False, indent=2), encoding="utf-8")

    print("REPORT_READY")
    print("csv=" + str(csv_path))
    print("json=" + str(json_path))
    print("orders_found=" + str(len(order_ids)))
    print("orders_fetched=" + str(fetched))
    print("top10=" + json.dumps(rows[:10], ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
