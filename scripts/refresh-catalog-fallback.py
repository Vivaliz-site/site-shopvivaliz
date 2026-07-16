#!/usr/bin/env python3
"""Atualiza api/catalog/fallback-products.json com preco/estoque reais.

Le o CSV gerado por export-olist-images-csv.py (logs/olist-images-export.csv)
e faz merge por olist_id/sku no catalogo estatico usado pelo storefront
(catalogo.php, produto.php, index.php), preservando os campos de auditoria
de imagem (image_url, images_count, status) ja existentes.
"""

import csv
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
CSV_PATH = Path(sys.argv[1]) if len(sys.argv) > 1 else ROOT / "logs" / "olist-images-export.csv"
CATALOG_PATH = Path(sys.argv[2]) if len(sys.argv) > 2 else ROOT / "api" / "catalog" / "fallback-products.json"


def to_float(value) -> float:
    try:
        return float(str(value).replace(",", "."))
    except (TypeError, ValueError):
        return 0.0


def to_int(value) -> int:
    try:
        return int(float(str(value).replace(",", ".")))
    except (TypeError, ValueError):
        return 0


def load_csv_rows(path: Path) -> dict:
    if not path.is_file():
        print(f"ERRO: CSV nao encontrado em {path}", file=sys.stderr)
        sys.exit(1)
    by_id = {}
    by_sku = {}
    with path.open(encoding="utf-8-sig", newline="") as csv_file:
        for row in csv.DictReader(csv_file):
            olist_id = str(row.get("olist_id") or "").strip()
            sku = str(row.get("sku") or "").strip()
            if olist_id:
                by_id[olist_id] = row
            if sku:
                by_sku[sku] = row
    return {"by_id": by_id, "by_sku": by_sku}


def main() -> None:
    index = load_csv_rows(CSV_PATH)
    catalog = json.loads(CATALOG_PATH.read_text(encoding="utf-8"))
    if not isinstance(catalog, list):
        print(f"ERRO: {CATALOG_PATH} nao e uma lista de produtos", file=sys.stderr)
        sys.exit(1)

    updated = 0
    for product in catalog:
        olist_id = str(product.get("olist_product_id") or product.get("id") or "").strip()
        sku = str(product.get("sku") or "").strip()
        row = index["by_id"].get(olist_id) or index["by_sku"].get(sku)
        if not row:
            continue

        price = to_float(row.get("preco_venda"))
        stock = to_int(row.get("estoque_atual"))
        if price > 0:
            product["price"] = price
            updated += 1
        if stock > 0 or "estoque_atual" in row:
            product["stock"] = stock

    CATALOG_PATH.write_text(
        json.dumps(catalog, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    print(f"Produtos no catalogo: {len(catalog)}. Precos atualizados: {updated}.")


if __name__ == "__main__":
    main()
