#!/usr/bin/env python3
"""
Gera o TIKTOK_ACCESS_TOKEN via OAuth 2.0 TikTok Shop Open Platform.

Uso:
    # Limpa tokens antigos e gera novos, incluindo shop_id
    python scripts/tiktok-get-token.py --fresh

Precisa de (ja nos secrets ou em .env.local):
    TIKTOK_SERVICE_ID
    TIKTOK_APP_KEY
    TIKTOK_APP_SECRET
    TIKTOK_REDIRECT_URL   (opcional - usa localhost se ausente)
    TIKTOK_AUTH_REGION    (opcional - row ou us; padrao row)
"""
import hashlib
import hmac
import http.server
import json
import os
import subprocess
import sys
import threading
import time
import webbrowser
from urllib.parse import parse_qs, urlencode, urlparse
from urllib.request import Request, urlopen
from urllib.error import HTTPError
from pathlib import Path

# Configuracao

AUTH_URL_ROW = "https://services.tiktokshop.com/open/authorize"
AUTH_URL_US  = "https://services.tiktokshops.us/open/authorize"
TOKEN_URL    = "https://auth.tiktok-shops.com/api/v2/token/get"
API_URL      = "https://open-api.tiktokglobalshop.com"
LOCAL_PORT   = 8765
LOCAL_REDIRECT = f"http://localhost:{LOCAL_PORT}/callback"

_captured_code: list[str] = []
_captured_shop_cipher: list[str] = []


# Helpers

def load_env():
    for fname in [".env.local", ".env"]:
        p = Path(fname)
        if p.exists():
            for line in p.read_text(encoding="utf-8").splitlines():
                line = line.strip()
                if line and not line.startswith("#") and "=" in line:
                    k, _, v = line.partition("=")
                    os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))


def get_creds():
    service_id  = os.environ.get("TIKTOK_SERVICE_ID", "").strip()
    app_key     = os.environ.get("TIKTOK_APP_KEY", "").strip()
    app_secret  = os.environ.get("TIKTOK_APP_SECRET", "").strip()
    redirect    = os.environ.get("TIKTOK_REDIRECT_URL", LOCAL_REDIRECT).strip()
    auth_region = os.environ.get("TIKTOK_AUTH_REGION", "row").strip().lower()
    if not service_id or not app_key or not app_secret:
        print("ERRO: TIKTOK_SERVICE_ID, TIKTOK_APP_KEY e TIKTOK_APP_SECRET sao obrigatorios.")
        sys.exit(1)
    if auth_region not in {"row", "us"}:
        print("ERRO: TIKTOK_AUTH_REGION invalido. Use 'row' ou 'us'.")
        sys.exit(1)
    return service_id, app_key, app_secret, redirect, auth_region


def build_auth_link(service_id: str, auth_region: str, state: str = "shopvivaliz") -> str:
    base_url = AUTH_URL_US if auth_region == "us" else AUTH_URL_ROW
    return f"{base_url}?{urlencode({'service_id': service_id, 'state': state})}"


# Servidor local de callback

class _Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        parsed = urlparse(self.path)
        if "/callback" in parsed.path:
            params = parse_qs(parsed.query)
            code  = params.get("code",      [None])[0]
            shop_cipher = params.get("shop_cipher", [None])[0]
            error = params.get("message",   [None])[0] or params.get("error", [None])[0]
            if code:
                _captured_shop_cipher.append(shop_cipher)
                _captured_code.append(code)
                body = b"<h2 style='font-family:sans-serif;color:green'>Autorizado! Pode fechar esta aba.</h2>"
                self.send_response(200)
            else:
                body = f"<h2 style='font-family:sans-serif;color:red'>Erro: {error}</h2>".encode()
                self.send_response(400)
            self.send_header("Content-Type", "text/html; charset=utf-8")
            self.end_headers()
            self.wfile.write(body)
        else:
            self.send_response(404)
            self.end_headers()

    def log_message(self, *args):
        pass


def start_local_server():
    server = http.server.HTTPServer(("localhost", LOCAL_PORT), _Handler)
    t = threading.Thread(target=server.serve_forever, daemon=True)
    t.start()
    return server


# Troca de codigo por token

def exchange_code(auth_code: str, app_key: str, app_secret: str) -> dict:
    params = urlencode({
        "app_key":    app_key,
        "app_secret": app_secret,
        "auth_code":  auth_code,
        "grant_type": "authorized_code",
    })
    req  = Request(
        f"{TOKEN_URL}?{params}",
        method="GET",
    )
    try:
        with urlopen(req, timeout=30) as resp:
            return json.loads(resp.read())
    except HTTPError as e:
        return {"error": e.read().decode()[:500]}


def save_secret(name: str, value: str):
    try:
        result = subprocess.run(
            ["gh", "secret", "set", name, "--body", value],
            capture_output=True, text=True
        )
        if result.returncode == 0:
            print(f"  ✅ Secret {name} salvo no GitHub!")
        else:
            error_msg = result.stderr.strip()
            print(f"  ⚠️  Não foi possível salvar via gh CLI: {error_msg}")
            print(f"     Defina manualmente: Settings → Secrets → Actions → {name}")
    except FileNotFoundError:
        print(f"  ⚠️  gh CLI não encontrado. Defina o secret manualmente: Settings → Secrets → Actions → {name}")


