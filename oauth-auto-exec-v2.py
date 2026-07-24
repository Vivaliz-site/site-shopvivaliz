#!/usr/bin/env python3
"""
Automação OAuth Tiny/Olist com Playwright - Versão não-interativa
Use: python oauth-auto-exec-v2.py [email] [senha]
Ou configure: TINY_EMAIL e TINY_PASSWORD como variáveis de ambiente
"""

import asyncio
import json
import os
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


async def oauth_login_and_get_code(email, password, client_id, redirect_uri):
    """Fazer login OAuth e pegar código"""
    print("🌐 Abrindo navegador (Chromium)...")

    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=False)
        page = await browser.new_page()

        # URL de auth
        auth_url = (
            "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth?"
            + "&".join([
                f"client_id={client_id}",
                f"redirect_uri={redirect_uri}",
                "response_type=code",
                "scope=openid+email+offline_access",
            ])
        )

        print(f"📍 Navegando para Tiny OAuth...")
        await page.goto(auth_url)

        print("⏳ Aguardando página de login carregar...")
        try:
            await page.wait_for_selector('input[name="username"], input[type="text"]', timeout=10000)
        except Exception:
            print("⚠️  Campo de username não encontrado, tentando aguardar...")
            await page.wait_for_timeout(2000)

        # Preencher email/username
        print(f"📝 Preenchendo email: {email}")
        username_field = await page.query_selector('input[name="username"]')
        if not username_field:
            username_field = await page.query_selector('input[type="text"]')
        if username_field:
            await username_field.fill(email)
            await page.press('input[name="username"], input[type="text"]', 'Enter')
        else:
            print("⚠️  Não conseguiu encontrar campo de email, talvez já esteja logado...")

        # Aguardar campo de senha
        print("⏳ Aguardando campo de senha...")
        try:
            await page.wait_for_selector('input[name="password"], input[type="password"]', timeout=10000)
        except Exception:
            print("⚠️  Campo de senha não encontrado em 10s, aguardando mais...")
            await page.wait_for_timeout(3000)

        # Preencher senha
        print("🔐 Preenchendo senha...")
        password_field = await page.query_selector('input[name="password"]')
        if not password_field:
            password_field = await page.query_selector('input[type="password"]')
        if password_field:
            await password_field.fill(password)
            await page.press('input[name="password"], input[type="password"]', 'Enter')
        else:
            print("⚠️  Campo de senha não encontrado, clicando em Enter...")
            await page.press('body', 'Enter')

        # Aguardar autorização (pode pedir permissão)
        print("⏳ Aguardando página de autorização ou redirect...")
        await page.wait_for_timeout(3000)

        # Procurar botão "Autorizar" ou "Permitir"
        auth_buttons = await page.query_selector_all('button')
        auth_btn = None
        for btn in auth_buttons:
            text = await btn.text_content()
            if text and any(x in text.lower() for x in ['autorizar', 'permitir', 'allow', 'approve']):
                auth_btn = btn
                break

        if auth_btn:
            print("🔓 Clicando em botão de autorização...")
            await auth_btn.click()
        else:
            print("⚠️  Botão de autorização não encontrado, aguardando redirect...")

        # Aguardar redirect com código
        print("⏳ Aguardando redirect com código...")
        try:
            await page.wait_for_url(lambda url: "code=" in url, timeout=15000)
        except Exception as e:
            print(f"⚠️  Timeout aguardando redirect: {e}")
            print(f"   URL atual: {page.url}")

        final_url = page.url
        print(f"✅ Redirect capturado!")

        # Extrair código
        try:
            parsed = urlparse(final_url)
            code = parse_qs(parsed.query).get('code', [None])[0]
        except Exception as e:
            print(f"❌ Erro ao extrair código: {e}")
            code = None

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
    print("═" * 70)
    print("OAuth Tiny/Olist - Automação com Playwright (v2 - Não-Interativo)")
    print("═" * 70)

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

    # Obter credenciais
    email = sys.argv[1] if len(sys.argv) > 1 else os.getenv('TINY_EMAIL', 'atendimento@shopvivaliz.com.br')
    password = sys.argv[2] if len(sys.argv) > 2 else os.getenv('TINY_PASSWORD', '')

    if not password:
        print("❌ Senha vazia! Forneça via argumento ou variável TINY_PASSWORD")
        print(f"\nUso:")
        print(f"  python oauth-auto-exec-v2.py {email} <senha>")
        print(f"  ou: $env:TINY_PASSWORD='sua_senha'; python oauth-auto-exec-v2.py")
        return

    print(f"\n🔑 Credenciais:")
    print(f"  Email: {email}")

    # Fazer login OAuth
    code = await oauth_login_and_get_code(email, password, client_id, redirect_uri)

    if not code:
        print("❌ Não foi possível obter código OAuth")
        return

    print(f"✅ Código obtido: {code[:40]}...")

    # Trocar código por token
    token_data = await exchange_code_for_token(code, client_id, client_secret, redirect_uri)

    if not token_data or 'access_token' not in token_data:
        print(f"❌ Erro ao obter token:")
        print(json.dumps(token_data, indent=2, ensure_ascii=False))
        return

    access_token = token_data['access_token']
    refresh_token = token_data.get('refresh_token', '')
    expires_in = token_data.get('expires_in', 14400)

    print(f"\n🎉 Token obtido com sucesso!")
    print(f"  Access Token: {access_token[:50]}...")
    print(f"  Refresh Token: {refresh_token[:50] if refresh_token else 'null'}...")
    print(f"  Expira em: {expires_in / 3600:.1f} horas")

    # Salvar em .env
    await save_token_to_env(access_token, refresh_token)

    print(f"\n✅ PRONTO!")
    print(f"  Tokens salvos em .env")
    print(f"  Daemon será executado no próximo cron (a cada 6 horas)")
    print(f"  Ou faça git push para sincronizar com VM")


if __name__ == "__main__":
    asyncio.run(main())
