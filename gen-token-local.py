#!/usr/bin/env python3
"""
Gerar Token Tiny/Olist - Client Credentials Flow
Executa localmente, sem dependência de servidor
"""

import json
import urllib.request
import urllib.parse
from pathlib import Path


def load_env():
    """Carregar .env"""
    env = {}
    env_file = Path(__file__).parent / ".env"

    if not env_file.exists():
        print(f"❌ .env não encontrado: {env_file}")
        return None

    for line in env_file.read_text().split('\n'):
        line = line.strip()
        if not line or line.startswith('#'):
            continue
        if '=' in line:
            key, val = line.split('=', 1)
            env[key.strip()] = val.strip()

    return env


def gen_token():
    """Gerar token via Client Credentials"""
    print("=" * 60)
    print("Gerador de Token Tiny/Olist")
    print("=" * 60)

    env = load_env()
    if not env:
        return False

    client_id = env.get('OLIST_CLIENT_ID', '')
    client_secret = env.get('OLIST_CLIENT_SECRET', '')

    if not client_id or not client_secret:
        print("❌ CLIENT_ID ou CLIENT_SECRET não configurados em .env")
        return False

    print(f"\n✓ Client ID: {client_id[:40]}...")
    print(f"✓ Client Secret: {client_secret[:40]}...")

    # Client Credentials Flow
    token_url = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token"

    data = urllib.parse.urlencode({
        'grant_type': 'client_credentials',
        'client_id': client_id,
        'client_secret': client_secret,
        'scope': 'openid',
    }).encode()

    print(f"\n📡 Conectando a: {token_url}")

    try:
        req = urllib.request.Request(token_url, data=data)
        with urllib.request.urlopen(req, timeout=30) as response:
            token_data = json.loads(response.read())
    except Exception as e:
        print(f"❌ Erro ao conectar: {e}")
        return False

    if 'access_token' not in token_data:
        print(f"❌ Token não obtido:")
        print(json.dumps(token_data, indent=2, ensure_ascii=False))
        return False

    access_token = token_data['access_token']
    refresh_token = token_data.get('refresh_token', '')
    expires_in = token_data.get('expires_in', 14400)

    print(f"\n✅ Token obtido!")
    print(f"   Access: {access_token[:50]}...")
    print(f"   Expira em: {expires_in / 3600:.1f} horas")

    # Salvar em .env
    print(f"\n💾 Salvando em .env...")

    env_file = Path(__file__).parent / ".env"
    content = env_file.read_text()

    keys = ['OLIST_ACCESS_TOKEN', 'OLIST_REFRESH_TOKEN', 'TINY_ACCESS_TOKEN', 'TINY_REFRESH_TOKEN']
    values = [access_token, refresh_token, access_token, refresh_token]

    for key, val in zip(keys, values):
        if f"{key}=" in content:
            import re
            content = re.sub(f"^{key}=.*", f"{key}={val}", content, flags=re.MULTILINE)
        else:
            content += f"\n{key}={val}"

    env_file.write_text(content)

    print(f"\n✅ PRONTO!")
    print(f"   Teste: https://shopvivaliz.com.br/olist/test-token-v3.php")

    return True


if __name__ == "__main__":
    gen_token()
