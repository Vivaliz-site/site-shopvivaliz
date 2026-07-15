#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Abre o API Testing Tool, monitora popups de autorizacao de loja
(que o botao "Obter autorizacao da loja" abre) e captura shop_cipher/access_token.
"""
import sys, json, subprocess, time
from urllib.parse import urlparse, parse_qs

TOOL_URL = "https://partner.tiktokshop.com/dev/api-testing-tool"

try:
    from playwright.sync_api import sync_playwright, TimeoutError as PWTimeout
except ImportError:
    subprocess.run([sys.executable, "-m", "pip", "install", "playwright"])
    subprocess.run([sys.executable, "-m", "playwright", "install", "chromium"])
    from playwright.sync_api import sync_playwright, TimeoutError as PWTimeout

def save(name, value):
    r = subprocess.run(["gh", "secret", "set", name, "--body", value],
                       capture_output=True, cwd="c:/Users/FRED/site-shopvivaliz")
    print("  OK: " + name if r.returncode == 0 else "  ERRO: " + name)

captured = {"cipher": [], "token": [], "code": []}

def scan_response(resp, source=""):
    try:
        url = resp.url
        if "code=" in url:
            code = parse_qs(urlparse(url).query).get("code", [None])[0]
            if code and code not in captured["code"] and not code.startswith("eyJ"):
                captured["code"].append(code)
                print(f"[{source}] code capturado: {code[:40]}...")
        ct = resp.headers.get("content-type", "")
        if "json" in ct:
            body = resp.json()
            data = body.get("data", body) if isinstance(body, dict) else {}
            cipher = data.get("shop_cipher")
            token = data.get("access_token")
            if cipher and cipher not in captured["cipher"]:
                captured["cipher"].append(cipher)
                print(f"[{source}] shop_cipher capturado: {cipher[:40]}...")
            if token and not token.startswith("eyJ") and token not in captured["token"]:
                captured["token"].append(token)
                print(f"[{source}] access_token capturado: {token[:40]}...")
    except Exception:
        pass

with sync_playwright() as p:
    browser = p.chromium.launch(headless=False, slow_mo=300)
    ctx = browser.new_context(locale="pt-BR")
    page = ctx.new_page()
    page.on("response", lambda r: scan_response(r, "main"))

    popups = []
    def on_popup(popup):
        print(">> Popup detectado: " + popup.url[:80])
        popups.append(popup)
        popup.on("response", lambda r: scan_response(r, "popup"))

    ctx.on("page", on_popup)

    print("Abrindo API Testing Tool...")
    page.goto(TOOL_URL, timeout=30000, wait_until="domcontentloaded")

    print("\nFaca login se necessario.")
    print("Clique em 'Obter autorizacao da loja', selecione Shop_Vivaliz e autorize.")
    print("Vou monitorar a aba principal E qualquer popup que abrir.")
    print("Monitorando por 4 minutos...\n")

    for i in range(240):
        time.sleep(1)

        # Verificar popups por URL com code/cipher
        for pu in popups:
            try:
                cur = pu.url
                if "code=" in cur:
                    code = parse_qs(urlparse(cur).query).get("code", [None])[0]
                    if code and code not in captured["code"]:
                        captured["code"].append(code)
                        print("Code via URL popup: " + code[:40])
                if "shop_cipher=" in cur:
                    cipher = parse_qs(urlparse(cur).query).get("shop_cipher", [None])[0]
                    if cipher and cipher not in captured["cipher"]:
                        captured["cipher"].append(cipher)
                        print("Cipher via URL popup: " + cipher[:40])
            except Exception:
                pass

        # Verificar campos na pagina principal a cada 15s
        if i % 15 == 0 and i > 0:
            try:
                for sel in ["input", "textarea"]:
                    for el in page.query_selector_all(sel):
                        try:
                            val = el.input_value()
                            label = (el.get_attribute("placeholder") or
                                     el.get_attribute("name") or
                                     el.get_attribute("id") or "")
                            if val and len(val) > 15:
                                if "cipher" in label.lower() and val not in captured["cipher"]:
                                    captured["cipher"].append(val)
                                    print("Campo cipher preenchido: " + val[:50])
                                elif "token" in label.lower() and not val.startswith("eyJ") and val not in captured["token"]:
                                    captured["token"].append(val)
                                    print("Campo token preenchido: " + val[:50])
                        except Exception:
                            pass
            except Exception:
                pass
            print(str(240 - i) + "s restantes... (popups abertos: " + str(len(popups)) + ")")

        if captured["cipher"]:
            print("\nshop_cipher obtido! Aguardando 3s para estabilizar...")
            time.sleep(3)
            break

    browser.close()

print("\n=== RESULTADOS ===")
print(json.dumps(captured, indent=2)[:500])

if captured["cipher"]:
    save("TIKTOK_SHOP_CIPHER", captured["cipher"][0])
if captured["token"]:
    save("TIKTOK_ACCESS_TOKEN", captured["token"][0])

if not captured["cipher"] and not captured["token"]:
    print("\nNada capturado automaticamente.")
    print("Se viu o shop_cipher na tela, rode:")
    print("  gh secret set TIKTOK_SHOP_CIPHER --body VALOR")
else:
    print("\nConcluido.")
