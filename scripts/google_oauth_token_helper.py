#!/usr/bin/env python3
"""
Google OAuth 2.0 Token Generator
Automates authorization flow for Google APIs without printing secrets.

Search Console scope is read-only by default. Broader Google scopes should be
requested explicitly only when the corresponding module is being configured.

Usage:
  python scripts/google_oauth_token_helper.py [--port 8080]
  python scripts/google_oauth_token_helper.py --exchange-code <code>
  python scripts/google_oauth_token_helper.py --write-token-file
"""

import argparse
import json
import os
import stat
import sys
import urllib.error
import urllib.parse
import urllib.request
import webbrowser
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path

DEFAULT_SCOPES = [
    "https://www.googleapis.com/auth/webmasters.readonly",
]
OPTIONAL_SCOPE_GROUPS = {
    "search-console-write": ["https://www.googleapis.com/auth/webmasters"],
    "merchant": ["https://www.googleapis.com/auth/content"],
    "tag-manager": [
        "https://www.googleapis.com/auth/tagmanager.edit.containers",
        "https://www.googleapis.com/auth/tagmanager.publish",
    ],
    "analytics": ["https://www.googleapis.com/auth/analytics.readonly"],
    "indexing": ["https://www.googleapis.com/auth/indexing"],
}
SENSITIVE_TOKEN_KEYS = {"access_token", "refresh_token", "id_token"}


def mask_value(value, keep=8):
    value = str(value or "")
    if not value:
        return ""
    if len(value) <= keep:
        return "<redacted>"
    return f"{value[:keep]}...<redacted>"


def sanitized_token_summary(tokens):
    return {
        "has_refresh_token": bool(tokens.get("refresh_token")),
        "has_access_token": bool(tokens.get("access_token")),
        "token_type": tokens.get("token_type", "Bearer"),
        "expires_in": tokens.get("expires_in", "N/A"),
        "scope_count": len(str(tokens.get("scope", "")).split()),
        "scopes": sorted(str(tokens.get("scope", "")).split()),
    }


