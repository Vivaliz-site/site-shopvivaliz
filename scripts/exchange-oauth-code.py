#!/usr/bin/env python3
"""
[LEGADO] Troca o authorization code da Olist/Tiny pelo access_token + refresh_token.
Uso: python scripts/exchange-oauth-code.py <CODE>

ATENÇÃO: Este script pertence ao pipeline Olist (legado).
O novo pipeline usa Shopee + TikTok diretamente — este script NÃO é necessário.
"""
import json, os, sys, subprocess
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import Request, urlopen

CLIENT_ID     = os.environ.get("OLIST_CLIENT_ID", "")
CLIENT_SECRET = os.environ.get("OLIST_CLIENT_SECRET", "")
REDIRECT_URI  = os.environ.get("OLIST_REDIRECT_URI", "https://shopvivaliz.com.br/olist/callback.php")
TOKEN_URL     = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token"

if not CLIENT_ID or not CLIENT_SECRET:
    print("⚠️  OLIST_CLIENT_ID e OLIST_CLIENT_SECRET não definidos no ambiente.")
    print("   Este script é legado — o novo pipeline usa Shopee + TikTok diretamente.")
    sys.exit(1)

code = sys.argv[1] if len(sys.argv) > 1 else input("Cole o code aqui: ").strip()

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
        tokens = json.loads(r.read())
except HTTPError as e:
    body = e.read().decode()
    print(f"ERRO HTTP {e.code}: {body[:500]}")
    sys.exit(1)

at = tokens.get("access_token")
rt = tokens.get("refresh_token")

if not at:
    print("Falha:", json.dumps(tokens, indent=2))
    sys.exit(1)

print(f"\n✅ access_token : {at[:60]}...")
print(f"✅ refresh_token: {rt[:60] if rt else '(não retornado)'}...")

if rt:
    # Salvar nos secrets do GitHub automaticamente
    for name, val in [("OLIST_ACCESS_TOKEN", at), ("OLIST_REFRESH_TOKEN", rt), ("TINY_ACCESS_TOKEN", at)]:
        r = subprocess.run(["gh", "secret", "set", name, "--body", val], capture_output=True)
        status = "✅" if r.returncode == 0 else "⚠️ "
        print(f"{status} secret {name} {'atualizado' if r.returncode==0 else 'falhou: '+r.stderr.decode()[:80]}")

print("\nPronto! Rode o pipeline novamente.")
