#!/usr/bin/env python3
"""
Auto OAuth Login - Fazer login automaticamente na Olist
Usa Selenium para abrir navegador, fazer login, e obter refresh_token

Execução: python scripts/auto-oauth-login.py
"""

import os
import sys
import json
import time
import subprocess
from pathlib import Path
from datetime import datetime

# Verificar se Selenium está instalado
try:
    from selenium import webdriver
    from selenium.webdriver.common.by import By
    from selenium.webdriver.support.ui import WebDriverWait
    from selenium.webdriver.support import expected_conditions as EC
    from selenium.webdriver.chrome.options import Options
except ImportError:
    print("❌ Selenium não instalado. Instalando...")
    subprocess.check_call([sys.executable, "-m", "pip", "install", "selenium"])
    from selenium import webdriver
    from selenium.webdriver.common.by import By
    from selenium.webdriver.support.ui import WebDriverWait
    from selenium.webdriver.support import expected_conditions as EC
    from selenium.webdriver.chrome.options import Options

# Configurações
CLIENT_ID = os.getenv('OLIST_CLIENT_ID', 'tiny-api-d4eb7c80a2e7e8abebad641a446a2f69d9e98289-1782127553')
CLIENT_SECRET = os.getenv('OLIST_CLIENT_SECRET', 'sh1MLgXhFlvycybhlShnvQMcEL8T2GWv')
REDIRECT_URI = 'https://dev.shopvivaliz.com.br/olist/setup-oauth.php'

# Credenciais Olist
OLIST_EMAIL = os.getenv('OLIST_EMAIL', 'atendimento@shopvivaliz.com.br')
OLIST_PASSWORD = os.getenv('OLIST_PASSWORD', 'L:z27062021*')

# Caminhos
PROJECT_ROOT = Path(__file__).parent.parent
TOKENS_DIR = PROJECT_ROOT / '.tokens'
CONFIG_FILE = TOKENS_DIR / 'olist-config.json'
LOG_FILE = PROJECT_ROOT / 'logs' / 'auto-oauth-login.log'

def log_msg(msg):
    """Log com timestamp"""
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    line = f"[{timestamp}] {msg}"
    print(line)

    LOG_FILE.parent.mkdir(parents=True, exist_ok=True)
    with open(LOG_FILE, 'a', encoding='utf-8') as f:
        f.write(line + '\n')

def get_chrome_driver():
    """Criar driver do Chrome com opções"""
    options = Options()
    # options.add_argument('--headless')  # Comentado para debug visual
    options.add_argument('--no-sandbox')
    options.add_argument('--disable-dev-shm-usage')
    options.add_argument('--disable-blink-features=AutomationControlled')
    options.add_experimental_option('excludeSwitches', ['enable-automation'])

    log_msg("Iniciando Chrome...")
    driver = webdriver.Chrome(options=options)
    return driver

def login_and_get_token():
    """Fazer login e obter token via Selenium"""

    driver = None
    try:
        driver = get_chrome_driver()

        # URL de autorização
        auth_url = f"https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth?" \
                   f"client_id={CLIENT_ID}&redirect_uri={REDIRECT_URI}&response_type=code&scope=openid"

        log_msg(f"Acessando URL de autorização...")
        driver.get(auth_url)
        time.sleep(3)

        # Preencher email
        log_msg("Preenchendo email...")
        email_input = WebDriverWait(driver, 10).until(
            EC.presence_of_element_located((By.ID, "username"))
        )
        email_input.clear()
        email_input.send_keys(OLIST_EMAIL)
        time.sleep(1)

        # Preencher senha
        log_msg("Preenchendo senha...")
        password_input = driver.find_element(By.ID, "password")
        password_input.clear()
        password_input.send_keys(OLIST_PASSWORD)
        time.sleep(1)

        # Clicar em login
        log_msg("Clicando em login...")
        login_button = driver.find_element(By.ID, "kc-login")
        login_button.click()
        time.sleep(5)

        # Aguardar autorização (pode ter tela de consentimento)
        try:
            approve_button = WebDriverWait(driver, 10).until(
                EC.presence_of_element_located((By.NAME, "authorize"))
            )
            log_msg("Clicando em autorizar...")
            approve_button.click()
            time.sleep(5)
        except:
            log_msg("Sem tela de consentimento (já autorizado)")

        # Aguardar redirecionamento e extrair código
        log_msg("Aguardando redirecionamento...")
        WebDriverWait(driver, 15).until(
            lambda d: 'setup-oauth.php' in d.current_url or 'code=' in d.current_url
        )

        current_url = driver.current_url
        log_msg(f"URL final: {current_url[:80]}...")

        # Extrair código da URL
        if 'code=' in current_url:
            code = current_url.split('code=')[1].split('&')[0]
            log_msg(f"Código obtido: {code[:30]}...")

            # Verificar se o arquivo de config foi criado
            time.sleep(2)
            if CONFIG_FILE.exists():
                log_msg(f"✅ Token foi salvo em {CONFIG_FILE}")
                with open(CONFIG_FILE, 'r') as f:
                    config = json.load(f)
                    log_msg(f"✅ Refresh token: {config.get('refresh_token', 'N/A')[:30]}...")
                    log_msg(f"✅ Access token: {config.get('access_token', 'N/A')[:30]}...")
                return True
            else:
                log_msg("⚠️ Arquivo de config não foi criado")
                return False
        else:
            log_msg("❌ Código não encontrado na URL")
            return False

    except Exception as e:
        log_msg(f"❌ Erro: {str(e)}")
        import traceback
        log_msg(traceback.format_exc())
        return False

    finally:
        if driver:
            driver.quit()
            log_msg("Chrome fechado")

def main():
    """Função principal"""
    log_msg("=== AUTO OAUTH LOGIN INICIADO ===")

    # Criar diretório de tokens
    TOKENS_DIR.mkdir(parents=True, exist_ok=True)

    # Fazer login
    if login_and_get_token():
        log_msg("✅ LOGIN CONCLUÍDO COM SUCESSO!")
        log_msg("📊 Agora você pode rodar: python scripts/auto-sync-agora.py")
        return 0
    else:
        log_msg("❌ FALHA NO LOGIN")
        return 1

if __name__ == '__main__':
    sys.exit(main())
