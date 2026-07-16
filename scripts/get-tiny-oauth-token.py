#!/usr/bin/env python3
"""
Helper OAuth2 para gerar refresh_token da Tiny ERP / Olist.

Abre o browser no fluxo de autorização, captura o code no callback local,
troca pelo token e salva automaticamente nos secrets do GitHub.

Uso:
    python scripts/get-tiny-oauth-token.py

Variáveis de ambiente necessárias (ou em .env.local):
    OLIST_CLIENT_ID      ou  TINY_CLIENT_ID
    OLIST_CLIENT_SECRET  ou  TINY_CLIENT_SECRET
    URL_REDIRCT_OLIST            (opcional — URL de redirect registrada no app Tiny)
"""

import http.server
import json
import os
import sys
import subprocess
import threading
import time
import webbrowser
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import parse_qs, urlencode, urlparse
from urllib.request import Request, urlopen

# ---------------------------------------------------------------------------
TOKEN_URL  = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token"
AUTH_URL   = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth"
CALLBACK_PORT = 9753
CALLBACK_PATH = "/callback"
REDIRECT_URI  = f"http://localhost:{CALLBACK_PORT}{CALLBACK_PATH}"
# ---------------------------------------------------------------------------

_auth_code: list[str] = []  # thread-safe via append


def load_env_local():
    """Carrega .env.local se existir."""
    env_file = Path(".env.local")
    if not env_file.exists():
        env_file = Path(".env")
    if env_file.exists():
        for line in env_file.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                k, _, v = line.partition("=")
                os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))


def get_credentials():
    client_id     = os.getenv("OLIST_CLIENT_ID") or os.getenv("TINY_CLIENT_ID") or os.getenv("CLIENT_ID_API_OLIST")
    client_secret = os.getenv("OLIST_CLIENT_SECRET") or os.getenv("TINY_CLIENT_SECRET") or os.getenv("CLIENT_SECRET_OLIST")
    redirect_uri  = os.getenv("URL_REDIRCT_OLIST") or REDIRECT_URI

    if not client_id:
        client_id = input("OLIST_CLIENT_ID (client_id do app Tiny): ").strip()
    if not client_secret:
        client_secret = input("OLIST_CLIENT_SECRET: ").strip()

    return client_id, client_secret, redirect_uri


class CallbackHandler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        parsed = urlparse(self.path)
        if parsed.path == CALLBACK_PATH:
            params = parse_qs(parsed.query)
            code = params.get("code", [None])[0]
            error = params.get("error", [None])[0]

            if code:
                _auth_code.append(code)
                body = b"<h2>Autorizado! Pode fechar esta aba.</h2>"
                self.send_response(200)
            else:
                body = f"<h2>Erro: {error}</h2>".encode()
                self.send_response(400)

            self.send_header("Content-Type", "text/html; charset=utf-8")
            self.end_headers()
            self.wfile.write(body)
        else:
            self.send_response(404)
            self.end_headers()

    def log_message(self, *args):
        pass  # silenciar logs HTTP do servidor local


def start_callback_server():
    server = http.server.HTTPServer(("localhost", CALLBACK_PORT), CallbackHandler)
    t = threading.Thread(target=server.serve_forever, daemon=True)
    t.start()
    return server


def exchange_code_for_tokens(code: str, client_id: str, client_secret: str, redirect_uri: str) -> dict:
    payload = {
        "grant_type": "authorization_code",
        "code": code,
        "client_id": client_id,
        "client_secret": client_secret,
        "redirect_uri": redirect_uri,
    }
    req = Request(
        TOKEN_URL,
        data=urlencode(payload).encode(),
        headers={"Content-Type": "application/x-www-form-urlencoded"},
        method="POST",
    )
    try:
        with urlopen(req, timeout=30) as resp:
            return json.loads(resp.read())
    except HTTPError as exc:
        body = exc.read().decode()
        print(f"\nERRO HTTP {exc.code}: {body[:500]}")
        return {}


