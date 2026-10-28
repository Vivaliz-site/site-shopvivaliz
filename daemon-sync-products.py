#!/usr/bin/env python3
"""Keep the active Olist/Tiny catalog cache enriched with detail data."""

from __future__ import annotations

import argparse
import fcntl
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

from scripts.olist_pdv import discover_pdv, fetch_pdv_snapshot, pdv_product_prices

API_BASE = "https://api.tiny.com.br/public-api/v3"
CACHE_PATH = Path("storage/products-cache-ativos.json")
VIDEO_SOURCES_PATH = Path("storage/product-video-sources.json")
_RATE_LOCK = threading.Lock()
_LAST_REQUEST = 0.0
_MIN_REQUEST_INTERVAL = 1.10
DETAIL_CACHE_MAX_AGE_SECONDS = 2 * 60 * 60


def public_product(item: dict[str, Any]) -> dict[str, Any]:
    """Keep only fields required by the public storefront cache."""
    if item.get("price_source") != "olist_pdv" or int(item.get("pdv_list_id") or 0) < 1:
        raise ValueError("pdv_prices_required")
    prices = item.get("precos") if isinstance(item.get("precos"), dict) else {}
    if float(prices.get("preco") or 0) <= 0:
        raise ValueError("pdv_price_invalid")
    stock = item.get("estoque") if isinstance(item.get("estoque"), dict) else {}
    category = item.get("categoria") if isinstance(item.get("categoria"), dict) else {}
    brand = item.get("marca") if isinstance(item.get("marca"), dict) else {}
    dimensions = item.get("dimensoes") if isinstance(item.get("dimensoes"), dict) else {}
    seo = item.get("seo") if isinstance(item.get("seo"), dict) else {}
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
            # Only the explicitly PDV-derived fields are publishable.
            # Never fall back to registration preco/preco_venda.
            "preco": float(prices["preco"]),
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
            "pesoBruto": float(dimensions.get("pesoBruto") or dimensions.get("peso_bruto") or 0),
        },
        "anexos": attachments,
        "imagem_principal_url": str(item.get("imagem_principal_url") or ""),
        "seo_title": str(seo.get("titulo") or item.get("seo_title") or ""),
        "seo_description": str(seo.get("descricao") or item.get("seo_description") or ""),
        "keywords": seo.get("keywords") if isinstance(seo.get("keywords"), list) else (item.get("keywords", []) if isinstance(item.get("keywords"), list) else []),
        "slug": str(seo.get("slug") or item.get("slug") or ""),
        "video_url": str(seo.get("linkVideo") or item.get("video_url") or "").strip(),
        "sync_source": "tiny_v3",
        "price_source": "olist_pdv",
        "pdv_list_id": int(item["pdv_list_id"]),
        "_pdv_erp_price": item.get("_pdv_erp_price"),
        "_pdv_erp_promo": item.get("_pdv_erp_promo"),
        "_olist_updated_at": str(item.get("_olist_updated_at") or "").strip(),
        "_detail_synced_at": str(item.get("_detail_synced_at") or datetime.now(timezone.utc).isoformat()),
    }


def throttle() -> None:
    global _LAST_REQUEST
    with _RATE_LOCK:
        delay = _MIN_REQUEST_INTERVAL - (time.monotonic() - _LAST_REQUEST)
        if delay > 0:
            time.sleep(delay)
        _LAST_REQUEST = time.monotonic()


def token_from_env_file(env_file: Path) -> str | None:
    """Read the refreshed runtime token from the canonical deployment env file."""
    if not env_file.is_file():
        return None
    values: dict[str, str] = {}
    try:
        lines = env_file.read_text(encoding="utf-8-sig").splitlines()
    except OSError:
        return None
    for raw_line in lines:
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        if line.startswith("export "):
            line = line[7:].strip()
        key, value = line.split("=", 1)
        key = key.strip()
        if key not in {"OLIST_ACCESS_TOKEN", "TINY_ACCESS_TOKEN"}:
            continue
        values[key] = value.strip().strip('"').strip("'")
    for key in ("OLIST_ACCESS_TOKEN", "TINY_ACCESS_TOKEN"):
        token = values.get(key, "").strip()
        if token:
            return token.removeprefix("Bearer ").removeprefix("bearer ").strip()
    return None


def get_token() -> str | None:
    # The token renewer atomically updates current/.env (a shared deployment
    # symlink). Read that file on every sync cycle so a token captured in the
    # long-lived systemd process environment cannot shadow a refreshed token.
    token = token_from_env_file(Path(".env"))
    if token:
        return token
    for key in ("OLIST_ACCESS_TOKEN", "TINY_ACCESS_TOKEN"):
        value = os.getenv(key, "").strip()
        if value:
            return value.removeprefix("Bearer ").removeprefix("bearer ").strip()
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


