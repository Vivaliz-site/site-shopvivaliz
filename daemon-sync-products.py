#!/usr/bin/env python3
"""Keep the active Olist/Tiny catalog cache enriched with detail data."""

from __future__ import annotations

import argparse
import json
import os
import threading
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

API_BASE = "https://api.tiny.com.br/public-api/v3"
CACHE_PATH = Path("storage/products-cache-ativos.json")
_RATE_LOCK = threading.Lock()
_LAST_REQUEST = 0.0
_MIN_REQUEST_INTERVAL = 0.55


def public_product(item: dict[str, Any]) -> dict[str, Any]:
    """Keep only fields required by the public storefront cache."""
    prices = item.get("precos") if isinstance(item.get("precos"), dict) else {}
    stock = item.get("estoque") if isinstance(item.get("estoque"), dict) else {}
    category = item.get("categoria") if isinstance(item.get("categoria"), dict) else {}
    brand = item.get("marca") if isinstance(item.get("marca"), dict) else {}
    dimensions = item.get("dimensoes") if isinstance(item.get("dimensoes"), dict) else {}
    attachments = []
    for attachment in item.get("anexos", []) if isinstance(item.get("anexos"), list) else []:
        if isinstance(attachment, dict) and str(attachment.get("url", "")).startswith("https://"):
            attachments.append({"url": str(attachment["url"])})
    quantity = max(0, int(item.get("estoque_disponivel") or stock.get("quantidade") or 0))
    kit_composition = []
    for component in item.get("kit", []) if isinstance(item.get("kit"), list) else []:
        if not isinstance(component, dict):
            continue
        produto = component.get("produto") if isinstance(component.get("produto"), dict) else {}
        sku = str(produto.get("sku") or "").strip()
        qty = int(component.get("quantidade") or 0)
        if sku and qty > 0:
            kit_composition.append({"sku": sku, "quantidade": qty})
    return {
        "id": item.get("id"),
        "sku": str(item.get("sku") or item.get("codigo") or "").strip(),
        "tipo": str(item.get("tipo") or "P"),
        "kit": kit_composition,
        "descricao": str(item.get("descricao") or item.get("nome") or "").strip(),
        "descricaoComplementar": str(item.get("descricaoComplementar") or item.get("descricao_complementar") or "").strip(),
        "situacao": str(item.get("situacao") or "A"),
        "unidade": str(item.get("unidade") or ""),
        "gtin": str(item.get("gtin") or ""),
        "precos": {
            "preco": float(prices.get("preco") or prices.get("preco_venda") or item.get("preco") or 0),
            "precoPromocional": float(prices.get("precoPromocional") or 0),
        },
        "estoque": {"quantidade": quantity},
        "estoque_disponivel": quantity,
        "categoria": {
            "nome": str(category.get("nome") or ""),
            "caminhoCompleto": str(category.get("caminhoCompleto") or ""),
        },
        "marca": {"nome": str(brand.get("nome") or "")},
        "dimensoes": {
            "largura": float(dimensions.get("largura") or 0),
            "altura": float(dimensions.get("altura") or 0),
            "comprimento": float(dimensions.get("comprimento") or 0),
            "pesoLiquido": float(dimensions.get("pesoLiquido") or dimensions.get("peso_liquido") or item.get("peso") or 0),
        },
        "imagem_principal_url": str(item.get("imagem_principal_url") or ""),
        "anexos": attachments,
        "_detail_synced_at": str(item.get("_detail_synced_at") or datetime.now(timezone.utc).isoformat()),
    }


def throttle() -> None:
    global _LAST_REQUEST
    with _RATE_LOCK:
        delay = _MIN_REQUEST_INTERVAL - (time.monotonic() - _LAST_REQUEST)
        if delay > 0:
            time.sleep(delay)
        _LAST_REQUEST = time.monotonic()


def get_token() -> str | None:
    token = os.getenv("OLIST_ACCESS_TOKEN") or os.getenv("TINY_ACCESS_TOKEN")
    if token:
        return token.strip()
    env_file = Path(".env")
    if not env_file.is_file():
        return None
    for raw_line in env_file.read_text(encoding="utf-8-sig").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        if key.strip() in {"OLIST_ACCESS_TOKEN", "TINY_ACCESS_TOKEN"}:
            return value.strip().strip('"').strip("'") or None
    return None


