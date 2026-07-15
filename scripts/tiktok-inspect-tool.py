#!/usr/bin/env python3
# -*- coding: utf-8 -*-
import os, sys, json, subprocess, time
from urllib.parse import urlparse, parse_qs, urlencode
from urllib.request import Request, urlopen
from urllib.error import HTTPError

APP_KEY    = os.environ.get("TIKTOK_APP_KEY", "")
APP_SECRET = os.environ.get("TIKTOK_APP_SECRET", "")
TOKEN_URL  = "https://auth.tiktok-shops.com/api/v2/token/get"
TOOL_URL   = "https://partner.tiktokshop.com/dev/api-testing-tool"

if not APP_KEY or not APP_SECRET:
    print("ERRO: defina TIKTOK_APP_KEY e TIKTOK_APP_SECRET no ambiente.")
    sys.exit(1)

try:
    from playwright.sync_api import sync_playwright, TimeoutError as PWTimeout
except ImportError:
    subprocess.run([sys.executable, "-m", "pip", "install", "playwright"])
    subprocess.run([sys.executable, "-m", "playwright", "install", "chromium"])
    from playwright.sync_api import sync_playwright, TimeoutError as PWTimeout

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

def is_shop_token(val):
    # Token da Shop API nao e JWT e tem >30 chars
    # JWTs comecam com eyJ
    if not val or len(val) < 20:
        return False
    if val.startswith("eyJ"):  # JWT do SSO -- ignorar
        return False
    return True

captured_token = []
captured_code  = []

with sync_playwright() as p:
    browser = p.chromium.launch(headless=False, slow_mo=200)
    ctx = browser.new_context(locale="pt-BR")
    page = ctx.new_page()

    def on_response(resp):
        url = resp.url
        # Capturar auth_code na URL
        if "code=" in url and "tiktok" in url:
            code = parse_qs(urlparse(url).query).get("code", [None])[0]
            if code and code not in captured_code and not code.startswith("eyJ"):
                captured_code.append(code)
                print("Auth code capturado: " + code[:40] + "...")
        # Capturar access_token em respostas JSON da Shop API
        try:
            ct = resp.headers.get("content-type", "")
            if "json" in ct and "tiktok" in url:
                body = resp.json()
                token = (body.get("data", {}).get("access_token") or
                         body.get("access_token"))
                if token and is_shop_token(token) and token not in captured_token:
                    captured_token.append(token)
                    print("ACCESS TOKEN da Shop API capturado!")
        except Exception:
            pass

    page.on("response", on_response)

    print("Abrindo TikTok Partner Center API Testing Tool...")
    page.goto(TOOL_URL, timeout=30000, wait_until="domcontentloaded")

    print("")
    print("================================================")
    print("  Faca login no browser.")
    print("  Depois use o API Testing Tool normalmente.")
    print("  Estou monitorando por 5 minutos.")
    print("================================================")

    logged_in = False
    for i in range(300):
        cur = page.url
        time.sleep(1)

        # Detectar quando fez login
        if not logged_in and "partner.tiktokshop.com" in cur and "login" not in cur:
            logged_in = True
            print("\nLogin detectado! URL: " + cur[:80])
            print("Agora use o API Testing Tool no browser.")
            print("Vou ler o conteudo da pagina em 10 segundos...")
            time.sleep(10)

            # Ler conteudo
            try:
                content = page.inner_text("body")
                print("\n--- CONTEUDO DA PAGINA ---")
                print(content[:4000])
                print("---")

                # Ler todos os inputs
                print("\n--- CAMPOS ---")
                for sel in ["input", "textarea"]:
                    els = page.query_selector_all(sel)
                    for el in els:
                        try:
                            val = el.input_value()
                            label = (el.get_attribute("placeholder") or
                                     el.get_attribute("name") or
                                     el.get_attribute("id") or "")
                            if val:
                                print("  [" + label + "]: " + val[:100])
                                if is_shop_token(val):
                                    captured_token.append(val)
                        except Exception:
                            pass
            except Exception as e:
                print("Erro leitura: " + str(e)[:100])

        # Re-ler a cada 30s apos login
        if logged_in and i % 30 == 0 and i > 15:
            try:
                print("\n[" + str(i) + "s] Verificando campos...")
                for sel in ["input", "textarea"]:
                    els = page.query_selector_all(sel)
                    for el in els:
                        try:
                            val = el.input_value()
                            if val and is_shop_token(val) and val not in captured_token:
                                print("  Token encontrado: " + val[:60] + "...")
                                captured_token.append(val)
                        except Exception:
                            pass
            except Exception:
                pass

        if i % 60 == 0 and i > 0:
            print(str(300 - i) + "s restantes... URL: " + cur[:60])

    # Leitura final
    try:
        print("\n--- LEITURA FINAL ---")
        content = page.inner_text("body")
        print(content[:5000])
        for sel in ["input", "textarea"]:
            els = page.query_selector_all(sel)
            for el in els:
                try:
                    val = el.input_value()
                    label = (el.get_attribute("placeholder") or
                             el.get_attribute("name") or
                             el.get_attribute("id") or "")
                    if val:
                        print("Campo [" + label + "]: " + val[:120])
                        if is_shop_token(val) and val not in captured_token:
                            captured_token.append(val)
                except Exception:
                    pass
    except Exception:
        pass

    browser.close()

print("\n=== RESULTADOS ===")

if captured_code:
    code = captured_code[0]
    print("Trocando auth_code por token...")
    result = exchange(code)
    print(json.dumps(result, indent=2)[:600])
    data = result.get("data", {})
    token = data.get("access_token")
    if token and is_shop_token(token):
        captured_token.insert(0, token)
        if data.get("refresh_token"):
            save("TIKTOK_REFRESH_TOKEN", data["refresh_token"])
        if data.get("shop_cipher"):
            save("TIKTOK_SHOP_CIPHER", data["shop_cipher"])

if captured_token:
    token = captured_token[0]
    print("Access Token: " + token[:60] + "...")
    save("TIKTOK_ACCESS_TOKEN", token)
    print("\nSUCESSO! Pipeline TikTok pronto.")
else:
    print("Nenhum token Shop capturado.")
    print("Se encontrou um token no browser, rode:")
    print("  gh secret set TIKTOK_ACCESS_TOKEN --body SEU_TOKEN")
