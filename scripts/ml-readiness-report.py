#!/usr/bin/env python3
"""
Relatorio seguro de prontidao Mercado Livre.

Nao publica anuncios, nao altera precos e nao depende de tokens validos
para gerar um diagnostico local do ambiente.
"""

from __future__ import annotations

import argparse
import json
import os
from datetime import datetime
from pathlib import Path


REQUIRED_ENV = [
    "ML_CLIENT_ID",
    "ML_CLIENT_SECRET",
    "ML_REDIRECT_URI",
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


def read_json(path: Path) -> dict:
    if not path.is_file():
        return {}
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        return data if isinstance(data, dict) else {}
    except Exception:
        return {}


def read_catalog_count() -> int:
    catalog = Path("api/catalog/fallback-products.json")
    if not catalog.is_file():
        return 0
    try:
        data = json.loads(catalog.read_text(encoding="utf-8"))
        return len(data) if isinstance(data, list) else 0
    except Exception:
        return 0


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

    token_path = Path("storage/private/ml-tokens.json")
    token_data = read_json(token_path)
    expires_at_ms = int(token_data.get("expires_at_ms") or 0)
    now_ms = int(datetime.now().timestamp() * 1000)

    report = {
        "generated_at": datetime.now().isoformat(),
        "environment": env_status(),
        "tokens": {
            "file_present": token_path.is_file(),
            "has_access_token": bool(token_data.get("access_token")),
            "has_refresh_token": bool(token_data.get("refresh_token")),
            "user_id": token_data.get("user_id"),
            "expires_at_ms": expires_at_ms or None,
            "expires_in_seconds": max(0, int((expires_at_ms - now_ms) / 1000)) if expires_at_ms else None,
        },
        "catalog": {
            "products_count": read_catalog_count(),
        },
        "safety": {
            "mutates_data": False,
            "price_impact": False,
            "publishes_products": False,
        },
    }

    report["summary"] = {
        "ready_for_authenticated_sync": report["environment"]["configured"]
        and report["tokens"]["has_access_token"]
        and report["tokens"]["has_refresh_token"],
        "ready_for_catalog_preview": True,
        "ready_for_price_change": False,
    }
    return report


def main() -> int:
    parser = argparse.ArgumentParser(description="Relatorio de prontidao Mercado Livre")
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
        print("[MERCADO LIVRE] Relatorio de prontidao")
        print(f"  Configurado: {'sim' if env['configured'] else 'nao'}")
        print(f"  Faltando: {', '.join(env['missing']) if env['missing'] else 'nenhum'}")
        print(f"  Token salvo: {'sim' if report['tokens']['file_present'] else 'nao'}")
        print(f"  Catalogo local: {report['catalog']['products_count']}")
        print(f"  Sincronizacao autenticada pronta: {'sim' if report['summary']['ready_for_authenticated_sync'] else 'nao'}")
        if args.output:
            print(f"  Relatorio salvo em: {args.output}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