def api_get(path: str, token: str, timeout: int = 30, attempts: int = 5) -> dict[str, Any]:
    last_error: Exception | None = None
    for attempt in range(attempts):
        throttle()
        request = urllib.request.Request(
            f"{API_BASE}/{path.lstrip('/')}",
            headers={"Authorization": f"Bearer {token}", "Accept": "application/json", "User-Agent": "ShopVivaliz-CatalogSync/2.0"},
        )
        try:
            with urllib.request.urlopen(request, timeout=timeout) as response:
                payload = json.loads(response.read().decode("utf-8"))
                return payload if isinstance(payload, dict) else {}
        except urllib.error.HTTPError as exc:
            last_error = exc
            if exc.code not in {429, 500, 502, 503, 504}:
                break
            if attempt + 1 < attempts:
                retry_after = exc.headers.get("Retry-After", "") if exc.headers else ""
                wait = int(retry_after) if retry_after.isdigit() else min(30, 5 * (attempt + 1))
                time.sleep(max(2, wait))
        except (urllib.error.URLError, TimeoutError, json.JSONDecodeError) as exc:
            last_error = exc
            if attempt + 1 < attempts:
                time.sleep(min(15, 2 * (attempt + 1)))
    raise RuntimeError(f"API request failed for {path}: {last_error}")


def fetch_products_active(token: str) -> list[dict[str, Any]]:
    products: list[dict[str, Any]] = []
    offset = 0
    while True:
        data = api_get(f"produtos?limit=100&offset={offset}", token)
        items = data.get("itens")
        if not isinstance(items, list) or not items:
            break
        products.extend(item for item in items if isinstance(item, dict) and item.get("situacao") == "A")
        print(f"[+] Offset {offset}: {len(items)} recebidos; {len(products)} ativos acumulados")
        if len(items) < 100:
            break
        offset += 100
    return products


def load_previous_cache() -> dict[str, dict[str, Any]]:
    try:
        payload = json.loads(CACHE_PATH.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}
    items = payload.get("itens", []) if isinstance(payload, dict) else []
    return {str(item.get("id")): item for item in items if isinstance(item, dict) and item.get("id") is not None}


def enrich_products(summaries: list[dict[str, Any]], token: str, workers: int = 4) -> tuple[list[dict[str, Any]], int]:
    previous = load_previous_cache()
    enriched: dict[str, dict[str, Any]] = {}
    failures = 0

    def fetch(summary: dict[str, Any]) -> tuple[str, dict[str, Any]]:
        product_id = str(summary.get("id", ""))
        if not product_id:
            return product_id, summary
        detail = api_get(f"produtos/{product_id}", token)
        merged = dict(summary)
        merged.update(detail)
        stock = merged.get("estoque") if isinstance(merged.get("estoque"), dict) else {}
        quantity = max(0, int(stock.get("quantidade") or 0))

        # O endpoint /estoque/{id} calcula "disponivel" no lado da Tiny
        # (saldo - reservado, considerando composicao de kits automaticamente
        # e depositos que devem ser desconsiderados) -- mais confiavel que
        # estoque.quantidade do /produtos/{id}, que para kits fica sempre 0
        # ou desatualizado. Usa disponivel quando o endpoint responde; cai
        # para estoque.quantidade se a chamada falhar (produto raramente
        # controla estoque, ou instabilidade pontual da API).
        try:
            stock_detail = api_get(f"estoque/{product_id}", token, attempts=2)
            if "disponivel" in stock_detail:
                quantity = max(0, int(stock_detail.get("disponivel") or 0))
        except RuntimeError:
            pass

        merged["estoque_disponivel"] = quantity
        merged["_detail_synced_at"] = datetime.now(timezone.utc).isoformat()
        return product_id, public_product(merged)

    with ThreadPoolExecutor(max_workers=max(1, min(workers, 8))) as executor:
        future_map = {executor.submit(fetch, summary): summary for summary in summaries}
        for index, future in enumerate(as_completed(future_map), 1):
            summary = future_map[future]
            product_id = str(summary.get("id", ""))
            try:
                key, product = future.result()
                enriched[key] = product
            except Exception as exc:
                failures += 1
                cached = previous.get(product_id)
                enriched[product_id] = public_product(cached if isinstance(cached, dict) else summary)
                print(f"[!] Detalhe {product_id} falhou; cache anterior preservado ({type(exc).__name__})")
            if index % 25 == 0 or index == len(summaries):
                print(f"[+] Detalhes processados: {index}/{len(summaries)}; falhas={failures}")

    ordered = [enriched[str(item.get("id", ""))] for item in summaries if str(item.get("id", "")) in enriched]
    return ordered, failures