def load_video_sources() -> dict[str, dict[str, Any]]:
    try:
        payload = json.loads(VIDEO_SOURCES_PATH.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}
    return payload if isinstance(payload, dict) else {}


def merge_video_sources(product: dict[str, Any], registry: dict[str, dict[str, Any]]) -> dict[str, Any]:
    merged = dict(product)
    product_id = str(merged.get("id") or merged.get("olist_product_id") or "").strip()
    sku = str(merged.get("sku") or "").strip()
    extra = registry.get(product_id) if product_id else None
    if not isinstance(extra, dict) and sku:
        extra = registry.get(f"sku:{sku}")
    if not isinstance(extra, dict):
        return merged
    direct = str(extra.get("direct_url") or "").strip()
    youtube = str(extra.get("youtube_url") or "").strip()
    if not str(merged.get("video_url") or "").strip() and direct:
        merged["video_url"] = direct
    if youtube:
        merged["youtube_url"] = youtube
    return merged


def load_previous_cache() -> dict[str, dict[str, Any]]:
    try:
        payload = json.loads(CACHE_PATH.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}
    items = payload.get("itens", []) if isinstance(payload, dict) else []
    return {str(item.get("id")): item for item in items if isinstance(item, dict) and item.get("id") is not None}



def detail_cache_is_fresh(cached: dict[str, Any], current_marker: str, now: datetime | None = None) -> bool:
    cached_marker = str(cached.get("_olist_updated_at") or "").strip()
    if not current_marker or cached_marker != current_marker:
        return False
    raw_synced = str(cached.get("_detail_synced_at") or "").strip()
    if not raw_synced:
        return False
    try:
        synced_at = datetime.fromisoformat(raw_synced.replace("Z", "+00:00"))
    except ValueError:
        return False
    if synced_at.tzinfo is None:
        synced_at = synced_at.replace(tzinfo=timezone.utc)
    current_time = now or datetime.now(timezone.utc)
    age = (current_time - synced_at.astimezone(timezone.utc)).total_seconds()
    return age <= DETAIL_CACHE_MAX_AGE_SECONDS


def enrich_products(summaries: list[dict[str, Any]], token: str, workers: int = 4) -> tuple[list[dict[str, Any]], int]:
    # This is a LIVE authenticated discovery; never infer a saved/historical ID.
    pdv_list = discover_pdv(api_get, token)
    pdv_snapshot = fetch_pdv_snapshot(api_get, token, pdv_list)
    previous = load_previous_cache()
    enriched: dict[str, dict[str, Any]] = {}
    failures = 0
    pending: list[dict[str, Any]] = []

    for summary in summaries:
        product_id = str(summary.get("id", ""))
        if not product_id:
            failures += 1
            continue
        cached = previous.get(product_id)
        current_marker = str(summary.get("dataAlteracao") or "").strip()
        if (isinstance(cached, dict)
                and cached.get("price_source") == "olist_pdv"
                and cached.get("_pdv_erp_price") is not None
                and detail_cache_is_fresh(cached, current_marker)):
            try:
                current_prices = pdv_product_prices(
                    api_get, token, int(product_id),
                    {"preco": cached["_pdv_erp_price"], "precoPromocional": cached.get("_pdv_erp_promo", 0)},
                    pdv_list,
                    pdv_snapshot,
                )
                refreshed = dict(cached)
                refreshed["precos"] = {
                    "preco": current_prices["preco"],
                    "precoPromocional": current_prices["precoPromocional"],
                }
                refreshed["pdv_list_id"] = current_prices["pdv_list_id"]
                refreshed["price_source"] = "olist_pdv"
                enriched[product_id] = refreshed
            except (TypeError, ValueError, RuntimeError) as exc:
                failures += 1
                print(f"[!] PDV indisponivel para ID {product_id}: {type(exc).__name__}; cache nao sera publicado")
            continue
        pending.append(summary)

    reused = len(summaries) - len(pending) - failures
    if reused:
        print(f"[+] Detalhes reaproveitados do cache: {reused}; pendentes={len(pending)}")

    def fetch(summary: dict[str, Any]) -> tuple[str, dict[str, Any]]:
        product_id = str(summary.get("id", ""))
        if not product_id:
            raise RuntimeError("product_id_missing")
        detail = api_get(f"produtos/{product_id}", token)
        merged = dict(summary)
        merged.update(detail)
        stock = merged.get("estoque") if isinstance(merged.get("estoque"), dict) else {}
        quantity = max(0, int(stock.get("quantidade") or 0))
        try:
            stock_detail = api_get(f"estoque/{product_id}", token, attempts=2)
            if "disponivel" in stock_detail:
                quantity = max(0, int(stock_detail.get("disponivel") or 0))
        except RuntimeError:
            pass

        raw_prices = detail.get("precos") if isinstance(detail.get("precos"), dict) else {}
        pdv = pdv_product_prices(api_get, token, int(product_id), raw_prices, pdv_list, pdv_snapshot)
        merged["_pdv_erp_price"] = raw_prices.get("preco", raw_prices.get("preco_venda"))
        merged["_pdv_erp_promo"] = raw_prices.get("precoPromocional", raw_prices.get("preco_promocional", 0))
        merged["precos"] = {
            "preco": pdv["preco"],
            "precoPromocional": pdv["precoPromocional"],
        }
        merged["price_source"] = "olist_pdv"
        merged["pdv_list_id"] = pdv["pdv_list_id"]
        merged["estoque_disponivel"] = quantity
        marker = str(summary.get("dataAlteracao") or merged.get("dataAlteracao") or "").strip()
        if marker:
            merged["_olist_updated_at"] = marker
        merged["_detail_synced_at"] = datetime.now(timezone.utc).isoformat()
        return product_id, public_product(merged)

    if pending:
        with ThreadPoolExecutor(max_workers=max(1, min(workers, 8))) as executor:
            future_map = {executor.submit(fetch, summary): summary for summary in pending}
            for index, future in enumerate(as_completed(future_map), 1):
                summary = future_map[future]
                product_id = str(summary.get("id", ""))
                try:
                    key, product = future.result()
                    enriched[key] = product
                except Exception as exc:
                    failures += 1
                    print(f"[!] Detalhe/PDV {product_id} falhou; cache inteiro anterior preservado ({type(exc).__name__})")
                if index % 25 == 0 or index == len(pending):
                    print(f"[+] Detalhes processados: {index}/{len(pending)}; falhas={failures}")

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