def set_github_secret(name: str, value: str):
    """Tenta definir secret no GitHub via gh CLI."""
    try:
        result = subprocess.run(
            ["gh", "secret", "set", name, "--body", value],
            capture_output=True, text=True
        )
        if result.returncode == 0:
            print(f"  ✅ GitHub secret {name} atualizado!")
        else:
            print(f"  ⚠️  Não foi possível atualizar via gh CLI: {result.stderr.strip()}")
            print(f"     Defina manualmente: Settings → Secrets → {name}")
    except FileNotFoundError:
        print(f"  ⚠️  gh CLI não encontrado. Defina manualmente: Settings → Secrets → {name}")


def main():
    load_env_local()

    # Trava: só executa se chamado explicitamente com --run
    # Evita abertura acidental do browser por outros scripts ou automações
    if "--run" not in sys.argv:
        print("[get-tiny-oauth-token] Script legado Olist. Use --run para executar manualmente.")
        print("  O pipeline principal (Shopee+TikTok) não usa este script.")
        sys.exit(0)

    client_id, client_secret, redirect_uri = get_credentials()
    if not client_id or not client_secret:
        print("ERRO: OLIST_CLIENT_ID e OLIST_CLIENT_SECRET são obrigatórios.")
        sys.exit(1)

    # Usar redirect local para callback (só se o app Tiny aceitar localhost)
    # Se a URL registrada for outra, o usuário precisa colar o code manualmente
    use_local_callback = redirect_uri.startswith("http://localhost") or "localhost" in redirect_uri

    auth_params = {
        "client_id": client_id,
        "redirect_uri": redirect_uri,
        "response_type": "code",
        "scope": "openid",
    }
    auth_link = f"{AUTH_URL}?{urlencode(auth_params)}"

    print("\n" + "="*60)
    print("  GERADOR DE TOKEN TINY / OLIST")
    print("="*60)

    if use_local_callback:
        print(f"\n  Iniciando servidor de callback em localhost:{CALLBACK_PORT}...")
        server = start_callback_server()

    print(f"\n  Abrindo browser para autorização...")
    print(f"  URL: {auth_link}\n")
    webbrowser.open(auth_link)

    if use_local_callback:
        print("  Aguardando autorização no browser (60s)...")
        for _ in range(60):
            if _auth_code:
                break
            time.sleep(1)
        server.shutdown()

        if not _auth_code:
            print("\n  Timeout! Cole o 'code' da URL de redirecionamento:")
            code = input("  code=").strip()
        else:
            code = _auth_code[0]
            print(f"  Code recebido!")
    else:
        print(f"  Depois de autorizar, você será redirecionado para:")
        print(f"  {redirect_uri}?code=XXXX...")
        print()
        code = input("  Cole o valor do parâmetro 'code' aqui: ").strip()

    if not code:
        print("ERRO: code não fornecido.")
        sys.exit(1)

    print("\n  Trocando code por tokens...")
    tokens = exchange_code_for_tokens(code, client_id, client_secret, redirect_uri)

    access_token  = tokens.get("access_token")
    refresh_token = tokens.get("refresh_token")
    expires_in    = tokens.get("expires_in", "?")

    if not access_token:
        print("ERRO: Não foi possível obter access_token.")
        print(json.dumps(tokens, indent=2, ensure_ascii=False))
        sys.exit(1)

    print(f"\n  ✅ Tokens obtidos! (expires_in: {expires_in}s)")
    print(f"\n  access_token : {access_token[:40]}...")
    if refresh_token:
        print(f"  refresh_token: {refresh_token[:40]}...")

    print("\n  Salvando nos GitHub Secrets...")
    set_github_secret("OLIST_ACCESS_TOKEN", access_token)
    if refresh_token:
        set_github_secret("OLIST_REFRESH_TOKEN", refresh_token)
        set_github_secret("TINY_ACCESS_TOKEN", access_token)

    print("\n" + "="*60)
    print("  CONCLUÍDO!")
    if refresh_token:
        print("  Os secrets foram atualizados. Você pode rodar o pipeline novamente.")
    else:
        print("  Apenas access_token obtido (sem refresh_token).")
        print("  Adicione OLIST_ACCESS_TOKEN manualmente nos secrets do GitHub.")
    print("="*60 + "\n")


if __name__ == "__main__":
    main()
