#!/usr/bin/env python3
"""
Teste do Regenerador de Token
Executa: python test-token-refresh.py
"""

import json
import urllib.request
from pathlib import Path

print("=" * 70)
print("[*] Testando Regenerador de Token")
print("=" * 70)

# Carregar .env
env_file = Path("C:/site-shopvivaliz/.env")
env = {}

for line in env_file.read_text().split('\n'):
    if '=' in line and not line.startswith('#'):
        k, v = line.split('=', 1)
        env[k.strip()] = v.strip()

client_id = env.get('OLIST_CLIENT_ID', '')
client_secret = env.get('OLIST_CLIENT_SECRET', '')
refresh_token = env.get('OLIST_REFRESH_TOKEN', '')

print(f"\n[+] Client ID: {client_id[:40]}...")
print(f"[+] Refresh Token: {refresh_token[:40]}..." if refresh_token else "[!] Refresh Token: VAZIO")

if not refresh_token:
    print("[!] Nenhum refresh_token configurado!")
    exit(1)

# ============================================================
# TESTAR REFRESH
# ============================================================

print("\n[*] Enviando requisicao de refresh...")

token_url = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token"

data = urllib.parse.urlencode({
    'grant_type': 'refresh_token',
    'client_id': client_id,
    'client_secret': client_secret,
    'refresh_token': refresh_token,
}).encode()

try:
    import urllib.parse
    req = urllib.request.Request(token_url, data=data)
    with urllib.request.urlopen(req, timeout=30) as response:
        token_data = json.loads(response.read())
except Exception as e:
    print(f"[!] Erro: {e}")
    exit(1)

if 'access_token' not in token_data:
    print(f"[!] Token nao obtido:")
    print(json.dumps(token_data, indent=2))
    exit(1)

access_token = token_data['access_token']
expires_in = token_data.get('expires_in', 14400)

print(f"\n[+] Token renovado com sucesso!")
print(f"    Access: {access_token[:50]}...")
print(f"    Expira: {expires_in / 3600:.1f}h")

# Salvar em .env
print(f"\n[*] Salvando novo token em .env...")

content = env_file.read_text()
import re

keys = ['OLIST_ACCESS_TOKEN', 'OLIST_REFRESH_TOKEN', 'TINY_ACCESS_TOKEN', 'TINY_REFRESH_TOKEN']
new_refresh = token_data.get('refresh_token', refresh_token)
values = [access_token, new_refresh, access_token, new_refresh]

for key, val in zip(keys, values):
    if f"{key}=" in content:
        content = re.sub(f"^{key}=.*", f"{key}={val}", content, flags=re.MULTILINE)
    else:
        content += f"\n{key}={val}"

env_file.write_text(content)

print(f"[+] Token salvo!")
print(f"\n[+] REGENERADOR FUNCIONANDO PERFEITAMENTE!")
