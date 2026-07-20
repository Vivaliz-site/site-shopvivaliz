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
PRODUCTS_API_V3 = os.getenv("OLIST_PRODUCTS_API_V3", "https://api.tiny.com.br/public-api/v3/produtos")
PROXY_URL    = os.getenv("OLIST_PROXY_URL", "")  # se definido, usa proxy PHP no servidor
OUT_CSV = Path(os.getenv("OUT_CSV", "logs/olist-images-export.csv"))
OUT_JSON = Path(os.getenv("OUT_JSON", "logs/olist-products-raw.json"))
LIMIT = int(os.getenv("OLIST_PAGE_LIMIT", "50"))
MAX_PAGES = int(os.getenv("OLIST_MAX_PAGES", "20"))
SLEEP_SECONDS = float(os.getenv("OLIST_SLEEP_SECONDS", "1.0"))


def fail(message: str, code: int = 1) -> None:
    print(f"ERRO: {message}", file=sys.stderr)
    sys.exit(code)


def http_json(request: Request, timeout: int = 45, retries: int = 5) -> dict:
    for attempt in range(retries):
        try:
            with urlopen(request, timeout=timeout) as response:
                raw = response.read().decode("utf-8", errors="replace")
                return json.loads(raw) if raw else {}
        except HTTPError as exc:
            if exc.code == 429 and attempt < retries - 1:
                wait = 2 ** attempt
                print(f"  Rate limit (429), aguardando {wait}s...")
                time.sleep(wait)
                continue
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


def try_token_request(url: str, data: dict, timeout: int = 45) -> dict:
    """Como http_post_form, mas nunca encerra o processo: erros viram {} para
    permitir que auth_context() tente o proximo metodo de autenticacao."""
    request = Request(
        url,
        data=urlencode(data).encode("utf-8"),
        headers={"Content-Type": "application/x-www-form-urlencoded"},
        method="POST",
    )
    try:
        with urlopen(request, timeout=timeout) as response:
            raw = response.read().decode("utf-8", errors="replace")
            return json.loads(raw) if raw else {}
    except HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        print(f"  Token request falhou (HTTP {exc.code}): {body[:300]}")
        return {}
    except (URLError, json.JSONDecodeError) as exc:
        print(f"  Token request falhou: {exc}")
        return {}


def auth_context() -> dict:
    # Modo proxy: usa o servidor como intermediário (contorna bloqueio de IP)
    if PROXY_URL:
        squad_token = os.getenv("SQUAD_TOKEN", "")
        api_token   = os.getenv("TOKEN_API_OLIST") or os.getenv("OLIST_API_TOKEN") or ""
        print(f"Usando proxy PHP: {PROXY_URL}")
        return {"type": "proxy", "proxy_url": PROXY_URL, "squad_token": squad_token, "olist_token": api_token}

    # API v2 (legada) foi descontinuada pela Tiny e bloqueada via Cloudflare para
    # qualquer origem (datacenter ou residencial) — preferimos sempre OAuth/v3.
    client_id = os.getenv("OLIST_CLIENT_ID") or os.getenv("TINY_CLIENT_ID")
    client_secret = os.getenv("OLIST_CLIENT_SECRET") or os.getenv("TINY_CLIENT_SECRET")
    refresh_token = os.getenv("OLIST_REFRESH_TOKEN") or os.getenv("TINY_REFRESH_TOKEN")

    if client_id and client_secret and refresh_token:
        print("Renovando access token com refresh_token (API v3)...")
        payload = {
            "grant_type": "refresh_token",
            "client_id": client_id,
            "client_secret": client_secret,
            "refresh_token": refresh_token,
        }
        token_response = try_token_request(TOKEN_URL, payload)
        access_token = token_response.get("access_token")
        if access_token:
            return {"type": "bearer_v3", "token": access_token}
        print(f"Falha ao renovar token: {json.dumps(token_response, ensure_ascii=False)[:300]}")

    access_token = os.getenv("OLIST_ACCESS_TOKEN") or os.getenv("TINY_ACCESS_TOKEN")
    if access_token:
        print("Usando access token Bearer direto do ambiente (API v3).")
        return {"type": "bearer_v3", "token": access_token}

    if not client_id or not client_secret:
        fail("Configure OLIST_REFRESH_TOKEN/OLIST_ACCESS_TOKEN ou OLIST_CLIENT_ID e OLIST_CLIENT_SECRET.")

    print("Refresh token nao encontrado. Tentando client_credentials...")
    payload = {"grant_type": "client_credentials", "client_id": client_id, "client_secret": client_secret}
    token_response = try_token_request(TOKEN_URL, payload)
    access_token = token_response.get("access_token")
    if not access_token:
        fail(f"Token nao retornado. Resposta: {json.dumps(token_response, ensure_ascii=False)[:1000]}")
    return {"type": "bearer_v3", "token": access_token}


