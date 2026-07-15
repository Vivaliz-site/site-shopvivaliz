#!/usr/bin/env python3
"""Sincroniza estoque e preco reais da Tiny (API v3) para
api/catalog/fallback-products.json.

Roda via GitHub Actions (.github/workflows/sync-stock-tiny.yml) e
tambem pode ser executado manualmente na VM.

Prioriza refresh via OAuth2 (TINY_REFRESH_TOKEN/OLIST_REFRESH_TOKEN),
ja que o access_token estatico expira em ~4h -- mesmo criterio usado
em agents/v9.2.85/app/ShopeeListingsExtractorAgent.php.
"""
import json
import os
import sys
import time
import urllib.parse
import urllib.request
import urllib.error

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CATALOG_PATH = os.path.join(ROOT, "api", "catalog", "fallback-products.json")
TOKEN_URL = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token"
REPORT_PATH = os.path.join(ROOT, "logs", "sync-stock-tiny-report.json")


def load_env():
    """Le .env local se existir (execucao manual na VM); no GitHub
    Actions as variaveis ja vem do ambiente via secrets."""
    env_path = os.path.join(ROOT, ".env")
    if os.path.isfile(env_path):
        with open(env_path, encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                k, v = line.split("=", 1)
                k = k.strip()
                v = v.strip().strip('"').strip("'")
                if k and k not in os.environ:
                    os.environ[k] = v


def http_post(url, fields):
    data = urllib.parse.urlencode(fields).encode()
    req = urllib.request.Request(url, data=data, headers={
        "Content-Type": "application/x-www-form-urlencoded",
    })
    try:
        with urllib.request.urlopen(req, timeout=20) as resp:
            return json.loads(resp.read())
    except urllib.error.HTTPError as e:
        body = e.read().decode(errors="replace")
        print(f"ERRO HTTP {e.code} ao renovar token: {body[:300]}", file=sys.stderr)
        return {"_http_error": e.code, "_body": body[:1000]}


def save_report(payload):
    os.makedirs(os.path.dirname(REPORT_PATH), exist_ok=True)
    with open(REPORT_PATH, "w", encoding="utf-8") as f:
        json.dump(payload, f, ensure_ascii=False, indent=2)
        f.write("\n")


def resolve_token():
    client_id = os.environ.get("TINY_CLIENT_ID") or os.environ.get("OLIST_CLIENT_ID") or ""
    client_secret = os.environ.get("TINY_CLIENT_SECRET") or os.environ.get("OLIST_CLIENT_SECRET") or ""
    refresh_token = os.environ.get("TINY_REFRESH_TOKEN") or os.environ.get("OLIST_REFRESH_TOKEN") or ""

    if client_id and client_secret and refresh_token:
        resp = http_post(TOKEN_URL, {
            "grant_type": "refresh_token",
            "client_id": client_id,
            "client_secret": client_secret,
            "refresh_token": refresh_token,
        })
        if resp.get("access_token"):
            return {
                "token": resp["access_token"],
                "source": "oauth_refresh",
                "refresh_ok": True,
            }
        if resp.get("_http_error"):
            decoded = {}
            try:
                decoded = json.loads(resp.get("_body") or "{}")
            except Exception:
                decoded = {}
            return {
                "token": None,
                "source": "oauth_refresh",
                "refresh_ok": False,
                "http_error": resp.get("_http_error"),
                "oauth_error": decoded.get("error", ""),
                "oauth_error_description": decoded.get("error_description", ""),
            }

    static_token = (
        os.environ.get("TINY_ACCESS_TOKEN")
        or os.environ.get("TINY_API_TOKEN")
        or os.environ.get("OLIST_ACCESS_TOKEN")
        or ""
    )
    if static_token:
        return {
            "token": static_token,
            "source": "static_token",
            "refresh_ok": False,
        }
    return {
        "token": None,
        "source": "none",
        "refresh_ok": False,
    }


def fetch_stock(product_id, token, retries=5):
    url = f"https://api.tiny.com.br/public-api/v3/produtos/{product_id}"
    req = urllib.request.Request(url, headers={
        "Authorization": f"Bearer {token}",
        "Accept": "application/json",
    })
    for attempt in range(retries):
        try:
            with urllib.request.urlopen(req, timeout=15) as resp:
                data = json.loads(resp.read())
                estoque = data.get("estoque", {})
                precos = data.get("precos", {})
                return {
                    "stock": int(estoque.get("quantidade", 0) or 0),
                    "price": float(precos.get("preco", 0) or 0),
                }
        except urllib.error.HTTPError as e:
            if e.code == 429:
                wait = 3 * (attempt + 1)
                print(f"  429 em {product_id}, aguardando {wait}s (tentativa {attempt + 1}/{retries})", file=sys.stderr)
                time.sleep(wait)
                continue
            print(f"  ERRO {product_id}: HTTP {e.code}", file=sys.stderr)
            return None
        except Exception as e:
            print(f"  ERRO {product_id}: {e}", file=sys.stderr)
            return None
    print(f"  DESISTINDO {product_id} apos {retries} tentativas (429 persistente)", file=sys.stderr)
    return None


def main():
    load_env()
    token_info = resolve_token()
    token = token_info.get("token")
    if not token:
        save_report({
            "ok": False,
            "stage": "resolve_token",
            "token_source": token_info.get("source"),
            "refresh_ok": token_info.get("refresh_ok"),
            "http_error": token_info.get("http_error"),
            "oauth_error": token_info.get("oauth_error"),
            "oauth_error_description": token_info.get("oauth_error_description"),
            "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        })
        print("ERRO: nenhuma credencial Tiny disponivel (refresh ou token estatico)", file=sys.stderr)
        sys.exit(1)

    if (
        token_info.get("source") == "oauth_refresh"
        and token_info.get("refresh_ok") is False
        and token_info.get("oauth_error") == "invalid_grant"
    ):
        save_report({
            "ok": False,
            "stage": "resolve_token",
            "token_source": token_info.get("source"),
            "refresh_ok": False,
            "http_error": token_info.get("http_error"),
            "oauth_error": token_info.get("oauth_error"),
            "oauth_error_description": token_info.get("oauth_error_description"),
            "action_required": "Regenerar refresh token valido nos secrets OLIST_/TINY_.",
            "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        })
        print("ERRO: refresh token invalido/inativo; abortando antes de disparar 401 em massa.", file=sys.stderr)
        sys.exit(1)

    with open(CATALOG_PATH, encoding="utf-8") as f:
        catalog = json.load(f)

    print(f"Sincronizando estoque de {len(catalog)} produtos...")
    updated = 0
    errors = 0
    for i, product in enumerate(catalog):
        pid = str(product.get("olist_product_id") or product.get("id") or "").strip()
        if not pid:
            continue
        result = fetch_stock(pid, token)
        if result is None:
            errors += 1
            continue
        old_stock = product.get("stock", 0)
        product["stock"] = result["stock"]
        if result["price"] > 0:
            product["price"] = result["price"]
        if old_stock != result["stock"]:
            updated += 1
        if (i + 1) % 20 == 0:
            print(f"  {i + 1}/{len(catalog)} processados...")
        time.sleep(0.6)

    with open(CATALOG_PATH, "w", encoding="utf-8") as f:
        json.dump(catalog, f, ensure_ascii=False, indent=2)
        f.write("\n")

    save_report({
        "ok": errors <= len(catalog) * 0.5,
        "stage": "sync_complete",
        "token_source": token_info.get("source"),
        "refresh_ok": token_info.get("refresh_ok"),
        "catalog_size": len(catalog),
        "updated": updated,
        "errors": errors,
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
    })

    print(f"Concluido: {updated} produtos com estoque atualizado, {errors} erros.")
    if errors > len(catalog) * 0.5:
        print("Mais da metade das chamadas falhou -- provavel problema de credencial/rate limit.", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
