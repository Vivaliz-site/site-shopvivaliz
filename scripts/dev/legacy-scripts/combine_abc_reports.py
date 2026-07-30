#!/usr/bin/env python3
"""Combine Tiny/Olist ABC reports with catalog cost data for ads prioritization."""

from __future__ import annotations

import json
import re
import unicodedata
from pathlib import Path

import pandas as pd


ROOT = Path(__file__).resolve().parents[1]
REPORT_DIR = ROOT / "storage" / "reports"
VALUE_FILE = REPORT_DIR / "curva-abc_19-07-2026-10-01-30.xlsx"
QUANTITY_FILE = REPORT_DIR / "curva-abc_19-07-2026-10-00-47.xlsx"


def norm_sku(value: object) -> str:
    text = str(value or "").strip()
    text = unicodedata.normalize("NFKD", text).encode("ascii", "ignore").decode("ascii")
    return re.sub(r"\s+", "", text).upper()


def parse_number(value: object) -> float:
    if value is None:
        return 0.0
    if isinstance(value, (int, float)):
        return float(value)
    text = str(value).strip().replace(".", "").replace(",", ".")
    try:
        return float(text)
    except ValueError:
        return 0.0


def load_abc(path: Path, source: str) -> list[dict]:
    df = pd.read_excel(path)
    rows = []
    for index, row in df.iterrows():
        sku = norm_sku(row.get("C�digo") or row.get("Código") or row.get("Codigo"))
        if not sku:
            continue
        rows.append({
            "source": source,
            "rank": index + 1,
            "sku": sku,
            "name": str(row.get("Produto") or "").strip(),
            "quantity": parse_number(row.get("Quantidade")),
            "revenue": parse_number(row.get("Valor")),
            "abc_class": str(row.get("Classifica��o") or row.get("Classificação") or "").strip(),
        })
    return rows


def load_catalog() -> dict[str, dict]:
    paths = [ROOT / "storage" / "products-cache.json", ROOT / "api" / "catalog" / "fallback-products.json"]
    catalog: dict[str, dict] = {}
    for path in paths:
        if not path.is_file():
            continue
        data = json.loads(path.read_text(encoding="utf-8", errors="ignore"))
        rows = data.get("itens") if isinstance(data, dict) else data
        if not isinstance(rows, list):
            continue
        for row in rows:
            if not isinstance(row, dict):
                continue
            sku = norm_sku(row.get("sku"))
            if not sku:
                continue
            previous = catalog.get(sku)
            if previous is None or unit_cost(row) > unit_cost(previous):
                catalog[sku] = row
    return catalog


def unit_cost(row: dict) -> float:
    prices = row.get("precos") if isinstance(row.get("precos"), dict) else {}
    for key in ("precoCustoMedio", "precoCusto", "cost"):
        value = prices.get(key) if key in prices else row.get(key)
        number = parse_number(value)
        if number > 0:
            return number
    return 0.0


def stock(row: dict) -> float:
    for key in ("stock", "disponivel"):
        number = parse_number(row.get(key))
        if number > 0:
            return number
    prices = row.get("estoque") if isinstance(row.get("estoque"), dict) else {}
    for key in ("disponivel", "quantidade", "saldo"):
        number = parse_number(prices.get(key))
        if number > 0:
            return number
    return 0.0


def category_hint(name: str) -> str:
    lowered = name.lower()
    if "carrinho" in lowered or "ferramenta" in lowered:
        return "ferramentas"
    if "rodizio" in lowered or "rod�zio" in lowered or "rodízio" in lowered:
        return "rodizios"
    if "assento" in lowered or "sanit" in lowered:
        return "assentos"
    if "vaso" in lowered or "cachepot" in lowered or "floreira" in lowered:
        return "vasos"
    if "comedouro" in lowered:
        return "pet"
    if "vedante" in lowered or "rodo" in lowered:
        return "vedantes"
    return "outros"


def main() -> int:
    rows = load_abc(QUANTITY_FILE, "quantity") + load_abc(VALUE_FILE, "value")
    catalog = load_catalog()
    merged: dict[str, dict] = {}
    for row in rows:
        current = merged.setdefault(row["sku"], {
            "sku": row["sku"],
            "name": row["name"],
            "quantity": 0.0,
            "revenue": 0.0,
            "quantity_rank": None,
            "value_rank": None,
            "abc_class": row["abc_class"],
        })
        if row["quantity"] > current["quantity"]:
            current["quantity"] = row["quantity"]
        if row["revenue"] > current["revenue"]:
            current["revenue"] = row["revenue"]
        if len(row["name"]) > len(current["name"]):
            current["name"] = row["name"]
        if row["source"] == "quantity":
            current["quantity_rank"] = row["rank"]
        if row["source"] == "value":
            current["value_rank"] = row["rank"]

    final = []
    for item in merged.values():
        cat = catalog.get(item["sku"], {})
        cost = unit_cost(cat) if cat else 0.0
        stk = stock(cat) if cat else 0.0
        avg_price = item["revenue"] / item["quantity"] if item["quantity"] else 0.0
        gross_profit = item["revenue"] - (cost * item["quantity"]) if cost > 0 else 0.0
        gross_margin = gross_profit / item["revenue"] * 100 if item["revenue"] > 0 and cost > 0 else 0.0
        roi10_max_cpa = gross_profit / 10 if gross_profit > 0 else 0.0
        category = category_hint(item["name"])
        item.update({
            "avg_price": round(avg_price, 2),
            "unit_cost": round(cost, 2),
            "stock": round(stk, 2),
            "gross_profit": round(gross_profit, 2),
            "gross_margin_percent": round(gross_margin, 2),
            "roi10_max_cpa_total_period": round(roi10_max_cpa, 2),
            "category": category,
            "ads_score": round(
                (item["revenue"] / 1000)
                + (item["quantity"] / 20)
                + (gross_margin / 10)
                + (2 if stk >= 10 else 0)
                - (3 if avg_price < 40 else 0),
                2,
            ),
        })
        final.append(item)

    final.sort(key=lambda item: item["ads_score"], reverse=True)
    out_json = REPORT_DIR / "abc-roi10-combined-20260719.json"
    out_csv = REPORT_DIR / "abc-roi10-combined-20260719.csv"
    pd.DataFrame(final).to_csv(out_csv, index=False, encoding="utf-8-sig")
    out_json.write_text(json.dumps(final, ensure_ascii=False, indent=2), encoding="utf-8")

    print("ABC_COMBINED_READY")
    print("json=" + str(out_json))
    print("csv=" + str(out_csv))
    print("top15=" + json.dumps(final[:15], ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
