#!/usr/bin/env python3
"""
[LEGADO] Playwright autônomo: faz OAuth no Olist/Tiny e salva refresh_token no GitHub.

ATENÇÃO: Este script pertence ao pipeline Olist (LEGADO).
O novo pipeline ShopVivaliz usa Shopee + TikTok diretamente.
Este script NÃO deve ser executado automaticamente.
"""
import json
import os
import subprocess
import sys
import time
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urlencode, urlparse, parse_qs
from urllib.request import Request, urlopen

from playwright.sync_api import sync_playwright

CLIENT_ID     = os.environ.get("OLIST_CLIENT_ID", "")
CLIENT_SECRET = os.environ.get("OLIST_CLIENT_SECRET", "")
REDIRECT_URI  = os.environ.get("OLIST_REDIRECT_URI", "https://shopvivaliz.com.br/olist/callback.php")
TOKEN_URL     = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token"

if not CLIENT_ID or not CLIENT_SECRET:
    print("⚠️  Script legado Olist — não necessário para o pipeline Shopee+TikTok.")
    print("   Configure OLIST_CLIENT_ID e OLIST_CLIENT_SECRET se precisar do Olist.")
    sys.exit(0)

AUTH_URL = (
    "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth"
    f"?client_id={CLIENT_ID}"
    f"&redirect_uri={REDIRECT_URI.replace(':', '%3A').replace('/', '%2F')}"
    "&response_type=code&scope=openid"
)

# Perfil do Chrome do usuário (tem a sessão do Olist já logada)
CHROME_PROFILE = str(Path.home() / "AppData/Local/Google/Chrome/User Data")
CHROME_TEMP_PROFILE = str(Path(os.environ.get("TEMP", "C:/Temp")) / "pw_olist_profile")


def exchange_code(code: str) -> dict:
    payload = urlencode({
        "grant_type":    "authorization_code",
        "code":          code,
        "client_id":     CLIENT_ID,
        "client_secret": CLIENT_SECRET,
        "redirect_uri":  REDIRECT_URI,
    }).encode()
    req = Request(TOKEN_URL, data=payload,
                  headers={"Content-Type": "application/x-www-form-urlencoded"})
    try:
        with urlopen(req, timeout=30) as r:
            return json.loads(r.read())
    except HTTPError as e:
        body = e.read().decode()
        print(f"ERRO HTTP {e.code}: {body[:500]}")
        return {}


def set_github_secret(name: str, value: str):
    r = subprocess.run(["gh", "secret", "set", name, "--body", value],
                       capture_output=True, text=True)
    mark = "✅" if r.returncode == 0 else "⚠️ "
    msg  = "ok" if r.returncode == 0 else r.stderr.strip()[:80]
    print(f"  {mark} {name}: {msg}")


def main():
    captured_code = []

    print("=== Playwright OAuth Olist ===")
    print(f"Perfil Chrome: {CHROME_PROFILE}")
    print("Abrindo browser com sessão do usuário...\n")

    with sync_playwright() as p:
        # Tentar usar perfil real do Chrome (tem sessão logada)
        try:
            context = p.chromium.launch_persistent_context(
                user_data_dir=CHROME_TEMP_PROFILE,
                channel="chrome",
                headless=False,
                args=["--disable-blink-features=AutomationControlled"],
                ignore_default_args=["--enable-automation"],
            )
            print("Browser iniciado com perfil temporário.")
        except Exception as e:
            print(f"Chrome não disponível ({e}), usando Chromium...")
            context = p.chromium.launch_persistent_context(
                user_data_dir=CHROME_TEMP_PROFILE,
                headless=False,
            )

        page = context.new_page()

        # Interceptar a URL de redirect antes de carregar
        def handle_route(route):
            url = route.request.url
            if "dev.shopvivaliz.com.br/olist/callback.php" in url:
                parsed = urlparse(url)
                params = parse_qs(parsed.query)
                code = params.get("code", [None])[0]
                if code:
                    captured_code.append(code)
                    print(f"\n✅ Code capturado: {code[:30]}...")
                route.abort()  # Não precisa carregar a página
            else:
                route.continue_()

        page.route("**/*", handle_route)

        # Navegar para a URL de autorização
        print(f"Navegando para: {AUTH_URL[:80]}...")
        try:
            page.goto(AUTH_URL, timeout=15000)
        except Exception:
            pass  # O abort da rota pode causar erro de navegação

        # Aguardar code por até 3 minutos
        print("Aguardando autorização (3 min)...")
        print("Se aparecer tela de login, entre com suas credenciais Olist.\n")
        deadline = time.time() + 180
        while time.time() < deadline:
            if captured_code:
                break
            # Verificar URL atual por se a interceptação não pegou
            try:
                current_url = page.url
                if "callback.php" in current_url and "code=" in current_url:
                    parsed = urlparse(current_url)
                    params = parse_qs(parsed.query)
                    code = params.get("code", [None])[0]
                    if code and code not in captured_code:
                        captured_code.append(code)
                        print(f"\n✅ Code na URL: {code[:30]}...")
                        break
            except Exception:
                pass
            time.sleep(1)

        context.close()

    if not captured_code:
        print("\n❌ Timeout — code não capturado.")
        sys.exit(1)

    code = captured_code[0]
    print(f"\nTrocando code por tokens...")
    tokens = exchange_code(code)

    access_token  = tokens.get("access_token")
    refresh_token = tokens.get("refresh_token")
    expires_in    = tokens.get("expires_in", "?")

    if not access_token:
        print("Falha:", json.dumps(tokens, indent=2))
        sys.exit(1)

    print(f"\n✅ access_token : {access_token[:50]}...")
    if refresh_token:
        print(f"✅ refresh_token: {refresh_token[:50]}...")
    print(f"   expires_in   : {expires_in}s")

    print("\nSalvando nos GitHub Secrets...")
    set_github_secret("OLIST_ACCESS_TOKEN",  access_token)
    set_github_secret("TINY_ACCESS_TOKEN",   access_token)
    if refresh_token:
        set_github_secret("OLIST_REFRESH_TOKEN", refresh_token)
        set_github_secret("TINY_REFRESH_TOKEN",  refresh_token)

    print("\n✅ Concluído! Todos os tokens salvos nos secrets.")
    return access_token, refresh_token


if __name__ == "__main__":
    main()
