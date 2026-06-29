#!/usr/bin/env python3
"""Exporta produtos e URLs de imagens Olist/Tiny para CSV sem imprimir secrets."""

import csv
import json
import os
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen

TOKEN_URL = os.getenv(
    "OLIST_TOKEN_URL",
    "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token",
)
PRODUCTS_API = os.getenv("OLIST_PRODUCTS_API", "https://api.tiny.com.br/api/v2/produtos.json")
PROXY_URL    = os.getenv("OLIST_PROXY_URL", "")  # se definido, usa proxy PHP no servidor
OUT_CSV = Path(os.getenv("OUT_CSV", "logs/olist-images-export.csv"))
OUT_JSON = Path(os.getenv("OUT_JSON", "logs/olist-products-raw.json"))
LIMIT = int(os.getenv("OLIST_PAGE_LIMIT", "50"))
MAX_PAGES = int(os.getenv("OLIST_MAX_PAGES", "20"))
SLEEP_SECONDS = float(os.getenv("OLIST_SLEEP_SECONDS", "0.3"))


def fail(message: str, code: int = 1) -> None:
    print(f"ERRO: {message}", file=sys.stderr)
    sys.exit(code)


def http_json(request: Request, timeout: int = 45) -> dict:
    try:
        with urlopen(request, timeout=timeout) as response:
            raw = response.read().decode("utf-8", errors="replace")
            return json.loads(raw) if raw else {}
    except HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        fail(f"HTTP {exc.code} em {request.full_url}: {body[:1000]}")
    except URLError as exc:
        fail(f"Falha de rede em {request.full_url}: {exc}")
    except json.JSONDecodeError as exc:
        fail(f"Resposta nao e JSON valido em {request.full_url}: {exc}")
    return {}


def http_post_form(url: str, data: dict, timeout: int = 45) -> dict:
    request = Request(
        url,
        data=urlencode(data).encode("utf-8"),
        headers={"Content-Type": "application/x-www-form-urlencoded"},
        method="POST",
    )
    return http_json(request, timeout=timeout)


def auth_context() -> dict:
    # Modo proxy: usa o servidor como intermediário (contorna bloqueio de IP)
    if PROXY_URL:
        squad_token = os.getenv("SQUAD_TOKEN", "")
        api_token   = os.getenv("TOKEN_API_OLIST") or os.getenv("OLIST_API_TOKEN") or ""
        print(f"Usando proxy PHP: {PROXY_URL}")
        return {"type": "proxy", "proxy_url": PROXY_URL, "squad_token": squad_token, "olist_token": api_token}

    api_token = os.getenv("OLIST_API_TOKEN") or os.getenv("TINY_API_TOKEN") or os.getenv("TOKEN_API_OLIST")
    if api_token:
        print("Usando token de API v2 por parametro seguro.")
        return {"type": "query_token", "token": api_token}

    access_token = os.getenv("OLIST_ACCESS_TOKEN") or os.getenv("TINY_ACCESS_TOKEN")
    if access_token:
        print("Usando access token Bearer direto do ambiente.")
        return {"type": "bearer", "token": access_token}

    client_id = os.getenv("OLIST_CLIENT_ID") or os.getenv("TINY_CLIENT_ID")
    client_secret = os.getenv("OLIST_CLIENT_SECRET") or os.getenv("TINY_CLIENT_SECRET")
    refresh_token = os.getenv("OLIST_REFRESH_TOKEN") or os.getenv("TINY_REFRESH_TOKEN")
    if not client_id or not client_secret:
        fail("Configure OLIST_API_TOKEN/TOKEN_API_OLIST ou OLIST_CLIENT_ID e OLIST_CLIENT_SECRET.")

    if refresh_token:
        print("Renovando access token com refresh_token...")
        payload = {
            "grant_type": "refresh_token",
            "client_id": client_id,
            "client_secret": client_secret,
            "refresh_token": refresh_token,
        }
    else:
        print("Refresh token nao encontrado. Tentando client_credentials...")
        payload = {"grant_type": "client_credentials", "client_id": client_id, "client_secret": client_secret}

    token_response = http_post_form(TOKEN_URL, payload)
    access_token = token_response.get("access_token")
    if not access_token:
        fail(f"Token nao retornado. Resposta: {json.dumps(token_response, ensure_ascii=False)[:1000]}")
    return {"type": "bearer", "token": access_token}


def http_get_json(url: str, auth: dict, params: dict, timeout: int = 45) -> dict:
    query   = dict(params)
    headers = {"Accept": "application/json"}

    if auth["type"] == "proxy":
        # Chama o proxy PHP via POST (token embutido no servidor, não vai na URL)
        proxy_params = dict(params)
        post_data    = urlencode(proxy_params).encode()
        req_headers  = {
            "User-Agent":   "Mozilla/5.0 (compatible; ShopVivaliz/1.0)",
            "Content-Type": "application/x-www-form-urlencoded",
            "X-Squad":      auth.get("squad_token", ""),
        }
        request = Request(auth["proxy_url"], data=post_data, headers=req_headers, method="POST")
        return http_json(request, timeout=timeout)

    if auth["type"] == "query_token":
        query["token"] = auth["token"]
    else:
        headers["Authorization"] = f"Bearer {auth['token']}"
    request = Request(f"{url}?{urlencode(query)}", headers=headers, method="GET")
    return http_json(request, timeout=timeout)


