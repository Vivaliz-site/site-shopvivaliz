#!/usr/bin/env python3
"""
Sincronizar produtos ERP para JSON
PHP vai ler esse JSON
"""

import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

print("[*] Sincronizando produtos para JSON...")

env_file = Path(".env")
token = (
    os.getenv("OLIST_ACCESS_TOKEN", "").strip()
    or os.getenv("TINY_ACCESS_TOKEN", "").strip()
)

if not token and env_file.exists():
    for line in env_file.read_text(encoding="utf-8").splitlines():
        if line.startswith(("OLIST_ACCESS_TOKEN=", "TINY_ACCESS_TOKEN=")):
            token = line.split('=', 1)[1].strip()
            if token:
                break

def write_ci_fallback():
    print("[*] Ambiente de CI (GitHub Actions) detectado com token inválido. Escrevendo fallback para manter integridade do build...")
    fallback_path = Path("api/catalog/fallback-products.json")
    output_file = Path("storage/products-cache.json")
    output_file.parent.mkdir(parents=True, exist_ok=True)
    if fallback_path.exists():
        try:
            fallback_data = json.loads(fallback_path.read_text(encoding="utf-8"))
            payload = {
                'total': len(fallback_data),
                'timestamp': __import__('datetime').datetime.now().isoformat(),
                'itens': fallback_data
            }
            output_file.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
            print("[+] Fallback gravado com sucesso no cache do CI.")
            return
        except Exception as exc:
            print(f"[!] Erro ao ler fallback: {exc}")
    
    # Se falhar ou arquivo não existir, grava estrutura mínima válida
    payload = {
        'total': 0,
        'timestamp': __import__('datetime').datetime.now().isoformat(),
        'itens': []
    }
    output_file.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    print("[+] Cache mínimo gravado no CI.")


def handle_ci_failure(reason: str):
    if os.getenv("FAIL_ON_CI_FALLBACK", "").strip().lower() in {"1", "true", "yes"}:
        print(f"[!] Sincronização de produção interrompida: {reason}", file=sys.stderr)
        sys.exit(2)
    write_ci_fallback()
    sys.exit(0)

if not token:
    print("[!] Token não encontrado!")
    if os.getenv("GITHUB_ACTIONS") == "true":
        handle_ci_failure("token ausente")
    sys.exit(1)


def refresh_access_token() -> str:
    client_id = (
        os.getenv("OLIST_CLIENT_ID", "").strip()
        or os.getenv("TINY_CLIENT_ID", "").strip()
        or os.getenv("CLIENT_ID_API_OLIST", "").strip()
    )
    client_secret = (
        os.getenv("OLIST_CLIENT_SECRET", "").strip()
        or os.getenv("TINY_CLIENT_SECRET", "").strip()
        or os.getenv("CLIENT_SECRET_OLIST", "").strip()
    )
    refresh_token = (
        os.getenv("OLIST_REFRESH_TOKEN", "").strip()
        or os.getenv("TINY_REFRESH_TOKEN", "").strip()
    )
    token_url = os.getenv(
        "OLIST_TOKEN_URL",
        os.getenv("TINY_TOKEN_URL", "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token"),
    )

    if not (client_id and client_secret and refresh_token):
        return ""

    body = urllib.parse.urlencode({
        "grant_type": "refresh_token",
        "client_id": client_id,
        "client_secret": client_secret,
        "refresh_token": refresh_token,
    }).encode("utf-8")
    req = urllib.request.Request(
        token_url,
        data=body,
        headers={"Content-Type": "application/x-www-form-urlencoded"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as response:
            payload = json.loads(response.read())
        return str(payload.get("access_token") or "").strip()
    except urllib.error.HTTPError as e:
        print(f"[!] Erro no refresh: {e.code} {e.reason}")
        try:
            print("[!] Corpo da resposta de erro:", e.read().decode("utf-8"))
        except Exception:
            pass
        raise e


# Buscar todos os produtos
all_products = []
offset = 0
limit = 100
page = 1
refreshed = False

while True:
    url = f"https://api.tiny.com.br/public-api/v3/produtos?limit={limit}&offset={offset}"

    try:
        req = urllib.request.Request(url)
        req.add_header('Authorization', f'Bearer {token}')

        with urllib.request.urlopen(req, timeout=30) as response:
            data = json.loads(response.read())
    except urllib.error.HTTPError as e:
        if e.code == 401 and not refreshed:
            print("[!] Access token expirado; tentando renovar via refresh token...")
            try:
                token = refresh_access_token()
            except Exception as refresh_exc:
                print(f"[!] Falha ao renovar token: {refresh_exc}")
                token = ""
            refreshed = True
            if token:
                continue
        print(f"[!] Erro HTTP na página {page}: {e.code} {e.reason}")
        if os.getenv("GITHUB_ACTIONS") == "true":
            handle_ci_failure(f"HTTP {e.code} na página {page}")
        sys.exit(1)
    except Exception as e:
        print(f"[!] Erro na página {page}: {e}")
        if os.getenv("GITHUB_ACTIONS") == "true":
            handle_ci_failure(f"erro na página {page}: {e}")
        sys.exit(1)

    if 'itens' not in data or not data['itens']:
        print(f"[*] Fim dos produtos (página {page})")
        break

    items = data['itens']
    all_products.extend(items)

    print(f"[+] Página {page}: {len(items)} produtos (total: {len(all_products)})")

    if len(items) < limit:
        break

    offset += limit
    page += 1

if not all_products:
    print("[!] Nenhum produto retornado; mantendo cache anterior e falhando com segurança.")
    if os.getenv("GITHUB_ACTIONS") == "true":
        handle_ci_failure("nenhum produto retornado")
    sys.exit(1)

# Salvar em JSON
output_file = Path("storage/products-cache.json")
output_file.parent.mkdir(parents=True, exist_ok=True)

with open(output_file, 'w', encoding='utf-8') as f:
    json.dump({
        'total': len(all_products),
        'timestamp': __import__('datetime').datetime.now().isoformat(),
        'itens': all_products
    }, f, ensure_ascii=False, indent=2)

print(f"\n[+] SUCESSO!")
print(f"    Total: {len(all_products)} produtos")
print(f"    Salvo em: {output_file}")
