#!/usr/bin/env python3
"""
Login OAuth Olist - Obtém authorization code e sincroniza produtos
Usando Python requests para simular o fluxo de login
"""
import os
import requests
import json
import re
from pathlib import Path
from datetime import datetime

BASE_URL = "https://dev.shopvivaliz.com.br"
OLIST_AUTH_URL = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth"
OLIST_TOKEN_URL = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token"

CLIENT_ID = os.getenv("OLIST_CLIENT_ID") or os.getenv("TINY_CLIENT_ID") or ""
CLIENT_SECRET = os.getenv("OLIST_CLIENT_SECRET") or os.getenv("TINY_CLIENT_SECRET") or ""

EMAIL = os.getenv("OLIST_EMAIL") or os.getenv("OLIST_USER") or os.getenv("EMAIL_USER") or ""
SENHA = os.getenv("OLIST_PASSWORD") or os.getenv("EMAIL_PASSWORD") or ""

print("\n" + "="*70)
print("OLIST LOGIN - OBTER AUTHORIZATION CODE E SINCRONIZAR")
print("="*70)

session = requests.Session()
session.verify = False

try:
    # ========================================================================
    # PASSO 1: Acessar a página de login da Olist
    # ========================================================================

    print("\n[1] Acessando página de login Olist...")

    # Primeiro, vamos acessar a URL de autorização para pegar o formulário
    auth_url = f"{OLIST_AUTH_URL}?client_id={CLIENT_ID}&redirect_uri={BASE_URL}/olist/callback.php&response_type=code&scope=openid"

    response = session.get(auth_url, allow_redirects=True, timeout=15)
    print(f"    Status: {response.status_code}")
    print(f"    URL final: {response.url}")

    # ========================================================================
    # PASSO 2: Procurar pelo formulário de login
    # ========================================================================

    print("\n[2] Procurando formulário de login...")

    # Procurar por action do formulário
    form_match = re.search(r'<form[^>]*action=["\']([^"\']+)["\']', response.text)
    if form_match:
        login_form_url = form_match.group(1)
        if not login_form_url.startswith('http'):
            login_form_url = "https://accounts.tiny.com.br" + login_form_url
        print(f"    Encontrado: {login_form_url}")
    else:
        print("    [AVISO] Formulário não encontrado. Tentando enviar direto para token endpoint...")
        login_form_url = OLIST_TOKEN_URL

    # ========================================================================
    # PASSO 3: Fazer login com email e senha
    # ========================================================================

    print("\n[3] Fazendo login com email e senha...")

    login_data = {
        'username': EMAIL,
        'password': SENHA,
        'login': 'Login',
        'grant_type': 'authorization_code',
        'client_id': CLIENT_ID,
        'redirect_uri': f'{BASE_URL}/olist/callback.php',
        'response_type': 'code'
    }

    response = session.post(login_form_url, data=login_data, allow_redirects=True, timeout=15)
    print(f"    Status: {response.status_code}")
    print(f"    URL: {response.url}")

    # ========================================================================
    # PASSO 4: Procurar pelo authorization code
    # ========================================================================

    print("\n[4] Procurando authorization code...")

    # Procurar no URL
    if 'code=' in response.url:
        code_match = re.search(r'code=([^&]+)', response.url)
        if code_match:
            authorization_code = code_match.group(1)
            print(f"    Encontrado! Code: {authorization_code[:30]}...")
        else:
            print("    Code não extraído do URL")
            authorization_code = None
    else:
        print(f"    Procurando no HTML...")
        # Procurar no conteúdo da página
        code_match = re.search(r'code["\']?\s*:\s*["\']([^"\']+)["\']', response.text)
        if code_match:
            authorization_code = code_match.group(1)
            print(f"    Encontrado! Code: {authorization_code[:30]}...")
        else:
            print("    Code não encontrado no HTML")
            authorization_code = None

    # ========================================================================
    # PASSO 5: Trocar code por access token
    # ========================================================================

    if authorization_code:
        print("\n[5] Trocando authorization code por access token...")

        token_data = {
            'grant_type': 'authorization_code',
            'client_id': CLIENT_ID,
            'client_secret': CLIENT_SECRET,
            'code': authorization_code,
            'redirect_uri': f'{BASE_URL}/olist/callback.php'
        }

        response = session.post(OLIST_TOKEN_URL, data=token_data, timeout=15)
        print(f"    Status: {response.status_code}")

        if response.status_code == 200:
            token_response = response.json()
            access_token = token_response.get('access_token')
            print(f"    Access token obtido! Token: {access_token[:30]}..." if access_token else "    ERRO: Token não retornou")

            # ====================================================================
            # PASSO 6: Salvar token e fazer sync
            # ====================================================================

            if access_token:
                print("\n[6] Salvando token e sincronizando produtos...")

                # Chamar sync-products.php com o token na sessão
                sync_url = f"{BASE_URL}/olist/sync-products.php?code={authorization_code}"
                response = session.get(sync_url, timeout=60)

                print(f"    Status: {response.status_code}")

                # Verificar se sincronização funcionou
                if '"sucesso": true' in response.text or '198' in response.text:
                    print("\n[SUCESSO] Sincronização completada!")
                    try:
                        result = response.json()
                        print(f"    Total: {result.get('total_produtos', 'desconhecido')}")
                        print(f"    Com imagem: {result.get('com_imagem', 'desconhecido')}")
                        print(f"    Mensagem: {result.get('mensagem', 'desconhecido')}")
                    except:
                        print("    Resposta (primeiros 300 chars):")
                        print(f"    {response.text[:300]}")
                else:
                    print("\n[AVISO] Resposta da sincronização:")
                    print(response.text[:500])

                # Salvar resultado
                result_file = Path("logs/olist-oauth-resultado.json")
                result_file.parent.mkdir(exist_ok=True)

                result = {
                    'timestamp': datetime.now().isoformat(),
                    'sucesso': '"sucesso": true' in response.text,
                    'authorization_code': authorization_code,
                    'access_token_masked': access_token[:30] + '...' if access_token else None,
                    'resposta_primeiros_500_chars': response.text[:500]
                }

                with open(result_file, 'w', encoding='utf-8') as f:
                    json.dump(result, f, ensure_ascii=False, indent=2)

                print(f"\n    Resultado salvo: {result_file}")

        else:
            print(f"    ERRO ao obter token: {response.text[:200]}")

    else:
        print("\n[ERRO] Não conseguiu obter authorization code")

except Exception as e:
    print(f"\n[ERRO] {str(e)}")
    import traceback
    traceback.print_exc()

print("\n" + "="*70)
print("[CONCLUÍDO]")
print("="*70 + "\n")
