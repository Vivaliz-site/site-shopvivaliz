#!/usr/bin/env python3
"""
Relatorio seguro de prontidao Shopee.

Nao publica produtos, nao altera preco e nao depende de credenciais validas
para gerar um diagnostico local do pipeline.
"""

from __future__ import annotations

import argparse
import json
import os
from datetime import datetime
from pathlib import Path

from integrations.shopee import ShopeeIntegration


REQUIRED_ENV = [
    "SHOPEE_PARTNER_ID",
    "SHOPEE_PARTNER_KEY",
    "SHOPEE_SHOP_ID",
    "SHOPEE_ACCESS_TOKEN",
]


def load_env_file(path: Path) -> None:
    if not path.is_file():
        return
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip().strip('"\'')
        if key and key not in os.environ:
            os.environ[key] = value


def env_status() -> dict:
    values = {key: bool(os.environ.get(key, "").strip()) for key in REQUIRED_ENV}
    missing = [key for key, ok in values.items() if not ok]
    return {
        "required": REQUIRED_ENV,
        "present": [key for key, ok in values.items() if ok],
        "missing": missing,
        "configured": not missing,
    }


def build_report() -> dict:
    load_env_file(Path(".env"))

    integration = ShopeeIntegration()
    api_products = integration._load_products_from_api()
    performance_products = integration._load_products_from_performance()

    report = {
        "generated_at": datetime.now().isoformat(),
        "environment": env_status(),
        "integration": {
            "api_base_url": integration.api_base,
            "products_api_url": integration.products_api_url or "",
            "shop_id_present": bool(integration.shop_id),
            "access_token_present": bool(integration.access_token),
        },
        "catalog": {
            "api_products_count": len(api_products),
            "performance_products_count": len(performance_products),
            "sample_api_products": api_products[:3],
            "sample_performance_products": performance_products[:3],
        },
        "safety": {
            "mutates_data": False,
            "price_impact": False,
            "publishes_products": False,
        },
    }

    report["summary"] = {
        "ready_for_authenticated_sync": report["environment"]["configured"]
        and report["integration"]["shop_id_present"]
        and report["integration"]["access_token_present"],
        "ready_for_catalog_preview": True,
        "ready_for_price_change": False,
    }
    return report


def main() -> int:
    parser = argparse.ArgumentParser(description="Relatorio de prontidao Shopee")
    parser.add_argument("--json", action="store_true", help="Imprime JSON puro")
    parser.add_argument("--output", default="", help="Arquivo para salvar o relatorio JSON")
    args = parser.parse_args()

    report = build_report()
    text = json.dumps(report, indent=2, ensure_ascii=False)

    if args.output:
        output_path = Path(args.output)
        output_path.parent.mkdir(parents=True, exist_ok=True)
        output_path.write_text(text + "\n", encoding="utf-8")

    if args.json:
        print(text)
    else:
        env = report["environment"]
        print("[SHOPEE] Relatorio de prontidao")
        print(f"  Configurado: {'sim' if env['configured'] else 'nao'}")
        print(f"  Faltando: {', '.join(env['missing']) if env['missing'] else 'nenhum'}")
        print(f"  API produtos: {report['catalog']['api_products_count']}")
        print(f"  Produtos de performance: {report['catalog']['performance_products_count']}")
        print(f"  Sincronizacao autenticada pronta: {'sim' if report['summary']['ready_for_authenticated_sync'] else 'nao'}")
        if args.output:
            print(f"  Relatorio salvo em: {args.output}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
