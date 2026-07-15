import time
import hmac
import hashlib
import os
import sys
from playwright.sync_api import sync_playwright

# Força UTF-8 no Windows
if sys.platform == 'win32':
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

PARTNER_ID = 1237032
PARTNER_KEY = "shpk574f454f6a756e534e7476726b67727a5242554c76736d4b56567769554d"
USER = "SANDBOX.b6fb03003426929be0c1"
PASS = "56194122e737c5cd"
REDIRECT = "https://dev.shopvivaliz.com.br"
AUTH = "https://openplatform.sandbox.test-stable.shopee.sg/api/v2/shop/auth_partner"

def sign(p, t):
    return hmac.new(
        PARTNER_KEY.encode(),
        f"{PARTNER_ID}{p}{t}".encode(),
        hashlib.sha256
    ).hexdigest()

def run():
    path = "/api/v2/shop/auth_partner"
    ts = int(time.time())
    s = sign(path, ts)
    url = f"{AUTH}?partner_id={PARTNER_ID}&timestamp={ts}&sign={s}&redirect={REDIRECT}"

    print(f"Acessando: {url}\n")

    with sync_playwright() as p:
        b = p.chromium.launch(headless=False)
        page = b.new_page()
        page.goto(url)
        page.wait_for_load_state('networkidle')

        print("Procurando campos de login...")

        try:
            # Procura por inputs não-readonly
            username_input = page.query_selector('input[type="text"]:not([readonly])')
            if not username_input:
                username_input = page.query_selector('input[type="email"]')
            if not username_input:
                inputs = page.query_selector_all('input:not([readonly])')
                if inputs:
                    username_input = inputs[0]

            if username_input:
                print(f"Preenchendo usuario: {USER}")
                username_input.fill(USER)

            password_input = page.query_selector('input[type="password"]')
            if password_input:
                print(f"Preenchendo senha...")
                password_input.fill(PASS)

            login_button = page.query_selector('button:has-text("Log In")')
            if login_button:
                print("Clicando no botao Log In...")
                login_button.click()
            else:
                buttons = page.query_selector_all('button')
                if buttons:
                    buttons[0].click()
                    print("Clicado em primeiro botao")

            print("\n=== AGUARDANDO AUTORIZACAO ===")
            print("1. Clique em 'Continue' ou 'Authorize' no navegador")
            print("2. Confirme a autenticacao")
            print("3. O navegador vai redirecionar para a URL com o codigo\n")

            last_url = page.url
            for i in range(180):  # 3 minutos
                current_url = page.url

                # Se a URL mudou e contém o código
                if current_url != last_url and ("code=" in current_url or "dev.shopvivaliz" in current_url):
                    print("\n[SUCESSO] Autorizacao bem-sucedida!")
                    print(f"URL final: {current_url}\n")

                    # Extrai o código
                    if "code=" in current_url:
                        code_start = current_url.find("code=") + 5
                        code_end = current_url.find("&", code_start)
                        if code_end == -1:
                            code = current_url[code_start:]
                        else:
                            code = current_url[code_start:code_end]
                        print(f"[*] Authorization Code: {code}")
                    else:
                        print(f"[!] URL nao contem 'code='")

                    # Extrai o shop_id
                    if "shop_id=" in current_url:
                        shop_id_start = current_url.find("shop_id=") + 8
                        shop_id_end = current_url.find("&", shop_id_start)
                        if shop_id_end == -1:
                            shop_id = current_url[shop_id_start:]
                        else:
                            shop_id = current_url[shop_id_start:shop_id_end]
                        print(f"[*] Shop ID: {shop_id}\n")

                    break

                last_url = current_url
                time.sleep(1)
                if i % 30 == 0 and i > 0:
                    print(f"Aguardando autorizacao... ({i}s)")

            else:
                print(f"\n[TIMEOUT] Nao foi possivel capturar o codigo apos 3 minutos")
                print(f"URL atual: {page.url}")

            # Mantém o navegador aberto
            print("\nMantenha o navegador aberto por mais 10 segundos...")
            time.sleep(10)

        except Exception as e:
            print(f"[ERRO] {e}")
            print(f"URL atual: {page.url}")

        b.close()

if __name__ == "__main__":
    run()
