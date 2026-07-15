#!/usr/bin/env python3
# Abre o browser na URL de autorizacao TikTok e inicia servidor local (porta 8765)
import os, sys, threading, time, json, subprocess
import http.server
from urllib.parse import urlparse, parse_qs, urlencode
from urllib.request import Request, urlopen
from urllib.error import HTTPError
import webbrowser
from pathlib import Path

APP_KEY    = os.environ.get("TIKTOK_APP_KEY", "")
APP_SECRET = os.environ.get("TIKTOK_APP_SECRET", "")
if not APP_KEY or not APP_SECRET:
    print("ERRO: defina TIKTOK_APP_KEY e TIKTOK_APP_SECRET no ambiente.")
    sys.exit(1)
TOKEN_URL  = "https://auth.tiktok-shops.com/api/v2/token/get"
AUTH_URL   = f"https://auth.tiktok-shops.com/oauth/authorize?app_key={APP_KEY}&state=ok"
PORT       = 8765
CODE_FILE  = Path(__file__).parent / "tiktok_auth_code.txt"

captured = []

class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        code = parse_qs(urlparse(self.path).query).get("code", [None])[0]
        if code:
            captured.append(code)
            CODE_FILE.write_text(code)
            print("\nCode capturado! Salvando token...")
            body = b"<h2 style='color:green;font-family:sans-serif'>Autorizado! Pode fechar esta aba.</h2>"
            self.send_response(200)
        else:
            body = b"<h2 style='font-family:sans-serif'>Aguardando autorizacao...</h2>"
            self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.end_headers()
        self.wfile.write(body)
    def log_message(self, *a): pass

def exchange(code):
    params = urlencode({"app_key": APP_KEY, "app_secret": APP_SECRET,
                        "auth_code": code, "grant_type": "authorized_code"})
    try:
        req = Request(f"{TOKEN_URL}?{params}", method="GET")
        with urlopen(req, timeout=30) as r:
            return json.loads(r.read())
    except HTTPError as e:
        return {"error": e.read().decode()[:500]}

def save(name, value):
    r = subprocess.run(["gh", "secret", "set", name, "--body", value],
                       capture_output=True, cwd=str(Path(__file__).parent.parent))
    print("  OK: " + name if r.returncode == 0 else "  ERRO ao salvar: " + name)

srv = http.server.HTTPServer(("localhost", PORT), Handler)
t = threading.Thread(target=srv.serve_forever, daemon=True)
t.start()

print("=" * 60)
print("  TIKTOK AUTH - ShopVivaliz")
print("=" * 60)
print("Servidor local: http://localhost:" + str(PORT))
print("Abrindo browser...")
print("URL: " + AUTH_URL)
print()
print("INSTRUCOES:")
print("1. Faca login no TikTok Seller Center se necessario")
print("2. Clique em 'Autorizar' / 'Allow'")
print("3. O code sera capturado automaticamente")
print("   OU copie o code da URL e rode:")
print("   python scripts/exchange-tiktok-code.py SEU_CODE_AQUI")
print("=" * 60)

webbrowser.open(AUTH_URL)

print("Aguardando autorizacao (3 minutos)...")
for i in range(180):
    if captured:
        break
    # Verificar se code foi salvo em arquivo por outro processo
    if CODE_FILE.exists():
        code_from_file = CODE_FILE.read_text().strip()
        if code_from_file and code_from_file not in captured:
            captured.append(code_from_file)
            break
    if i % 30 == 0 and i > 0:
        print("  " + str(180-i) + "s restantes...")
    time.sleep(1)

srv.shutdown()

if not captured:
    print("\nTimeout. Use: python scripts/exchange-tiktok-code.py SEU_CODE")
    sys.exit(0)

code = captured[0]
print("\nCode: " + code[:50] + "...")
result = exchange(code)
print(json.dumps(result, indent=2, ensure_ascii=False)[:800])
data = result.get("data", {})
token = data.get("access_token")

if token:
    print("\nSUCESSO!")
    save("TIKTOK_ACCESS_TOKEN", token)
    if data.get("refresh_token"):
        save("TIKTOK_REFRESH_TOKEN", data["refresh_token"])
    if data.get("shop_cipher"):
        save("TIKTOK_SHOP_CIPHER", data["shop_cipher"])
    if CODE_FILE.exists():
        CODE_FILE.unlink()
    print("Pipeline TikTok pronto!")
else:
    print("ERRO: " + str(result.get("message", "sem token")))