def apply_kit_stock(products: list[dict[str, Any]]) -> int:
    """Produtos tipo kit (tipo == "K") nao tem estoque proprio confiavel na
    Tiny -- o campo estoque_disponivel para eles costuma ficar zerado ou
    desatualizado, porque a disponibilidade real depende da composicao
    (quantidade de cada componente em estoque). Recalcula estoque_disponivel
    dos kits como o minimo, entre os componentes, de estoque_do_componente
    dividido pela quantidade exigida por kit.
    """
    stock_by_sku = {
        str(item.get("sku") or ""): int(item.get("estoque_disponivel") or 0)
        for item in products
        if item.get("sku")
    }
    updated = 0
    for item in products:
        if item.get("tipo") != "K":
            continue
        composition = item.get("kit") or []
        if not composition:
            continue
        possible = [
            stock_by_sku.get(str(component.get("sku") or ""), 0) // max(1, int(component.get("quantidade") or 1))
            for component in composition
        ]
        kit_stock = max(0, min(possible)) if possible else 0
        if item.get("estoque_disponivel") != kit_stock:
            item["estoque_disponivel"] = kit_stock
            item["estoque"] = {"quantidade": kit_stock}
            updated += 1
    return updated


def save_products(products: list[dict[str, Any]]) -> Path:
    payload = {"total": len(products), "timestamp": datetime.now(timezone.utc).isoformat(), "itens": products}
    CACHE_PATH.parent.mkdir(parents=True, exist_ok=True)
    temporary = CACHE_PATH.with_suffix(".json.tmp")
    temporary.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    temporary.replace(CACHE_PATH)
    return CACHE_PATH


def sync_once(workers: int) -> bool:
    token = get_token()
    if not token:
        print("[!] OLIST_ACCESS_TOKEN/TINY_ACCESS_TOKEN não configurado")
        return False
    summaries = fetch_products_active(token)
    if not summaries:
        print("[!] A API não retornou produtos ativos; cache existente preservado")
        return False
    products, failures = enrich_products(summaries, token, workers)
    if not products:
        print("[!] Nenhum detalhe utilizável; cache existente preservado")
        return False
    kits_updated = apply_kit_stock(products)
    if kits_updated:
        print(f"[+] Estoque recalculado pela composicao em {kits_updated} produtos tipo kit")
    output = save_products(products)
    with_stock = sum(1 for item in products if int(item.get("estoque_disponivel") or 0) > 0)
    with_images = sum(1 for item in products if isinstance(item.get("anexos"), list) and item["anexos"])
    print(f"[+] Cache atômico salvo: {len(products)} produtos, {with_stock} com estoque, {with_images} com imagem, {failures} falhas em {output}")
    return failures < len(summaries)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--once", action="store_true", help="Executa uma sincronização e encerra")
    parser.add_argument("--interval", type=int, default=300, help="Intervalo do daemon em segundos")
    parser.add_argument("--workers", type=int, default=4, help="Concorrência para detalhes (1-8)")
    args = parser.parse_args()
    while True:
        started = time.monotonic()
        try:
            ok = sync_once(args.workers)
        except KeyboardInterrupt:
            return 130
        except Exception as exc:
            print(f"[!] Sincronização falhou sem substituir o cache: {type(exc).__name__}: {exc}")
            ok = False
        if args.once:
            return 0 if ok else 1
        elapsed = time.monotonic() - started
        time.sleep(max(5, args.interval - int(elapsed)))


if __name__ == "__main__":
    raise SystemExit(main())