def recover_missing_skus(summaries: list[dict[str, Any]], token: str) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    """Verify missing SKU from the authenticated ERP detail, never from stale cache."""
    resolved: list[dict[str, Any]] = []
    quarantined: list[dict[str, Any]] = []
    for row in summaries:
        product_id = str(row.get("id") or "").strip()
        if not product_id or not product_id.isdigit():
            raise ValueError("erp_product_id_missing")
        sku = str(row.get("sku") or row.get("codigo") or "").strip()
        if sku:
            resolved.append(row)
            continue
        detail = api_get(f"produtos/{product_id}", token)
        if str(detail.get("id") or "") != product_id:
            raise ValueError("erp_product_id_mismatch")
        verified_sku = str(detail.get("sku") or detail.get("codigo") or "").strip()
        if not verified_sku:
            quarantined.append({
                "sku": "",
                "olist_product_ids": [product_id],
                "reason": "missing_erp_sku",
            })
            continue
        updated = dict(row)
        updated["sku"] = verified_sku
        resolved.append(updated)
    return resolved, quarantined


def partition_ambiguous_skus(summaries: list[dict[str, Any]]) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    """Keep only unambiguous ERP IDs; quarantine EVERY member of a colliding SKU.

    Ambiguity is an explicit unresolved ERP incident, not a silent choice of the
    cheapest/first item. Missing identities or repeated IDs block the full sync.
    """
    by_sku: dict[str, list[dict[str, Any]]] = {}
    seen_ids: set[str] = set()
    for row in summaries:
        sku = str(row.get("sku") or row.get("codigo") or "").strip()
        product_id = str(row.get("id") or "").strip()
        if not sku or not product_id or product_id in seen_ids:
            raise ValueError("erp_missing_or_duplicate_identity")
        seen_ids.add(product_id)
        by_sku.setdefault(sku.casefold(), []).append(row)
    quarantined: list[dict[str, Any]] = []
    ambiguous_keys: set[str] = set()
    for key, rows in by_sku.items():
        if len(rows) < 2:
            continue
        ambiguous_keys.add(key)
        quarantined.append({
            "sku": str(rows[0].get("sku") or rows[0].get("codigo") or "").strip(),
            "olist_product_ids": sorted(str(row["id"]) for row in rows),
            "reason": "duplicate_active_sku",
        })
    clean = [row for row in summaries if str(row.get("sku") or row.get("codigo") or "").strip().casefold() not in ambiguous_keys]
    return clean, sorted(quarantined, key=lambda item: item["sku"].casefold())


