#!/usr/bin/env python3
"""
Olist Headless Login - Fazer login automaticamente com browser headless
"""

import os
import sys
import time
from pathlib import Path
from datetime import datetime

try:
    from selenium import webdriver
    from selenium.webdriver.common.by import By
    from selenium.webdriver.support.ui import WebDriverWait
    from selenium.webdriver.support import expected_conditions as EC
    from selenium.webdriver.chrome.options import Options
except ImportError:
    print("Instalando Selenium...")
    import subprocess
    subprocess.check_call([sys.executable, "-m", "pip", "install", "selenium"])
    from selenium import webdriver
    from selenium.webdriver.common.by import By
    from selenium.webdriver.support.ui import WebDriverWait
    from selenium.webdriver.support import expected_conditions as EC
    from selenium.webdriver.chrome.options import Options

PROJECT_ROOT = Path(__file__).parent.parent
LOG_FILE = PROJECT_ROOT / 'logs' / 'olist-headless-login.log'

CLIENT_ID = os.getenv('OLIST_CLIENT_ID') or os.getenv('TINY_CLIENT_ID') or ''
OLIST_EMAIL = os.getenv('OLIST_EMAIL') or os.getenv('OLIST_USER') or os.getenv('EMAIL_USER') or ''
OLIST_PASSWORD = os.getenv('OLIST_PASSWORD') or os.getenv('EMAIL_PASSWORD') or ''
REDIRECT_URI = 'https://dev.shopvivaliz.com.br/olist/handle-callback.php'

def log_msg(msg):
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    line = f"[{timestamp}] {msg}"
    print(line)
    LOG_FILE.parent.mkdir(parents=True, exist_ok=True)
    with open(LOG_FILE, 'a', encoding='utf-8') as f:
        f.write(line + '\n')

def login_headless():
    """Fazer login com Chrome headless"""

    log_msg("=== OLIST HEADLESS LOGIN ===")

    options = Options()
    options.add_argument('--headless=new')  # Novo headless mode
    options.add_argument('--no-sandbox')
    options.add_argument('--disable-dev-shm-usage')
    options.add_argument('--disable-blink-features=AutomationControlled')
    options.add_argument('--window-size=1920,1080')
    options.add_experimental_option('excludeSwitches', ['enable-automation'])
    options.add_experimental_option('useAutomationExtension', False)

    log_msg("Iniciando Chrome headless...")
    driver = None

    try:
        driver = webdriver.Chrome(options=options)

        # URL de autorização
        auth_url = f"https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth?" \
                   f"client_id={CLIENT_ID}&redirect_uri={REDIRECT_URI}&response_type=code&scope=openid"

        log_msg(f"Acessando {auth_url[:80]}...")
        driver.get(auth_url)
        time.sleep(3)

        # Preencher username
        log_msg("Procurando campo username...")
        username_input = WebDriverWait(driver, 10).until(
            EC.presence_of_element_located((By.ID, "username"))
        )
        username_input.send_keys(OLIST_EMAIL)
        log_msg("Username preenchido")
        time.sleep(1)

        # Preencher password
        log_msg("Preenchendo password...")
        password_input = driver.find_element(By.ID, "password")
        password_input.send_keys(OLIST_PASSWORD)
        time.sleep(1)

        # Clicar login
        log_msg("Clicando login...")
        login_button = driver.find_element(By.ID, "kc-login")
        login_button.click()
        time.sleep(5)

        # Aguardar redirecionamento
        log_msg("Aguardando redirecionamento...")
        WebDriverWait(driver, 15).until(
            lambda d: 'handle-callback.php' in d.current_url or '/complete-oauth-flow.php' in d.current_url
        )

        final_url = driver.current_url
        log_msg(f"URL final: {final_url[:100]}")

        if 'handle-callback.php' in final_url or '/complete-oauth-flow.php' in final_url:
            log_msg("Sucesso! Login concluido e redirecionado")
            return True
        else:
            log_msg(f"Redirecionamento inesperado")
            return False

    except Exception as e:
        log_msg(f"Erro: {str(e)}")
        import traceback
        log_msg(traceback.format_exc())

        # Salvar screenshot para debug
        if driver:
            try:
                screenshot = LOG_FILE.parent / 'login-error.png'
                driver.save_screenshot(str(screenshot))
                log_msg(f"Screenshot salvo: {screenshot}")
            except:
                pass

        return False

    finally:
        if driver:
            driver.quit()
            log_msg("Chrome fechado")

def main():
    if login_headless():
        log_msg("OK! Login completado com sucesso!")
        return 0
    else:
        log_msg("FALHA no login")
        return 1

if __name__ == '__main__':
    sys.exit(main())