def normalize_product(item):
    if isinstance(item, dict) and "produto" in item and isinstance(item["produto"], dict):
        return item["produto"]
    return item if isinstance(item, dict) else {}


def get_nested(data: dict, *paths, default=""):
    for path in paths:
        cur = data
        ok = True
        for key in path.split("."):
            if isinstance(cur, dict) and key in cur:
                cur = cur[key]
            else:
                ok = False
                break
        if ok and cur not in (None, ""):
            return cur
    return default


def extract_images(product: dict) -> list:
    images = []

    def add(url, source):
        if not url:
            return
        value = str(url).strip()
        if value and value not in [img["url"] for img in images]:
            images.append({"url": value, "source": source})

    add(get_nested(product, "imagem_produto.url", "imagem.url", "image_url", "primary_image_url"), "principal")

    for field in ("imagens", "images", "fotos", "anexos"):
        values = product.get(field)
        if isinstance(values, list):
            for item in values:
                if isinstance(item, dict):
                    add(get_nested(item, "url", "link", "src", "imagem.url"), field)
                elif isinstance(item, str):
                    add(item, field)
        elif isinstance(values, dict):
            add(get_nested(values, "url", "link", "src"), field)

    return images


def extract_products(response: dict) -> list:
    retorno = response.get("retorno")
    if isinstance(retorno, dict):
        for key in ("produtos", "products", "data", "items"):
            value = retorno.get(key)
            if isinstance(value, list):
                return value
    for key in ("produtos", "products", "data", "items"):
        value = response.get(key)
        if isinstance(value, list):
            return value
    return []


def fetch_all_products(auth: dict) -> list:
    all_products = []
    for page in range(1, MAX_PAGES + 1):
        print(f"Buscando pagina {page}...")
        response = http_get_json(PRODUCTS_API, auth, {"limite": LIMIT, "pagina": page, "formato": "json"})
        products = extract_products(response)
        print(f"  Retornados: {len(products)}")
        if not products:
            break
        all_products.extend([normalize_product(product) for product in products])
        if len(products) < LIMIT:
            break
        time.sleep(SLEEP_SECONDS)
    return all_products


def build_rows(products: list) -> list:
    now = datetime.now(timezone.utc).isoformat()
    rows = []
    for product in products:
        images = extract_images(product)
        image_urls = [img["url"] for img in images]
        image_sources = [img["source"] for img in images]
        rows.append(
            {
                "exported_at": now,
                "olist_id": get_nested(product, "id", "idProduto", "id_produto"),
                "sku": get_nested(product, "codigo", "sku", "codigo_sku"),
                "nome": get_nested(product, "nome", "descricao", "name"),
                "preco_venda": get_nested(product, "preco_venda", "preco", "price"),
                "estoque_atual": get_nested(product, "estoque_atual", "estoque", "stock"),
                "primary_image_url": image_urls[0] if image_urls else "",
                "images_count": len(image_urls),
                "all_image_urls": " | ".join(image_urls),
                "image_sources": " | ".join(image_sources),
                "raw_json": json.dumps(product, ensure_ascii=False, separators=(",", ":")),
            }
        )
    return rows


def write_outputs(products: list, rows: list) -> None:
    OUT_CSV.parent.mkdir(parents=True, exist_ok=True)
    OUT_JSON.parent.mkdir(parents=True, exist_ok=True)
    fieldnames = [
        "exported_at",
        "olist_id",
        "sku",
        "nome",
        "preco_venda",
        "estoque_atual",
        "primary_image_url",
        "images_count",
        "all_image_urls",
        "image_sources",
        "raw_json",
    ]
    with OUT_CSV.open("w", newline="", encoding="utf-8-sig") as csv_file:
        writer = csv.DictWriter(csv_file, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)
    with OUT_JSON.open("w", encoding="utf-8") as json_file:
        json.dump({"total": len(products), "produtos": products}, json_file, ensure_ascii=False, indent=2)


def main() -> None:
    auth = auth_context()
    products = fetch_all_products(auth)
    rows = build_rows(products)
    write_outputs(products, rows)
    with_images = sum(1 for row in rows if row["primary_image_url"])
    print("EXPORTACAO CONCLUIDA")
    print(f"Total produtos: {len(rows)}")
    print(f"Com imagem: {with_images}")
    print(f"Sem imagem: {len(rows) - with_images}")
    print(f"CSV: {OUT_CSV}")
    print(f"JSON bruto: {OUT_JSON}")


if __name__ == "__main__":
    main()