def load_credentials():
    candidates = list((Path.home() / "Downloads").glob("client_secret*.json")) + list(
        (Path.home() / "Desktop").glob("client_secret*.json")
    )

    for path in sorted(candidates, key=lambda p: p.stat().st_mtime, reverse=True):
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
            sec = data.get("web") or data.get("installed")
            if sec and sec.get("client_id") and sec.get("client_secret"):
                return sec["client_id"], sec["client_secret"], path
        except Exception:
            continue

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
        headers={"Content-Type": "application/x-www-form-urlencoded"},
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            resp_data = resp.read().decode("utf-8")
            return json.loads(resp_data), None
    except urllib.error.HTTPError as exc:
        return None, f"HTTP {exc.code}"
    except Exception as exc:
        return None, type(exc).__name__


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
            <head><title>Autorizado com Sucesso - ShopVivaliz</title></head>
            <body style="font-family: system-ui; background:#0f172a; color:#f8fafc; display:flex; align-items:center; justify-content:center; min-height:100vh;">
                <main style="background:#1e293b; padding:32px; border-radius:16px; max-width:520px; text-align:center;">
                    <h1 style="color:#10b981;">Autorização concluída</h1>
                    <p>O código foi capturado. Nenhum token será exibido no navegador.</p>
                    <p>Você já pode fechar esta aba e retornar ao terminal.</p>
                </main>
            </body>
            </html>
            """
            self.wfile.write(html.encode("utf-8"))
        elif "error" in qs:
            OAuthCallbackHandler.captured_error = qs["error"][0]
            self.send_response(400)
            self.send_header("Content-type", "text/html; charset=utf-8")
            self.end_headers()
            self.wfile.write(b"<h1>Erro na autorizacao OAuth.</h1>")
        else:
            self.send_response(404)
            self.end_headers()


def build_scopes(extra_groups):
    scopes = list(DEFAULT_SCOPES)
    for group in extra_groups:
        if group not in OPTIONAL_SCOPE_GROUPS:
            raise ValueError(f"Grupo de escopo desconhecido: {group}")
        for scope in OPTIONAL_SCOPE_GROUPS[group]:
            if scope not in scopes:
                scopes.append(scope)
    return scopes


def write_tokens_securely(tokens):
    tokens_dir = Path(__file__).parent.parent / ".tokens"
    tokens_dir.mkdir(parents=True, exist_ok=True)
    os.chmod(tokens_dir, stat.S_IRWXU)
    token_file = tokens_dir / "google-oauth-tokens.json"
    tmp_file = token_file.with_suffix(".json.tmp")
    tmp_file.write_text(json.dumps(tokens, indent=2), encoding="utf-8")
    os.chmod(tmp_file, stat.S_IRUSR | stat.S_IWUSR)
    tmp_file.replace(token_file)
    return token_file


def run_local_flow(client_id, client_secret, scopes, port=8080, write_token_file=False):
    redirect_uri = f"http://localhost:{port}/"
    auth_params = {
        "client_id": client_id,
        "redirect_uri": redirect_uri,
        "response_type": "code",
        "scope": " ".join(scopes),
        "access_type": "offline",
        "prompt": "consent",
        "include_granted_scopes": "true",
    }
    auth_url = f"https://accounts.google.com/o/oauth2/v2/auth?{urllib.parse.urlencode(auth_params)}"

    print("=" * 60, flush=True)
    print("GOOGLE OAUTH 2.0 - GERADOR DE TOKENS", flush=True)
    print("=" * 60, flush=True)
    print(f"Client ID: {mask_value(client_id)}", flush=True)
    print(f"Redirect URI: {redirect_uri}", flush=True)
    print(f"Escopos solicitados: {len(scopes)}", flush=True)
    print("-" * 60, flush=True)

    try:
        server = HTTPServer(("localhost", port), OAuthCallbackHandler)
    except Exception as exc:
        print(f"Erro ao iniciar servidor na porta {port}: {type(exc).__name__}", flush=True)
        return False

    server.timeout = 180

    print("Abrindo navegador padrão...", flush=True)
    print("Caso não abra automaticamente, acesse a URL abaixo:", flush=True)
    print(auth_url, flush=True)
    print("-" * 60, flush=True)
    print("Aguardando autorização no navegador...", flush=True)

    try:
        webbrowser.open(auth_url)
    except Exception as exc:
        print(f"Aviso ao abrir navegador: {type(exc).__name__}", flush=True)

    while OAuthCallbackHandler.captured_code is None and OAuthCallbackHandler.captured_error is None:
        server.handle_request()

    server.server_close()

    if OAuthCallbackHandler.captured_error:
        print("\nFalha durante a autorização OAuth.", flush=True)
        return False

    code = OAuthCallbackHandler.captured_code
    if not code:
        print("\nNenhum código de autorização foi recebido.", flush=True)
        return False

    print("\nCódigo de autorização capturado com sucesso.", flush=True)
    print("Trocando código por tokens junto ao Google OAuth...", flush=True)

    tokens, err = exchange_code_for_tokens(client_id, client_secret, code, redirect_uri)
    if err:
        print(f"\nFalha na troca de tokens: {err}", flush=True)
        return False

    print("\n" + "=" * 60, flush=True)
    print("TOKENS GERADOS COM SUCESSO", flush=True)
    print("=" * 60, flush=True)
    print(json.dumps(sanitized_token_summary(tokens), indent=2, ensure_ascii=False), flush=True)
    print("Valores de token não são impressos. Cadastre-os diretamente no cofre de segredos.", flush=True)

    if write_token_file:
        token_file = write_tokens_securely(tokens)
        print(f"Tokens gravados com permissão 0600 em: {token_file}", flush=True)
    else:
        print("Arquivo de tokens não foi criado. Use --write-token-file apenas em máquina segura.", flush=True)

    return True


def parse_args(argv):
    parser = argparse.ArgumentParser(description="Generate Google OAuth tokens without printing secrets.")
    parser.add_argument("--port", type=int, default=8080)
    parser.add_argument("--exchange-code")
    parser.add_argument("--redirect-uri", default="https://developers.google.com/oauthplayground")
    parser.add_argument("--write-token-file", action="store_true")
    parser.add_argument(
        "--scope-group",
        action="append",
        choices=sorted(OPTIONAL_SCOPE_GROUPS.keys()),
        default=[],
        help="Optional extra scope group. Default only requests Search Console read-only.",
    )
    return parser.parse_args(argv)


def main():
    args = parse_args(sys.argv[1:])
    cid, sec, path = load_credentials()
    if not cid or not sec:
        print("Credenciais não encontradas. Configure GOOGLE_OAUTH_CLIENT_ID e GOOGLE_OAUTH_CLIENT_SECRET.")
        return 1

    if path:
        print(f"Credenciais OAuth carregadas de arquivo local: {path}")
    scopes = build_scopes(args.scope_group)

    if args.exchange_code:
        print(f"Trocando código manual com redirect_uri: {args.redirect_uri}")
        tokens, err = exchange_code_for_tokens(cid, sec, args.exchange_code, args.redirect_uri)
        if err:
            print(f"Erro: {err}")
            return 1
        print(json.dumps(sanitized_token_summary(tokens), indent=2, ensure_ascii=False))
        if args.write_token_file:
            token_file = write_tokens_securely(tokens)
            print(f"Tokens gravados com permissão 0600 em: {token_file}")
        else:
            print("Valores de token não são impressos nem gravados sem --write-token-file.")
        return 0

    success = run_local_flow(cid, sec, scopes, port=args.port, write_token_file=args.write_token_file)
    return 0 if success else 1


if __name__ == "__main__":
    sys.exit(main())
