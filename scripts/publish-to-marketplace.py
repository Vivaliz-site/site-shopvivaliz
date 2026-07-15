#!/usr/bin/env python3
"""
ShopVivaliz - Publicação no Marketplace
Etapa 9 do pipeline: atualiza imagens nos anúncios Shopee e TikTok
via Tiny ERP API (sem alterar preço, estoque ou outros atributos).
"""

from __future__ import annotations

import argparse
import csv
import json
import os
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen

# ---------------------------------------------------------------------------
# Constantes
# ---------------------------------------------------------------------------

TINY_API_BASE   = os.getenv("OLIST_API_BASE_URL") or os.getenv("TINY_API_BASE_URL") or "https://api.tiny.com.br/public-api/v3"
TINY_TOKEN_URL  = os.getenv("OLIST_TOKEN_URL") or "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token"
DEFAULT_REPORT  = Path(os.getenv("AI_REPORT_JSON", "logs/ai-images-report.json"))
DEFAULT_OUT     = Path(os.getenv("PUBLISH_REPORT", "logs/publish-marketplace-report.json"))
SLEEP_BETWEEN   = float(os.getenv("PUBLISH_SLEEP", "1.5"))
MAX_RETRY       = int(os.getenv("PUBLISH_MAX_RETRY", "2"))
USER_AGENT      = "ShopVivalizMarketplacePublisher/1.0"

# Mapeamento: tipo de imagem principal por marketplace
MARKETPLACE_PRIMARY: Dict[str, str] = {
    "shopee":  "fundo_branco",   # Shopee: fundo branco como principal
    "tiktok":  "lifestyle",      # TikTok: lifestyle como principal
    "default": "fundo_branco",
}


# ---------------------------------------------------------------------------
# HTTP helpers
# ---------------------------------------------------------------------------

def log(msg: str) -> None:
    print(msg, flush=True)


def fail(msg: str, code: int = 1) -> None:
    print(f"ERRO: {msg}", file=sys.stderr, flush=True)
    sys.exit(code)


def http_post_form(url: str, data: dict, timeout: int = 45) -> dict:
    req = Request(
        url,
        data=urlencode(data).encode("utf-8"),
        headers={
            "Content-Type": "application/x-www-form-urlencoded",
            "User-Agent":   USER_AGENT,
        },
        method="POST",
    )
    try:
        with urlopen(req, timeout=timeout) as resp:
            raw = resp.read().decode("utf-8", errors="replace")
            return json.loads(raw) if raw else {}
    except HTTPError as exc:
        return {"_http_status": exc.code, "_error": exc.read().decode("utf-8", errors="replace")[:500]}
    except (URLError, TimeoutError) as exc:
        return {"_http_status": 0, "_error": str(exc)}
    except json.JSONDecodeError as exc:
        return {"_http_status": 0, "_error": f"invalid_json:{exc}"}


def http_api(method: str, url: str, token: str, body: Optional[dict] = None, params: Optional[dict] = None, timeout: int = 60) -> dict:
    if params:
        url = url + "?" + urlencode(params)
    data = json.dumps(body or {}, ensure_ascii=False).encode("utf-8") if body else None
    req  = Request(
        url,
        data=data,
        headers={
            "Authorization": f"Bearer {token}",
            "Content-Type":  "application/json",
            "Accept":        "application/json",
            "User-Agent":    USER_AGENT,
        },
        method=method,
    )
    try:
        with urlopen(req, timeout=timeout) as resp:
            raw = resp.read().decode("utf-8", errors="replace")
            return json.loads(raw) if raw else {}
    except HTTPError as exc:
        body_err = exc.read().decode("utf-8", errors="replace")
        return {"_http_status": exc.code, "_error": body_err[:500]}
    except (URLError, TimeoutError) as exc:
        return {"_http_status": 0, "_error": str(exc)}
    except json.JSONDecodeError as exc:
        return {"_http_status": 0, "_error": f"invalid_json:{exc}"}


# ---------------------------------------------------------------------------
# Autenticação Tiny / Olist
# ---------------------------------------------------------------------------

