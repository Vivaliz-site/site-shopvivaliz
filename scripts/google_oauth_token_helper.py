#!/usr/bin/env python3
"""
Google OAuth 2.0 Token Generator
Automates authorization flow for Google APIs:
- Merchant Center (https://www.googleapis.com/auth/content)
- Tag Manager (edit & publish)
- Search Console (webmasters)
- Google Analytics 4 (analytics.readonly)
- Indexing API (indexing)

Usage:
  python scripts/google_oauth_token_helper.py [--port 8080] [--exchange-code <code>]
"""

import os
import sys
import json
import urllib.parse
import urllib.request
import webbrowser
import threading
from http.server import HTTPServer, BaseHTTPRequestHandler
from pathlib import Path

DEFAULT_SCOPES = [
    "https://www.googleapis.com/auth/content",
    "https://www.googleapis.com/auth/tagmanager.edit.containers",
    "https://www.googleapis.com/auth/tagmanager.publish",
    "https://www.googleapis.com/auth/webmasters",
    "https://www.googleapis.com/auth/analytics.readonly",
    "https://www.googleapis.com/auth/indexing",
]

def load_credentials():
    # Check default download folder
    candidates = list((Path.home() / "Downloads").glob("client_secret*.json")) + \
                 list((Path.home() / "Desktop").glob("client_secret*.json"))
    
    for path in sorted(candidates, key=lambda p: p.stat().st_mtime, reverse=True):
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
            sec = data.get("web") or data.get("installed")
            if sec and sec.get("client_id") and sec.get("client_secret"):
                return sec["client_id"], sec["client_secret"], path
        except Exception:
            continue
    
    # Fallback to env
    cid = os.getenv("GOOGLE_OAUTH_CLIENT_ID", "")
    secret = os.getenv("GOOGLE_OAUTH_CLIENT_SECRET", "")
    return cid, secret, None

def exchange_code_for_tokens(client_id, client_secret, code, redirect_uri):
    token_url = "https://oauth2.googleapis.com/token"
    payload = {
        "code": code,
        "client_id": client_id,
        "client_secret": client_secret,
        "redirect_uri": redirect_uri,
        "grant_type": "authorization_code",
    }
    data = urllib.parse.urlencode(payload).encode("utf-8")
    req = urllib.request.Request(
        token_url,
        data=data,
        headers={"Content-Type": "application/x-www-form-urlencoded"}
    )
    try:
        with urllib.request.urlopen(req) as resp:
            resp_data = resp.read().decode("utf-8")
            return json.loads(resp_data), None
    except urllib.error.HTTPError as e:
        err_msg = e.read().decode("utf-8", errors="ignore")
        return None, f"HTTP {e.code}: {err_msg}"
    except Exception as e:
        return None, str(e)

class OAuthCallbackHandler(BaseHTTPRequestHandler):
    captured_code = None
    captured_error = None

    def log_message(self, format, *args):
        pass

    def do_GET(self):
        parsed = urllib.parse.urlparse(self.path)
        qs = urllib.parse.parse_qs(parsed.query)

        if "code" in qs:
            OAuthCallbackHandler.captured_code = qs["code"][0]
            self.send_response(200)
            self.send_header("Content-type", "text/html; charset=utf-8")
            self.end_headers()
            html = """
            <!DOCTYPE html>
            <html>
            <head>
                <title>Autorizado com Sucesso - ShopVivaliz</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; background: #0f172a; color: #f8fafc; }
                    .card { background: #1e293b; padding: 40px; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); text-align: center; max-width: 480px; border: 1px solid #334155; }
                    h1 { color: #10b981; margin-bottom: 12px; font-size: 24px; }
                    p { color: #94a3b8; font-size: 16px; line-height: 1.5; }
                    .badge { display: inline-block; background: #064e3b; color: #34d399; padding: 6px 14px; border-radius: 9999px; font-size: 14px; font-weight: 600; margin-bottom: 16px; }
                </style>
            </head>
            <body>
                <div class="card">
                    <div class="badge">&#10004; Sucesso</div>
                    <h1>Autorização Concluída</h1>
                    <p>O código de autorização foi capturado e os tokens estão sendo gerados. Você já pode fechar esta aba e retornar ao terminal.</p>
                </div>
            </body>
            </html>
            """
            self.wfile.write(html.encode("utf-8"))
        elif "error" in qs:
            OAuthCallbackHandler.captured_error = qs["error"][0]
            self.send_response(400)
            self.send_header("Content-type", "text/html; charset=utf-8")
            self.end_headers()
            self.wfile.write(f"<h1>Erro na autorização: {OAuthCallbackHandler.captured_error}</h1>".encode("utf-8"))
        else:
            self.send_response(404)
            self.end_headers()