def get_authorized_shops(app_key: str, app_secret: str, access_token: str) -> dict:
    """Chama /api/shop/get_authorized_shop para obter shop_id."""
    api_path = "/api/shop/get_authorized_shop"
    timestamp = str(int(time.time()))

    # https://partner.tiktokshop.com/doc/page/262811
    # Assinatura HMAC-SHA256
    base_string = f"{app_secret}{api_path}{timestamp}{app_secret}"
    sign = hmac.new(
        app_secret.encode(),
        base_string.encode(),
        hashlib.sha256
    ).hexdigest()

    params = urlencode({
        "app_key": app_key,
        "access_token": access_token,
        "sign": sign,
        "timestamp": timestamp,
    })
    req = Request(
        f"{API_URL}{api_path}?{params}",
        method="GET",
    )
    try:
        with urlopen(req, timeout=30) as resp:
            return json.loads(resp.read())
    except HTTPError as e:
        error_body = e.read().decode()
        print(f"ERRO API: {error_body}")
        return {"error": error_body[:500]}

# Main

def main():
    load_env()
    service_id, app_key, app_secret, redirect_uri, auth_region = get_creds()
    use_local = "localhost" in redirect_uri

    auth_link = build_auth_link(service_id, auth_region)

    print("\n" + "="*60)
    print("  TikTok Shop - Gerador de Access Token")
    print("="*60)
    print(f"\n  Service ID : {service_id}")
    print(f"  App Key    : {app_key}")
    print(f"  Regiao auth: {auth_region.upper()}")
    print(f"  Redirect   : {redirect_uri}")
    print(f"  Local CB   : {'Sim (servidor local)' if use_local else 'Nao (cole o auth_code)'}")

    if use_local:
        print(f"\n  Iniciando servidor local na porta {LOCAL_PORT}...")
        server = start_local_server()

    print(f"\n  Abrindo browser para autorizacao TikTok Shop...")
    print(f"  URL: {auth_link}\n")
    webbrowser.open(auth_link)

    auth_code = None
    shop_cipher = None

    if use_local:
        print("  Aguardando autorizacao no browser (90s)...")
        for _ in range(90):
            if _captured_code:
                break
            time.sleep(1)
        server.shutdown()
        auth_code = _captured_code[0] if _captured_code else None
        shop_cipher = _captured_shop_cipher[0] if _captured_shop_cipher else None

    if not auth_code:
        print("\n  Nao capturado automaticamente.")
        print("  Apos autorizar no browser, copie o 'code' da URL de redirecionamento.")
        print("  Exemplo: https://suaurl.com/callback?code=AQUI_O_CODE&...")
        auth_code = input("\n  Cole o auth_code: ").strip()

    if not auth_code:
        print("ERRO: auth_code nao fornecido.")
        sys.exit(1)

    if shop_cipher:
        print(f"\n  OK: shop_cipher capturado: {shop_cipher[:40]}...")
        print("  Salvando TIKTOK_SHOP_CIPHER nos GitHub Secrets...")
        save_secret("TIKTOK_SHOP_CIPHER", shop_cipher)

    print(f"\n  Trocando auth_code por access_token...")
    result = exchange_code(auth_code, app_key, app_secret)

    print("\n  Resposta TikTok:")
    print(json.dumps(result, indent=2, ensure_ascii=False)[:800])

    data = result.get("data", result)
    access_token  = data.get("access_token")
    refresh_token = data.get("refresh_token")
    expires_in    = data.get("access_token_expire_in") or data.get("expires_in", "?")

    if not access_token:
        print("\nERRO: Token nao obtido. Verifique a resposta acima.")
        sys.exit(1)

    print(f"\n  OK: access_token  : {access_token[:40]}...")
    print(f"  OK: expires_in    : {expires_in}s")
    if refresh_token:
        print(f"  OK: refresh_token : {refresh_token[:40]}...")

    print("\n  Salvando nos GitHub Secrets...")
    save_secret("TIKTOK_ACCESS_TOKEN", access_token)
    if refresh_token:
        save_secret("TIKTOK_REFRESH_TOKEN", refresh_token)

    # Passo final: obter shop_id
    print("\n  Verificando token e obtendo shop_id...")
    shop_info = get_authorized_shops(app_key, app_secret, access_token)

    if "data" in shop_info and shop_info["data"].get("shops"):
        shops = shop_info["data"]["shops"]
        print("\n  Lojas autorizadas:")
        for shop in shops:
            shop_id = shop.get("shop_id")
            shop_name = shop.get("shop_name")
            print(f"  - Nome: {shop_name}, ID: {shop_id}")
            if shop_id:
                print(f"    Salvando TIKTOK_SHOP_ID nos GitHub Secrets...")
                save_secret("TIKTOK_SHOP_ID", shop_id)
    else:
        print(f"\n  AVISO: Nao foi possivel obter o shop_id. Resposta: {json.dumps(shop_info)}")

    print("\nOK: Pipeline TikTok Shop pronto para rodar.")


if __name__ == "__main__":
    main()