def resolve_token() -> str:
    for name in ("OLIST_ACCESS_TOKEN", "TINY_ACCESS_TOKEN", "ERP_API_TOKEN", "TOKEN_API_OLIST", "OLIST_API_TOKEN", "TINY_API_TOKEN"):
        val = os.getenv(name, "").strip()
        if val:
            log(f"Token: usando {name}")
            return val

    client_id     = os.getenv("OLIST_CLIENT_ID") or os.getenv("TINY_CLIENT_ID", "")
    client_secret = os.getenv("OLIST_CLIENT_SECRET") or os.getenv("TINY_CLIENT_SECRET", "")
    refresh_token = os.getenv("OLIST_REFRESH_TOKEN") or os.getenv("TINY_REFRESH_TOKEN", "")

    if client_id and client_secret and refresh_token:
        log("Renovando token OAuth2...")
        resp  = http_post_form(TINY_TOKEN_URL, {
            "grant_type":    "refresh_token",
            "client_id":     client_id,
            "client_secret": client_secret,
            "refresh_token": refresh_token,
        })
        token = resp.get("access_token", "")
        if token:
            return token
        fail(f"Falha ao renovar token: {resp.get('_error', resp)}")

    fail("Nenhuma credencial Tiny/Olist configurada. Defina OLIST_ACCESS_TOKEN ou TINY_CLIENT_ID/SECRET/REFRESH_TOKEN.")
    return ""


# ---------------------------------------------------------------------------
# Busca produto na API Tiny pelo SKU
# ---------------------------------------------------------------------------

def fetch_product_by_sku(token: str, sku: str) -> Tuple[Optional[dict], str]:
    if not sku:
        return None, "sku vazio"
    resp = http_api("GET", f"{TINY_API_BASE}/produtos", token, params={"sku": sku, "limit": 1})
    if resp.get("_http_status"):
        return None, f"HTTP {resp['_http_status']}: {resp.get('_error', '')[:100]}"
    items = []
    for key in ("itens", "items", "produtos", "products", "data"):
        v = resp.get(key)
        if isinstance(v, list) and v:
            items = v
            break
    if not items:
        return None, "produto não encontrado"
    item = items[0]
    if isinstance(item, dict) and "produto" in item:
        item = item["produto"]
    return item, ""


def fetch_product_by_id(token: str, olist_id: str) -> Tuple[Optional[dict], str]:
    if not olist_id:
        return None, "olist_id vazio"
    resp = http_api("GET", f"{TINY_API_BASE}/produtos/{olist_id}", token)
    if resp.get("_http_status"):
        return None, f"HTTP {resp['_http_status']}: {resp.get('_error', '')[:100]}"
    for key in ("produto", "data", "item"):
        v = resp.get(key)
        if isinstance(v, dict):
            return v, ""
    if "id" in resp:
        return resp, ""
    return None, "resposta inesperada da API"


# ---------------------------------------------------------------------------
# Atualização de imagem via Tiny API
# ---------------------------------------------------------------------------

def update_product_image(token: str, product_id: str, image_url: str, extra_images: List[str]) -> Tuple[bool, str]:
    """Atualiza imagem principal e galeria de um produto no Tiny/Olist.
    NUNCA altera preço, estoque ou outros atributos."""

    images_payload: List[Dict[str, str]] = [{"url": image_url}]
    for url in extra_images:
        if url and url != image_url:
            images_payload.append({"url": url})

    body = {
        "imagem_produto": {"url": image_url},
        "imagens":        images_payload,
    }

    for attempt in range(MAX_RETRY + 1):
        resp = http_api("PUT", f"{TINY_API_BASE}/produtos/{product_id}", token, body=body)
        status_code = resp.get("_http_status", 200)
        if status_code and status_code >= 400:
            err = resp.get("_error", "")[:200]
            if attempt < MAX_RETRY:
                log(f"    Tentativa {attempt+1} falhou (HTTP {status_code}). Aguardando...")
                time.sleep(3)
                continue
            return False, f"HTTP {status_code}: {err}"
        break

    return True, ""


# ---------------------------------------------------------------------------
# Pipeline principal
# ---------------------------------------------------------------------------