def save_products(products: list[dict[str, Any]], quarantined: list[dict[str, Any]] | None = None, source_count: int | None = None, *, publish: bool = True) -> Path:
    registry = load_video_sources()
    products = [merge_video_sources(item, registry) for item in products]
    blocked = quarantined or []
    payload = {
        "total": len(products),
        "source_active_count": source_count if source_count is not None else len(products),
        "quarantined_skus": blocked,
        "quarantined_product_count": sum(len(row["olist_product_ids"]) for row in blocked),
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "itens": products,
    }
    CACHE_PATH.parent.mkdir(parents=True, exist_ok=True)
    lock_path = CACHE_PATH.parent / "olist-erp-webhook.lock"
    # PHP webhook mutations and the full reconciler must share one writer lock.
    with lock_path.open("a+") as lock:
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX)
        temporary = CACHE_PATH.with_suffix(".json.tmp")
        try:
            temporary.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
            temporary.replace(CACHE_PATH)
            if publish:
                # Derived caches must not serve registration prices after a PDV refresh.
                (CACHE_PATH.parent / "cache" / "products-cache-ativos.json").unlink(missing_ok=True)
                derived_dir = CACHE_PATH.parent / "cache" / "catalog-api"
                if derived_dir.is_dir():
                    for old_response in derived_dir.glob("*.json"):
                        old_response.unlink(missing_ok=True)
        finally:
            temporary.unlink(missing_ok=True)
            fcntl.flock(lock.fileno(), fcntl.LOCK_UN)
    return CACHE_PATH


def sync_once(workers: int, *, stage_only: bool = False) -> bool:
    token = get_token()
    if not token:
        print("[!] OLIST_ACCESS_TOKEN/TINY_ACCESS_TOKEN não configurado")
        return False
    summaries = fetch_products_active(token)
    if not summaries:
        print("[!] A API não retornou produtos ativos; cache existente preservado")
        return False
    # Block ONLY the ambiguous SKUs, with durable provenance in the same atomic
    # catalog snapshot. Other products may publish only after full PDV proof.
    try:
        identified_summaries, missing_skus = recover_missing_skus(summaries, token)
        unique_summaries, duplicate_skus = partition_ambiguous_skus(identified_summaries)
        quarantined = sorted(missing_skus + duplicate_skus, key=lambda item: (item["sku"].casefold(), item["olist_product_ids"]))
    except (TypeError, ValueError, RuntimeError) as exc:
        print(f"[!] Identidade ERP invalida ({type(exc).__name__}); cache anterior preservado")
        return False
    if not unique_summaries:
        print("[!] Nenhum SKU unico verificavel; cache anterior preservado")
        return False
    if quarantined:
        print(f"[!] QUARENTENA ERP: {len(quarantined)} SKU(s), issue #3271; todos os IDs associados bloqueados")
    products, failures = enrich_products(unique_summaries, token, workers)
    if failures or len(products) != len(unique_summaries):
        print(f"[!] PDV dos SKUs unicos nao comprovada; {failures} falhas; {len(products)}/{len(unique_summaries)} produtos; cache anterior preservado")
        return False
    if any(item.get("price_source") != "olist_pdv" or int(item.get("pdv_list_id") or 0) < 1 for item in products):
        print("[!] Algum preco nao proveio de PDV; cache anterior preservado")
        return False
    kits_updated = apply_kit_stock(products)
    if kits_updated:
        print(f"[+] Estoque recalculado pela composicao em {kits_updated} produtos tipo kit")
    output = save_products(products, quarantined, len(summaries), publish=not stage_only)
    with_stock = sum(1 for item in products if int(item.get("estoque_disponivel") or 0) > 0)
    with_images = sum(1 for item in products if isinstance(item.get("anexos"), list) and item["anexos"])
    print(f"[+] Cache atômico salvo: {len(products)} produtos PDV, {with_stock} com estoque, {with_images} com imagem; {len(quarantined)} SKU(s) em quarentena NAO_APTO; {failures} falhas em {output}")
    return failures < len(summaries)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--once", action="store_true", help="Executa uma sincronização e encerra")
    parser.add_argument("--interval", type=int, default=300, help="Intervalo do daemon em segundos")
    parser.add_argument("--workers", type=int, default=4, help="Concorrência para detalhes (1-8)")
    parser.add_argument("--stage-cache", type=Path, help="Gravar SOMENTE cache candidato isolado; nunca publicar o resultado")
    args = parser.parse_args()
    global CACHE_PATH
    if args.stage_cache is not None:
        if not args.once or not args.stage_cache.is_absolute() or not args.stage_cache.name.startswith(".pdv-stage-") or args.stage_cache.suffix != ".json":
            parser.error("--stage-cache exige --once e destino absoluto .pdv-stage-*.json")
        # The deployment runner creates this non-web-linked staging directory.
        allowed_stage_root = Path("/home/ubuntu/shopvivaliz-deploy/shared/deployment-pdv").resolve()
        if args.stage_cache.parent.resolve() != allowed_stage_root:
            parser.error("--stage-cache deve permanecer no diretorio de staging autorizado")
        CACHE_PATH = args.stage_cache
    while True:
        started = time.monotonic()
        try:
            ok = sync_once(args.workers, stage_only=args.stage_cache is not None)
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
