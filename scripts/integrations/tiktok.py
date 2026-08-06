#!/usr/bin/env python3
"""Atualização real de catálogo no TikTok Shop; falhas nunca viram sucesso."""
from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from utils.tiktok_client import TikTokClient  # noqa: E402


def load_products() -> list[dict]:
    source = os.environ.get("SHOPVIVALIZ_PRODUCTS_JSON", "")
    if not source:
        raise RuntimeError("SHOPVIVALIZ_PRODUCTS_JSON não configurado")
    data = json.loads(Path(source).read_text(encoding="utf-8"))
    products = data.get("products") if isinstance(data, dict) else data
    if not isinstance(products, list):
        raise RuntimeError("Fonte de produtos inválida")
    return products


def update_all() -> int:
    client = TikTokClient()
    products = load_products()
    success = 0
    failures = 0
    for product in products:
        sku = str(product.get("sku") or "").strip()
        if not sku:
            failures += 1
            print("[ERRO] Produto sem SKU")
            continue
        try:
            remote = client.find_product_by_sku(sku)
            product_id = str(remote.get("id") or remote.get("product_id") or "")
            if not product_id:
                raise RuntimeError("TikTok não retornou product_id")
            client.update_product(
                product_id,
                title=product.get("title") or product.get("name"),
                description=product.get("description"),
                image_files=product.get("image_files") or None,
            )
            print(f"[OK] SKU {sku} enviado ao TikTok Shop")
            success += 1
        except Exception as exc:
            print(f"[ERRO] SKU {sku}: {exc}")
            failures += 1
    print(f"Resultado real: {success} enviados, {failures} falharam")
    return 0 if failures == 0 else 1


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--update-all", action="store_true")
    args = parser.parse_args()
    if not args.update_all:
        raise SystemExit("Use --update-all")
    raise SystemExit(update_all())