def process_report(report: dict, token: str, dry_run: bool) -> List[dict]:
    results = []

    products = report.get("products", [])
    log(f"Produtos no relatório: {len(products)}")

    for idx, product in enumerate(products, start=1):
        sku      = (product.get("sku") or "").strip()
        olist_id = (product.get("olist_id") or "").strip()
        images   = product.get("images", {})

        log(f"\n[{idx}/{len(products)}] SKU={sku or '-'} OlistID={olist_id or '-'}")

        # Busca produto na API Tiny
        tiny_product = None
        err_msg      = ""
        if olist_id:
            tiny_product, err_msg = fetch_product_by_id(token, olist_id)
        if not tiny_product and sku:
            tiny_product, err_msg = fetch_product_by_sku(token, sku)

        if not tiny_product:
            log(f"  Produto não encontrado na API Tiny: {err_msg}")
            results.append({
                "sku":    sku,
                "olist_id": olist_id,
                "status": "not_found",
                "error":  err_msg,
            })
            continue

        product_id = str(tiny_product.get("id") or tiny_product.get("idProduto") or olist_id)

        # Coleta URLs das imagens geradas por tipo
        available = {
            img_type: img_data.get("site_url", "")
            for img_type, img_data in images.items()
            if img_data.get("site_url") and img_data.get("status") in ("uploaded", "generated")
        }

        if not available:
            log("  Sem imagens geradas disponíveis para publicação.")
            results.append({
                "sku":         sku,
                "olist_id":    olist_id,
                "product_id":  product_id,
                "status":      "no_images",
                "error":       "nenhuma imagem gerada/uploaded disponível",
                "marketplaces": {},
            })
            continue

        mp_results: Dict[str, Any] = {}
        for marketplace, primary_type in MARKETPLACE_PRIMARY.items():
            if marketplace == "default":
                continue

            primary_url = available.get(primary_type, "")
            if not primary_url:
                # Fallback para qualquer imagem disponível
                primary_url = next(iter(available.values()), "")

            if not primary_url:
                mp_results[marketplace] = {"status": "no_image"}
                continue

            extra_urls = [u for t, u in available.items() if u != primary_url]

            log(f"  [{marketplace}] Imagem principal: {primary_type} → {primary_url[:60]}")

            if dry_run:
                mp_results[marketplace] = {
                    "status":       "dry_run",
                    "primary_type": primary_type,
                    "primary_url":  primary_url,
                }
                continue

            ok, pub_err = update_product_image(token, product_id, primary_url, extra_urls)
            mp_results[marketplace] = {
                "status":       "published" if ok else "error",
                "primary_type": primary_type,
                "primary_url":  primary_url,
                "error":        pub_err,
            }
            if ok:
                log(f"    Publicado com sucesso.")
            else:
                log(f"    Falha: {pub_err[:80]}")

            time.sleep(SLEEP_BETWEEN)

        results.append({
            "sku":         sku,
            "olist_id":    olist_id,
            "product_id":  product_id,
            "marketplaces": mp_results,
            "status":      "processed",
        })

    return results


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def parse_args(argv: List[str]) -> argparse.Namespace:
    p = argparse.ArgumentParser(description="Publica imagens IA nos marketplaces via Tiny API.")
    p.add_argument("--report",   default=str(DEFAULT_REPORT), help="JSON gerado pelo generate-ai-images.py")
    p.add_argument("--out",      default=str(DEFAULT_OUT),    help="JSON de saída com resultados da publicação.")
    p.add_argument("--dry-run",  action="store_true",          help="Simula sem alterar anúncios.")
    p.add_argument("--limit",    type=int, default=0,          help="Limite de produtos (0 = todos).")
    return p.parse_args(argv)


def main(argv: List[str]) -> int:
    args = parse_args(argv)

    report_path = Path(args.report)
    if not report_path.is_file():
        fail(f"Relatório não encontrado: {report_path}")

    report = json.loads(report_path.read_text(encoding="utf-8"))
    if args.limit > 0:
        report["products"] = report.get("products", [])[:args.limit]

    log("=== ShopVivaliz Marketplace Publisher ===")
    if args.dry_run:
        log("Modo dry-run — nenhum anúncio será alterado.")
        token = "dry_run"
    else:
        token = resolve_token()
    results = process_report(report, token, args.dry_run)

    published = sum(
        1 for r in results
        for mp_r in (r.get("marketplaces") or {}).values()
        if mp_r.get("status") == "published"
    )
    errors = sum(
        1 for r in results
        for mp_r in (r.get("marketplaces") or {}).values()
        if mp_r.get("status") == "error"
    )

    output = {
        "ok":           True,
        "agent":        "shopvivaliz_marketplace_publisher",
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "dry_run":      args.dry_run,
        "summary": {
            "total_products":    len(results),
            "published":         published,
            "errors":            errors,
            "not_found":         sum(1 for r in results if r.get("status") == "not_found"),
        },
        "results": results,
    }

    out_path = Path(args.out)
    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(json.dumps(output, ensure_ascii=False, indent=2), encoding="utf-8")

    log(f"\n=== CONCLUÍDO ===")
    log(f"Produtos processados: {len(results)}")
    log(f"Publicados:           {published}")
    log(f"Erros:                {errors}")
    log(f"Relatório: {out_path}")
    return 0 if errors == 0 else 1


if __name__ == "__main__":
    try:
        raise SystemExit(main(sys.argv[1:]))
    except KeyboardInterrupt:
        print("\nInterrompido.", file=sys.stderr)
        raise SystemExit(130)
