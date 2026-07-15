#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Abre o Chrome do usuario na URL de autorizacao TikTok e captura o auth_code
via servidor local ou manualmente.
"""
import sys, json, subprocess, time, os, threading
import http.server
from urllib.parse import urlencode, urlparse, parse_qs
from urllib.request import Request, urlopen
from urllib.error import HTTPError

APP_KEY    = os.environ.get("TIKTOK_APP_KEY", "")
APP_SECRET = os.environ.get("TIKTOK_APP_SECRET", "")
if not APP_KEY or not APP_SECRET:
    print("ERRO: defina TIKTOK_APP_KEY e TIKTOK_APP_SECRET no ambiente.")
    sys.exit(1)
TOKEN_URL  = "https://auth.tiktok-shops.com/api/v2/token/get"
AUTH_URL   = f"https://auth.tiktok-shops.com/oauth/authorize?app_key={APP_KEY}&state=ok"
LOCAL_PORT = 8765

captured = []

class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        code = parse_qs(urlparse(self.path).query).get("code", [None])[0]
        if code:
            captured.append(code)
            body = b"<h2 style='font-family:sans-serif;color:green'>Autorizado! Pode fechar esta aba.</h2>"
            self.send_response(200)
        else:
            body = b"<h2>Aguardando...</h2>"
            self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.end_headers()
        self.wfile.write(body)
    def log_message(self, *a): pass

def start_server():
    srv = http.server.HTTPServer(("localhost", LOCAL_PORT), Handler)
    t = threading.Thread(target=srv.serve_forever, daemon=True)
    t.start()
    return srv

def exchange(code):
    params = urlencode({"app_key": APP_KEY, "app_secret": APP_SECRET,
                        "auth_code": code, "grant_type": "authorized_code"})
    try:
        req = Request(f"{TOKEN_URL}?{params}", method="GET")
        with urlopen(req, timeout=30) as r:
            return json.loads(r.read())
    except HTTPError as e:
        return {"error": e.read().decode()[:300]}

def save(name, value):
    r = subprocess.run(["gh", "secret", "set", name, "--body", value],
                       capture_output=True, cwd="c:/Users/FRED/site-shopvivaliz")
    print("  OK: " + name if r.returncode == 0 else "  ERRO: " + name)

def open_chrome(url):
    chrome_paths = [
        r"C:\Program Files\Google\Chrome\Application\chrome.exe",
        r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
        r"C:\Users\FRED\AppData\Local\Google\Chrome\Application\chrome.exe",
    ]
    for path in chrome_paths:
        if os.path.exists(path):
            subprocess.Popen([path, url])
            return True
    # Fallback: webbrowser
    import webbrowser
    webbrowser.open(url)
    return True

# Iniciar servidor local
print("Servidor local iniciado em http://localhost:" + str(LOCAL_PORT))
srv = start_server()

# Abrir Chrome com a URL de autorizacao
print("Abrindo Chrome na URL de autorizacao...")
print("URL: " + AUTH_URL)
open_chrome(AUTH_URL)

print("\nSe o Chrome pedir login, faca o login e depois autorize o app.")
print("Aguardando ate 180 segundos...\n")

for i in range(180):
    if captured:
        break
    if i % 30 == 0 and i > 0:
        print("  " + str(180 - i) + "s restantes...")
    time.sleep(1)

srv.shutdown()

if not captured:
    print("\nCode nao capturado automaticamente.")
    print("Copie o 'code' da URL onde foi redirecionado e cole abaixo:")
    print("Exemplo: ...?code=XXXXX&...")
    code_input = input("\nCole o code: ").strip()
    if code_input:
        captured.append(code_input)
    else:
        print("ERRO: code nao fornecido.")
        sys.exit(1)

code = captured[0]
print("\nCode obtido: " + code[:50] + "...")
print("Trocando por access_token...")
result = exchange(code)
print(json.dumps(result, indent=2, ensure_ascii=False)[:800])

data = result.get("data", {})
token = data.get("access_token")

if token:
    print("\nSUCESSO! Token obtido.")
    save("TIKTOK_ACCESS_TOKEN", token)
    if data.get("refresh_token"):
        save("TIKTOK_REFRESH_TOKEN", data["refresh_token"])
    if data.get("shop_cipher"):
        save("TIKTOK_SHOP_CIPHER", data["shop_cipher"])
    print("\nPipeline TikTok pronto!")
else:
    print("\nERRO: " + str(result.get("message", "sem token na resposta")))
