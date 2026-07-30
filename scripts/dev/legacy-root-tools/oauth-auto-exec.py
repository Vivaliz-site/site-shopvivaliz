#!/usr/bin/env python3
"""
OAuth Automático - Sem Interação do Usuário
Executa com credenciais hardcoded (apenas para este fluxo)
"""

import asyncio
import json
import re
from pathlib import Path
from urllib.parse import urlparse, parse_qs

try:
    from playwright.async_api import async_playwright
except ImportError:
    print("❌ Playwright não instalado! Execute: pip install playwright && playwright install")
    exit(1)


async def main():
    """Executar OAuth flow completo automaticamente"""

    print("=" * 70)
    print("[*] OAuth Tiny/Olist - Execucao Automatica")
    print("=" * 70)

    # Carregar .env
    env_file = Path("C:/site-shopvivaliz/.env")
    if not env_file.exists():
        print(f"[!] .env nao encontrado: {env_file}")
        return False

    env = {}
    for line in env_file.read_text().split('\n'):
        if '=' in line and not line.startswith('#'):
            k, v = line.split('=', 1)
            env[k.strip()] = v.strip()

    client_id = env.get('OLIST_CLIENT_ID', '')
    client_secret = env.get('OLIST_CLIENT_SECRET', '')
    redirect_uri = env.get('URL_REDIRCT_OLIST', 'https://shopvivaliz.com.br/olist/callback.php')

    if not client_id or not client_secret:
        print("[!] CLIENT_ID ou CLIENT_SECRET nao configurados")
        return False

    print(f"\n[+] Client ID: {client_id[:50]}...")
    print(f"[+] Redirect: {redirect_uri}")

    # Credenciais - CONFIGURE ANTES DE USAR
    email = "atendimento@shopvivaliz.com.br"
    password = "CONFIGURE_SENHA_AQUI"

    print(f"\n[*] Usando: {email}")

    # Playwright
    print("\n[*] Iniciando navegador Chromium...")
    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=False)
        page = await browser.new_page()

        # URL OAuth
        auth_url = (
            "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth?"
            f"client_id={client_id}&redirect_uri={redirect_uri}&response_type=code&scope=openid"
        )

        print(f"[*] Navegando para OAuth...")
        await page.goto(auth_url, wait_until="networkidle")

        try:
            # Aguardar email field
            print("[*] Aguardando campo de email...")
            email_field = await page.query_selector('input[name="username"]')
            if not email_field:
                email_field = await page.query_selector('input[type="text"]')

            if email_field:
                print(f"[+] Preenchendo email...")
                await email_field.fill(email)
                await page.press('input[name="username"], input[type="text"]', 'Enter')

            # Aguardar password field
            print("[*] Aguardando campo de senha...")
            await page.wait_for_selector('input[name="password"], input[type="password"]', timeout=10000)

            password_field = await page.query_selector('input[name="password"]')
            if not password_field:
                password_field = await page.query_selector('input[type="password"]')

            if password_field:
                print("[+] Preenchendo senha...")
                await password_field.fill(password)
                await page.press('input[name="password"], input[type="password"]', 'Enter')

            # Aguardar possível página de autorização
            print("[*] Aguardando autorizacao...")
            await page.wait_for_timeout(2000)

            # Clicar botão Autorizar se existir
            auth_btn = None
            try:
                auth_btn = await page.query_selector('button:has-text("Autorizar")')
            except:
                pass

            if not auth_btn:
                try:
                    auth_btn = await page.query_selector('button:has-text("Permitir")')
                except:
                    pass

            if auth_btn:
                print("[+] Clicando 'Autorizar'...")
                await auth_btn.click()

            # Aguardar redirect com code
            print("[*] Aguardando redirect com codigo...")
            await page.wait_for_url(lambda url: "code=" in url, timeout=15000)

            final_url = page.url
            print(f"[+] Redirect recebido!")

            # Extrair código
            parsed = urlparse(final_url)
            code = parse_qs(parsed.query).get('code', [None])[0]

            if not code:
                print("[!] Codigo nao extraido da URL")
                await browser.close()
                return False

            print(f"[*] Codigo: {code[:40]}...")

        except Exception as e:
            print(f"[!] Erro no fluxo browser: {e}")
            await browser.close()
            return False

        await browser.close()

    # Trocar código por token
    print("\n[*] Trocando codigo por token...")

    import urllib.request
    import urllib.parse

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
    except Exception as e:
        print(f"[!] Erro ao trocar codigo: {e}")
        return False

    if 'access_token' not in token_data:
        print(f"[!] Token nao obtido:")
        print(json.dumps(token_data, indent=2, ensure_ascii=False))
        return False

    access_token = token_data['access_token']
    refresh_token = token_data.get('refresh_token', '')
    expires_in = token_data.get('expires_in', 14400)

    print(f"\n[+] Token obtido!")
    print(f"    Access: {access_token[:50]}...")
    print(f"    Expira: {expires_in / 3600:.1f}h")

    # Salvar em .env
    print(f"\n[*] Salvando em .env...")

    content = env_file.read_text()

    keys = ['OLIST_ACCESS_TOKEN', 'OLIST_REFRESH_TOKEN', 'TINY_ACCESS_TOKEN', 'TINY_REFRESH_TOKEN']
    values = [access_token, refresh_token, access_token, refresh_token]

    for key, val in zip(keys, values):
        if f"{key}=" in content:
            content = re.sub(f"^{key}=.*", f"{key}={val}", content, flags=re.MULTILINE)
        else:
            content += f"\n{key}={val}"

    env_file.write_text(content)

    print(f"\n[+] SUCESSO TOTAL!")
    print(f"    Token salvo em: {env_file}")
    print(f"    Teste: https://shopvivaliz.com.br/olist/test-token-v3.php")

    return True


if __name__ == "__main__":
    result = asyncio.run(main())
    exit(0 if result else 1)