def http_get_json(url: str, auth: dict, params: dict, timeout: int = 45) -> dict:
    query   = dict(params)
    headers = {
        "Accept": "application/json",
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
    }

    if auth["type"] == "proxy":
        # Chama o proxy PHP via POST (token embutido no servidor, não vai na URL)
        proxy_params = dict(params)
        post_data    = urlencode(proxy_params).encode()
        req_headers  = {
            "User-Agent":   "Mozilla/5.0 (compatible; ShopVivaliz/1.0)",
            "Content-Type": "application/x-www-form-urlencoded",
            "Host":         "shopvivaliz.com.br",  # Bypass Cloudflare via IP de origem
            "X-Squad":      auth.get("squad_token", ""),
        }
        request = Request(auth["proxy_url"], data=post_data, headers=req_headers, method="POST")
        return http_json(request, timeout=timeout)

    headers["Authorization"] = f"Bearer {auth['token']}"
    request = Request(f"{url}?{urlencode(query)}", headers=headers, method="GET")
    return http_json(request, timeout=timeout)


def fetch_v3_detail(product_id, auth: dict) -> dict:
    headers = {"Accept": "application/json", "Authorization": f"Bearer {auth['token']}"}
    request = Request(f"{PRODUCTS_API_V3}/{product_id}", headers=headers, method="GET")
    return http_json(request, timeout=45)


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


def fetch_all_products_v3(auth: dict) -> list:
    all_products = []
    offset = 0
    for page in range(1, MAX_PAGES + 1):
        print(f"Buscando pagina {page} (offset={offset})...")
        response = http_get_json(PRODUCTS_API_V3, auth, {"limit": LIMIT, "offset": offset})
        items = response.get("itens") or []
        total = (response.get("paginacao") or {}).get("total", 0)
        print(f"  Retornados: {len(items)} (total disponivel: {total})")
        if not items:
            break
        for item in items:
            time.sleep(SLEEP_SECONDS)
            detail = fetch_v3_detail(item.get("id"), auth)
            merged = dict(item)
            merged.update(detail)
            all_products.append(merged)
        offset += LIMIT
        if offset >= total or len(items) < LIMIT:
            break
    return all_products


def fetch_all_products(auth: dict) -> list:
    if auth["type"] == "bearer_v3":
        return fetch_all_products_v3(auth)

    # Modo proxy: shop-catalog-export.php ignora a URL passada a http_get_json
    # e faz a chamada v3 do lado do servidor -- so os params (pagina/limite) importam.
    all_products = []
    for page in range(1, MAX_PAGES + 1):
        print(f"Buscando pagina {page} via proxy...")
        response = http_get_json(PRODUCTS_API_V3, auth, {"limite": LIMIT, "pagina": page, "formato": "json"})
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
                "preco_venda": get_nested(product, "preco_venda", "precos.preco", "preco", "price"),
                "estoque_atual": get_nested(product, "estoque_atual", "estoque.quantidade", "estoque", "stock"),
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
