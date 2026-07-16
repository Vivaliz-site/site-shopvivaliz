#!/usr/bin/env python3
# Uso: python scripts/exchange-tiktok-code.py SEU_CODE_AQUI
import os, sys, json, subprocess
from urllib.parse import urlencode
from urllib.request import Request, urlopen
from urllib.error import HTTPError
from pathlib import Path

APP_KEY    = os.environ.get("TIKTOK_APP_KEY", "")
APP_SECRET = os.environ.get("TIKTOK_APP_SECRET", "")
TOKEN_URL  = "https://auth.tiktok-shops.com/api/v2/token/get"
CODE_FILE  = Path(__file__).parent / "tiktok_auth_code.txt"

if not APP_KEY or not APP_SECRET:
    print("ERRO: defina TIKTOK_APP_KEY e TIKTOK_APP_SECRET no ambiente.")
    sys.exit(1)

code = None
if len(sys.argv) > 1:
    code = sys.argv[1].strip()
elif CODE_FILE.exists():
    code = CODE_FILE.read_text().strip()

if not code:
    print("Uso: python scripts/exchange-tiktok-code.py SEU_CODE")
    sys.exit(1)

print("Trocando code por token...")
params = urlencode({"app_key": APP_KEY, "app_secret": APP_SECRET,
                    "auth_code": code, "grant_type": "authorized_code"})
try:
    req = Request(f"{TOKEN_URL}?{params}", method="GET")
    with urlopen(req, timeout=30) as r:
        result = json.loads(r.read())
except HTTPError as e:
    result = {"error": e.read().decode()[:500]}

print(json.dumps(result, indent=2, ensure_ascii=False))
data = result.get("data", {})
token = data.get("access_token")

if token:
    def save(name, value):
        r = subprocess.run(["gh", "secret", "set", name, "--body", value],
                           capture_output=True, cwd=str(Path(__file__).parent.parent))
        print("  OK: " + name if r.returncode == 0 else "  ERRO: " + name)

    save("TIKTOK_ACCESS_TOKEN", token)
    if data.get("refresh_token"):
        save("TIKTOK_REFRESH_TOKEN", data["refresh_token"])
    if data.get("shop_cipher"):
        save("TIKTOK_SHOP_CIPHER", data["shop_cipher"])
    if CODE_FILE.exists():
        CODE_FILE.unlink()
    print("\nSUCESSO! Pipeline TikTok pronto.")
else:
    print("\nERRO: " + str(result.get("message", "sem token")))
