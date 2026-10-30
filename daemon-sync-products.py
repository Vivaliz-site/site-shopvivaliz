#!/usr/bin/env python3
"""Keep the active Olist/Tiny catalog cache enriched with detail data."""

from __future__ import annotations

import argparse
import fcntl
import json
import os
from decimal import Decimal, InvalidOperation, ROUND_HALF_UP
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
VIDEO_SOURCES_PATH = Path("storage/product-video-sources.json")
_RATE_LOCK = threading.Lock()
_LAST_REQUEST = 0.0
_MIN_REQUEST_INTERVAL = 1.10
DETAIL_CACHE_MAX_AGE_SECONDS = 2 * 60 * 60
AMAZON_PRICE_LIST_ID = 991
CENT = Decimal('0.01')
_UNCHECKED_CACHE_REVISION = object()


def public_product(item: dict[str, Any]) -> dict[str, Any]:
    """Keep only fields required by the public storefront cache."""
    prices = item.get("precos") if isinstance(item.get("precos"), dict) else {}
    stock = item.get("estoque") if isinstance(item.get("estoque"), dict) else {}
    category = item.get("categoria") if isinstance(item.get("categoria"), dict) else {}
    brand = item.get("marca") if isinstance(item.get("marca"), dict) else {}
    dimensions = item.get("dimensoes") if isinstance(item.get("dimensoes"), dict) else {}
    seo = item.get("seo") if isinstance(item.get("seo"), dict) else {}
    attachments = []
    for attachment in item.get("anexos", []) if isinstance(item.get("anexos"), list) else []:
        if isinstance(attachment, dict) and str(attachment.get("url", "")).startswith("https://"):
            attachments.append({"url": str(attachment["url"])})
    # Numeric zero is a valid authoritative ERP available-stock value.
    # Never fall back to the gross physical quantity when available is 0.
    source_quantity = (item["estoque_disponivel"]
                       if item.get("estoque_disponivel") is not None
                       else stock.get("quantidade", 0))
    quantity = max(0, int(source_quantity or 0))
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
        # Preserve ERP cadastro values separately: site prices will be Amazon-list prices.
        "_erp_base_prices": {
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


def price_decimal(value: Any, label: str, allow_zero: bool = True) -> Decimal:
    if isinstance(value, bool):
        raise RuntimeError(f"{label}_invalid")
    try:
        number = Decimal(str(value))
    except (InvalidOperation, ValueError, TypeError):
        raise RuntimeError(f"{label}_invalid") from None
    if not number.is_finite() or number < 0 or (not allow_zero and number == 0):
        raise RuntimeError(f"{label}_invalid")
    return number


def fetch_amazon_table(token: str) -> tuple[Decimal, dict[str, tuple[Decimal, Decimal]]]:
    """Authoritative ERP Amazon price list: general percent + fixed per-product exceptions."""
    payload = api_get(f"listas-precos/{AMAZON_PRICE_LIST_ID}", token)
    if (str(payload.get("id")) != str(AMAZON_PRICE_LIST_ID)
        or str(payload.get("descricao", "")).strip().casefold() != "amazon"
        or not isinstance(payload.get("excecoes"), list)):
        raise RuntimeError("amazon_price_table_invalid")
    raw_percent = payload.get("acrescimoDesconto")
    if raw_percent is None or isinstance(raw_percent, bool):
        raise RuntimeError("amazon_percent_invalid")
    try:
        percent = Decimal(str(raw_percent))
    except (InvalidOperation, ValueError, TypeError):
        raise RuntimeError("amazon_percent_invalid") from None
    if not percent.is_finite() or percent <= -100 or percent > 1000:
        raise RuntimeError("amazon_percent_invalid")
    exceptions: dict[str, tuple[Decimal, Decimal]] = {}
    for row in payload["excecoes"]:
        if not isinstance(row, dict):
            raise RuntimeError("amazon_price_exception_invalid")
        key = str(row.get("idProduto") or "").strip()
        if not key.isdecimal() or key in exceptions:
            raise RuntimeError("amazon_price_exception_id_invalid")
        price = price_decimal(row.get("preco"), "amazon_exception_price", allow_zero=False)
        promo = price_decimal(row.get("precoPromocional", 0), "amazon_exception_promo")
        exceptions[key] = (price, promo)
    return percent, exceptions


def apply_amazon_prices(
    products: list[dict[str, Any]],
    table: tuple[Decimal, dict[str, tuple[Decimal, Decimal]]],
) -> int:
    """Recompute from original ERP prices on EVERY sync; never compound overrides."""
    percent, exceptions = table
    factor = Decimal("1") + percent / Decimal("100")
    matched = 0
    for item in products:
        key = str(item.get("id") or "").strip()
        base = item.get("_erp_base_prices")
        if not key.isdecimal() or not isinstance(base, dict):
            raise RuntimeError("erp_base_price_unavailable_preserve_existing_cache")
        regular = price_decimal(base.get("preco"), "erp_base_price")
        promo = price_decimal(base.get("precoPromocional", 0), "erp_base_promo")
        if key in exceptions:
            regular, promo = exceptions[key]
            matched += 1
        else:
            regular, promo = regular * factor, promo * factor
        item["precos"] = {
            "preco": float(regular.quantize(CENT, rounding=ROUND_HALF_UP)),
            "precoPromocional": float(promo.quantize(CENT, rounding=ROUND_HALF_UP)),
        }
        item["_price_source"] = "olist_tiny_amazon"
        item["_price_list_id"] = AMAZON_PRICE_LIST_ID
        item["sync_source"] = "tiny_v3"
    return matched


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


def authoritative_stock_quantity(stock_detail: dict[str, Any]) -> int:
    """Only the ERP /estoque/{id}.disponivel field is sellable inventory.

    A gross 'saldo' can include reserved units; using it can oversell stock.
    Unavailable or malformed authoritative data must fail closed.
    """
    if not isinstance(stock_detail, dict) or "disponivel" not in stock_detail:
        raise RuntimeError("erp_sellable_stock_unavailable")
    value = stock_detail["disponivel"]
    if isinstance(value, bool):
        raise RuntimeError("erp_sellable_stock_invalid")
    try:
        quantity = Decimal(str(value))
    except (InvalidOperation, ValueError, TypeError):
        raise RuntimeError("erp_sellable_stock_invalid") from None
    if not quantity.is_finite() or quantity < 0:
        raise RuntimeError("erp_sellable_stock_invalid")
    return int(quantity)


def enrich_products(summaries: list[dict[str, Any]], token: str, workers: int = 4) -> tuple[list[dict[str, Any]], int]:
    previous = load_previous_cache()
    enriched: dict[str, dict[str, Any]] = {}
    failures = 0
    pending: list[dict[str, Any]] = []
    reusable_count = 0

    for summary in summaries:
        product_id = str(summary.get("id", ""))
        if not product_id:
            failures += 1
            continue
        pending.append(summary)
        cached = previous.get(product_id)
        marker = str(summary.get("dataAlteracao") or "").strip()
        if (isinstance(cached, dict) and isinstance(cached.get("_erp_base_prices"), dict)
            and detail_cache_is_fresh(cached, marker)):
            reusable_count += 1

    if reusable_count:
        print(f"[+] Detalhes reutilizaveis: {reusable_count}; estoque sera consultado no ERP para todos")

    def fetch(summary: dict[str, Any]) -> tuple[str, dict[str, Any]]:
        product_id = str(summary.get("id", ""))
        if not product_id:
            raise RuntimeError("product_id_missing")
        cached = previous.get(product_id)
        current_marker = str(summary.get("dataAlteracao") or "").strip()
        reuse_detail = (isinstance(cached, dict) and isinstance(cached.get("_erp_base_prices"), dict)
                        and detail_cache_is_fresh(cached, current_marker))

        if reuse_detail:
            product = dict(cached)
        else:
            detail = api_get(f"produtos/{product_id}", token)
            merged = dict(summary)
            merged.update(detail)
            product = public_product(merged)
            marker = str(summary.get("dataAlteracao") or merged.get("dataAlteracao") or "").strip()
            if marker:
                product["_olist_updated_at"] = marker
            product["_detail_synced_at"] = datetime.now(timezone.utc).isoformat()

        # Even if product registration/price has not changed, a new reservation
        # can change available stock. Never reuse inventory for two hours.
        stock_detail = api_get(f"estoque/{product_id}", token, attempts=2)
        quantity = authoritative_stock_quantity(stock_detail)
        product["estoque_disponivel"] = quantity
        product["estoque"] = {"quantidade": quantity}
        product["_stock_synced_at"] = datetime.now(timezone.utc).isoformat()
        product.pop("_stock_sync_status", None)
        return product_id, product

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
                    cached = previous.get(product_id)
                    if isinstance(cached, dict):
                        # Keep the previous verified Amazon price/identity but
                        # fail CLOSED for commerce: unknown sellable stock = 0.
                        fallback = dict(cached)
                        fallback["estoque_disponivel"] = 0
                        fallback["estoque"] = {"quantidade": 0}
                        fallback["_stock_sync_status"] = "unavailable"
                        enriched[product_id] = fallback
                        print(f"[!] Estoque {product_id} indisponivel; venda bloqueada ({type(exc).__name__})")
                    else:
                        print(f"[!] Produto novo {product_id} adiado sem estoque autoritativo ({type(exc).__name__})")
                if index % 25 == 0 or index == len(pending):
                    print(f"[+] Produtos com estoque ERP verificado: {index}/{len(pending)}; falhas={failures}")

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
    stock_by_sku: dict[str, int] = {}
    for item in products:
        sku = str(item.get("sku") or "").strip()
        if not sku:
            continue
        stock = max(0, int(item.get("estoque_disponivel") or 0))
        # Duplicate SKUs are ambiguous in kits; using the last row can
        # overstate stock if another product ID is already out of stock.
        stock_by_sku[sku] = min(stock_by_sku[sku], stock) if sku in stock_by_sku else stock
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


def cache_revision(path: Path) -> tuple[int, int, int] | None:
    """Identity of the last published atomic cache; inode changes on each replace."""
    try:
        st = path.stat()
    except FileNotFoundError:
        return None
    return st.st_ino, st.st_mtime_ns, st.st_size


def save_products(
    products: list[dict[str, Any]],
    expected_revision: tuple[int, int, int] | None | object = _UNCHECKED_CACHE_REVISION,
) -> Path:
    registry = load_video_sources()
    products = [merge_video_sources(item, registry) for item in products]
    payload = {"total": len(products), "timestamp": datetime.now(timezone.utc).isoformat(),
               "price_list_id": AMAZON_PRICE_LIST_ID, "price_source": "olist_tiny_amazon", "itens": products}
    CACHE_PATH.parent.mkdir(parents=True, exist_ok=True)
    # Same cross-language lock used by the authenticated Olist webhook.
    # Every writer checks the revision INSIDE the lock, preventing a slow
    # reconciliation from overwriting a newer event or another reconciliation.
    lock_path = CACHE_PATH.parent / "olist-erp-webhook.lock"
    with lock_path.open("a+") as lock_file:
        fcntl.flock(lock_file.fileno(), fcntl.LOCK_EX)
        try:
            if (expected_revision is not _UNCHECKED_CACHE_REVISION
                    and cache_revision(CACHE_PATH) != expected_revision):
                raise RuntimeError("catalog_changed_during_reconcile_retry_later")
            temporary = CACHE_PATH.with_suffix(".json.tmp")
            try:
                temporary.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
                os.chmod(temporary, 0o660)
                temporary.replace(CACHE_PATH)
            finally:
                temporary.unlink(missing_ok=True)
        finally:
            fcntl.flock(lock_file.fileno(), fcntl.LOCK_UN)
    return CACHE_PATH


def sync_once(workers: int) -> bool:
    initial_revision = cache_revision(CACHE_PATH)
    token = get_token()
    if not token:
        print("[!] OLIST_ACCESS_TOKEN/TINY_ACCESS_TOKEN não configurado")
        return False
    # Fail closed before publishing any price if the ERP price table cannot be read.
    amazon_table = fetch_amazon_table(token)
    summaries = fetch_products_active(token)
    if not summaries:
        print("[!] A API não retornou produtos ativos; cache existente preservado")
        return False
    products, failures = enrich_products(summaries, token, workers)
    if not products:
        print("[!] Nenhum detalhe utilizável; cache existente preservado")
        return False
    exceptions_matched = apply_amazon_prices(products, amazon_table)
    print(f"[+] Amazon ERP table {AMAZON_PRICE_LIST_ID}: {exceptions_matched} exceptions applied to active products")
    kits_updated = apply_kit_stock(products)
    if kits_updated:
        print(f"[+] Estoque recalculado pela composicao em {kits_updated} produtos tipo kit")
    output = save_products(products, expected_revision=initial_revision)
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
