#!/usr/bin/env python3
"""
Automação OAuth Tiny/Olist com Playwright
Faz login automático e gera token V3
"""

import asyncio
import json
import re
import sys
from pathlib import Path
from urllib.parse import urlparse, parse_qs

try:
    from playwright.async_api import async_playwright
except ImportError:
    print("❌ Playwright não instalado!")
    print("Execute: pip install playwright && playwright install")
    sys.exit(1)


async def load_env():
    """Carregar variáveis de .env"""
    env = {}
    env_file = Path(__file__).parent / ".env"

    if not env_file.exists():
        print(f"❌ Arquivo .env não encontrado: {env_file}")
        return None

    with open(env_file) as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith('#'):
                continue
            if '=' in line:
                key, val = line.split('=', 1)
                env[key.strip()] = val.strip()

    return env


async def oauth_login_and_get_code(browser_type, email, password, client_id, redirect_uri):
    """Fazer login OAuth e pegar código"""
    print("🌐 Abrindo navegador...")

    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=False)  # headless=False = você vê
        page = await browser.new_page()

        # URL de auth
        auth_url = (
            "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth?"
            + "&".join([
                f"client_id={client_id}",
                f"redirect_uri={redirect_uri}",
                "response_type=code",
                "scope=openid",
            ])
        )

        print(f"📍 Navegando para: {auth_url[:80]}...")
        await page.goto(auth_url)

        print("⏳ Aguardando página de login carregar...")
        await page.wait_for_selector('input[name="username"], input[type="text"]', timeout=10000)

        # Preencher email/username
        print(f"📝 Preenchendo email: {email}")
        username_field = await page.query_selector('input[name="username"]')
        if not username_field:
            username_field = await page.query_selector('input[type="text"]')
        if username_field:
            await username_field.fill(email)

        # Clicar Próximo ou Enter
        await page.press('input[name="username"], input[type="text"]', 'Enter')

        # Aguardar campo de senha
        print("⏳ Aguardando campo de senha...")
        await page.wait_for_selector('input[name="password"], input[type="password"]', timeout=10000)

        # Preencher senha
        print("🔐 Preenchendo senha...")
        password_field = await page.query_selector('input[name="password"]')
        if not password_field:
            password_field = await page.query_selector('input[type="password"]')
        if password_field:
            await password_field.fill(password)

        # Clicar Sign In
        print("🔑 Clicando para fazer login...")
        sign_in_btn = await page.query_selector('button[type="submit"]')
        if sign_in_btn:
            await sign_in_btn.click()
        else:
            await page.press('input[type="password"]', 'Enter')

        # Aguardar autorização (pode pedir permissão)
        print("⏳ Aguardando página de autorização...")
        await page.wait_for_timeout(3000)

        # Procurar botão "Autorizar" ou "Permitir"
        auth_btn = await page.query_selector('button:has-text("Autorizar"), button:has-text("Permitir"), button:has-text("Approve")')
        if auth_btn:
            print("🔓 Clicando em 'Autorizar'...")
            await auth_btn.click()

        # Aguardar redirect com código
        print("⏳ Aguardando redirect com código...")
        await page.wait_for_url(lambda url: "code=" in url, timeout=15000)

        final_url = page.url
        print(f"✅ Redirect capturado: {final_url[:80]}...")

        # Extrair código
        parsed = urlparse(final_url)
        code = parse_qs(parsed.query).get('code', [None])[0]

        await browser.close()

        return code


async def exchange_code_for_token(code, client_id, client_secret, redirect_uri):
    """Trocar código por token"""
    import urllib.request
    import urllib.parse

    print(f"🔄 Trocando código por token...")

    token_url = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token"

    data = urllib.parse.urlencode({
        'grant_type': 'authorization_code',
        'client_id': client_id,
        'client_secret': client_secret,
        'code': code,
        'redirect_uri': redirect_uri,
    }).encode()

    try:
        req = urllib.request.Request(token_url, data=data)
        with urllib.request.urlopen(req, timeout=30) as response:
            token_data = json.loads(response.read())
            return token_data
    except Exception as e:
        print(f"❌ Erro ao trocar código: {e}")
        return None


async def save_token_to_env(access_token, refresh_token):
    """Salvar token em .env"""
    env_file = Path(__file__).parent / ".env"

    print(f"💾 Salvando token em .env...")

    content = env_file.read_text()

    keys = ['OLIST_ACCESS_TOKEN', 'OLIST_REFRESH_TOKEN', 'TINY_ACCESS_TOKEN', 'TINY_REFRESH_TOKEN']
    values = [access_token, refresh_token, access_token, refresh_token]

    for key, val in zip(keys, values):
        pattern = f"^{key}=.*"
        if re.search(pattern, content, re.MULTILINE):
            content = re.sub(pattern, f"{key}={val}", content, flags=re.MULTILINE)
        else:
            content += f"\n{key}={val}"

    env_file.write_text(content)
    print(f"✅ Token salvo em .env")


async def main():
    """Main"""
    print("═" * 60)
    print("OAuth Tiny/Olist - Automação com Playwright")
    print("═" * 60)

    env = await load_env()
    if not env:
        return

    client_id = env.get('OLIST_CLIENT_ID', '')
    client_secret = env.get('OLIST_CLIENT_SECRET', '')
    redirect_uri = env.get('URL_REDIRCT_OLIST', 'https://shopvivaliz.com.br/olist/callback.php')

    if not client_id or not client_secret:
        print("❌ CLIENT_ID ou CLIENT_SECRET não configurados em .env")
        return

    print(f"\n📋 Configuração:")
    print(f"  Client ID: {client_id[:40]}...")
    print(f"  Redirect URI: {redirect_uri}")

    # Pedir credenciais ao usuário
    print(f"\n🔑 Credenciais Olist/Tiny:")
    email = input("📧 Email/Usuário [atendimento@shopvivaliz.com.br]: ").strip() or "atendimento@shopvivaliz.com.br"
    password = input("🔐 Senha: ").strip()

    if not password:
        print("❌ Senha vazia")
        return

    # Fazer login OAuth
    code = await oauth_login_and_get_code(None, email, password, client_id, redirect_uri)

    if not code:
        print("❌ Não foi possível obter código OAuth")
        return

    print(f"✅ Código obtido: {code[:40]}...")

    # Trocar código por token
    token_data = await exchange_code_for_token(code, client_id, client_secret, redirect_uri)

    if not token_data or 'access_token' not in token_data:
        print(f"❌ Erro ao obter token: {token_data}")
        return

    access_token = token_data['access_token']
    refresh_token = token_data.get('refresh_token', '')
    expires_in = token_data.get('expires_in', 14400)

    print(f"\n🎉 Token obtido com sucesso!")
    print(f"  Access Token: {access_token[:50]}...")
    print(f"  Expira em: {expires_in / 3600:.1f} horas")

    # Salvar em .env
    await save_token_to_env(access_token, refresh_token)

    print(f"\n✅ PRONTO!")
    print(f"  Teste em: https://shopvivaliz.com.br/olist/test-token-v3.php")


if __name__ == "__main__":
    asyncio.run(main())