def run_local_flow(client_id, client_secret, port=8080):
    redirect_uri = f"http://localhost:{port}/"
    scopes = " ".join(DEFAULT_SCOPES)
    auth_params = {
        "client_id": client_id,
        "redirect_uri": redirect_uri,
        "response_type": "code",
        "scope": scopes,
        "access_type": "offline",
        "prompt": "consent",
        "include_granted_scopes": "true",
    }
    auth_url = f"https://accounts.google.com/o/oauth2/v2/auth?{urllib.parse.urlencode(auth_params)}"

    print("=" * 60, flush=True)
    print("GOOGLE OAUTH 2.0 - GERADOR DE TOKENS", flush=True)
    print("=" * 60, flush=True)
    print(f"Client ID: {client_id[:20]}...apps.googleusercontent.com", flush=True)
    print(f"Redirect URI: {redirect_uri}", flush=True)
    print(f"Escopos solicitados: {len(DEFAULT_SCOPES)} escopos configurados", flush=True)
    print("-" * 60, flush=True)
    print(f"Iniciando servidor local na porta {port}...", flush=True)

    try:
        server = HTTPServer(("localhost", port), OAuthCallbackHandler)
    except Exception as e:
        print(f"Erro ao iniciar servidor na porta {port}: {e}", flush=True)
        return False

    server.timeout = 180  # 3 minutes timeout

    print(f"Abrindo navegador padrão...", flush=True)
    print(f"Caso não abra automaticamente, acesse a URL abaixo:", flush=True)
    print(auth_url, flush=True)
    print("-" * 60, flush=True)
    print("Aguardando autorização no navegador...", flush=True)

    try:
        webbrowser.open(auth_url)
    except Exception as e:
        print(f"Aviso ao abrir navegador: {e}")

    # Wait for request
    while OAuthCallbackHandler.captured_code is None and OAuthCallbackHandler.captured_error is None:
        server.handle_request()

    server.server_close()

    if OAuthCallbackHandler.captured_error:
        print(f"\n❌ Erro durante a autorização: {OAuthCallbackHandler.captured_error}", flush=True)
        return False

    code = OAuthCallbackHandler.captured_code
    if not code:
        print("\n❌ Nenhum código de autorização foi recebido (timeout ou cancelado).", flush=True)
        return False

    print("\n✅ Código de autorização capturado com sucesso!", flush=True)
    print("Trocando código por tokens junto ao Google OAuth...", flush=True)

    tokens, err = exchange_code_for_tokens(client_id, client_secret, code, redirect_uri)
    if err:
        print(f"\n❌ Falha na troca de tokens: {err}", flush=True)
        return False

    print("\n" + "=" * 60, flush=True)
    print("🎉 TOKENS GERADOS COM SUCESSO!", flush=True)
    print("=" * 60, flush=True)
    refresh_token = tokens.get("refresh_token", "N/A (Verifique se o parâmetro prompt=consent foi utilizado)")
    access_token = tokens.get("access_token", "N/A")
    expires_in = tokens.get("expires_in", "N/A")
    token_type = tokens.get("token_type", "Bearer")
    granted_scope = tokens.get("scope", "")

    print(f"Refresh Token: {refresh_token}", flush=True)
    print(f"Access Token: {access_token}", flush=True)
    print(f"Token Type: {token_type}", flush=True)
    print(f"Expires In: {expires_in} segundos", flush=True)
    print("-" * 60, flush=True)
    print(f"Granted Scopes:\n{granted_scope}", flush=True)
    print("=" * 60, flush=True)

    # Save to .tokens/google-oauth-tokens.json
    tokens_dir = Path(__file__).parent.parent / ".tokens"
    tokens_dir.mkdir(parents=True, exist_ok=True)
    token_file = tokens_dir / "google-oauth-tokens.json"
    token_file.write_text(json.dumps(tokens, indent=2), encoding="utf-8")
    print(f"Tokens salvos com segurança em: {token_file}", flush=True)

    return True

def main():
    cid, sec, path = load_credentials()
    if not cid or not sec:
        print("❌ Credenciais não encontradas. Configure GOOGLE_OAUTH_CLIENT_ID e GOOGLE_OAUTH_CLIENT_SECRET.")
        return 1

    if "--exchange-code" in sys.argv:
        idx = sys.argv.index("--exchange-code")
        if idx + 1 < len(sys.argv):
            code = sys.argv[idx + 1]
            redirect_uri = "https://developers.google.com/oauthplayground"
            if "--redirect-uri" in sys.argv:
                ridx = sys.argv.index("--redirect-uri")
                if ridx + 1 < len(sys.argv):
                    redirect_uri = sys.argv[ridx + 1]
            print(f"Trocando código manual com redirect_uri: {redirect_uri}")
            tokens, err = exchange_code_for_tokens(cid, sec, code, redirect_uri)
            if err:
                print(f"Erro: {err}")
                return 1
            print(json.dumps(tokens, indent=2))
            return 0

    port = 8080
    if "--port" in sys.argv:
        idx = sys.argv.index("--port")
        if idx + 1 < len(sys.argv):
            port = int(sys.argv[idx + 1])

    success = run_local_flow(cid, sec, port=port)
    return 0 if success else 1

if __name__ == "__main__":
    sys.exit(main())
