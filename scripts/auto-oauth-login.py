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
CLIENT_ID = os.getenv('OLIST_CLIENT_ID', 'SEU_OLIST_CLIENT_ID_AQUI')
CLIENT_SECRET = os.getenv('OLIST_CLIENT_SECRET', 'SEU_OLIST_CLIENT_SECRET_AQUI')
REDIRECT_URI = 'https://dev.shopvivaliz.com.br/olist/oauth-callback-simple.php'

# Credenciais Olist
OLIST_EMAIL = os.getenv('OLIST_EMAIL') or os.getenv('OLIST_USER') or os.getenv('EMAIL_USER') or ''
OLIST_PASSWORD = os.getenv('OLIST_PASSWORD') or os.getenv('EMAIL_PASSWORD') or ''

# Caminhos
PROJECT_ROOT = Path(__file__).parent.parent
TOKENS_DIR = PROJECT_ROOT / '.tokens'
CONFIG_FILE = TOKENS_DIR / 'olist-config.json'
LOG_FILE = PROJECT_ROOT / 'logs' / 'auto-oauth-login.log'

def log_msg(msg):
    """Log com timestamp"""
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    line = f"[{timestamp}] {msg}"
    # Remove caracteres especiais para evitar erro de encoding
    line_safe = line.encode('ascii', 'replace').decode('ascii')
    print(line_safe)

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

        # Tentar múltiplos seletores para o email
        log_msg("Procurando campo de email...")
        email_input = None
        for selector_type, selector_value in [
            (By.ID, "username"),
            (By.NAME, "username"),
            (By.NAME, "email"),
            (By.CSS_SELECTOR, "input[type='email']"),
            (By.CSS_SELECTOR, "input[name='username']"),
            (By.CSS_SELECTOR, "input[id='username']"),
        ]:
            try:
                email_input = WebDriverWait(driver, 3).until(
                    EC.presence_of_element_located((selector_type, selector_value))
                )
                log_msg(f"Campo encontrado: {selector_type} = {selector_value}")
                break
            except:
                pass

        if not email_input:
            log_msg("ERRO: Campo de email nao encontrado. Salvando screenshot...")
            driver.save_screenshot(str(LOG_FILE.parent / 'login_page.png'))
            log_msg(f"Screenshot salvo em {LOG_FILE.parent / 'login_page.png'}")
            raise Exception("Campo de email nao encontrado")

        log_msg("Preenchendo email...")
        email_input.clear()
        email_input.send_keys(OLIST_EMAIL)
        time.sleep(2)

        # Preencher senha
        log_msg("Preenchendo senha...")
        password_input = None
        for selector_type, selector_value in [
            (By.ID, "password"),
            (By.NAME, "password"),
            (By.CSS_SELECTOR, "input[type='password']"),
        ]:
            try:
                password_input = driver.find_element(selector_type, selector_value)
                break
            except:
                pass

        if not password_input:
            raise Exception("Campo de senha nao encontrado")

        password_input.clear()
        password_input.send_keys(OLIST_PASSWORD)
        time.sleep(2)

        # Clicar em login
        log_msg("Clicando em login...")
        login_button = None
        for selector_type, selector_value in [
            (By.ID, "kc-login"),
            (By.NAME, "login"),
            (By.CSS_SELECTOR, "button[type='submit']"),
            (By.XPATH, "//button[contains(text(), 'login') or contains(text(), 'Entrar') or contains(text(), 'Login')]"),
        ]:
            try:
                login_button = driver.find_element(selector_type, selector_value)
                break
            except:
                pass

        if not login_button:
            raise Exception("Botao de login nao encontrado")

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
            log_msg(f"Codigo obtido: {code[:30]}...")

            # Trocar código por token chamando sync-agora.php com o código
            log_msg("Trocando codigo por token...")
            import subprocess
            result = subprocess.run(
                [sys.executable, 'scripts/auto-sync-agora.py', '--code', code],
                capture_output=True,
                text=True,
                cwd=PROJECT_ROOT
            )

            log_msg(result.stdout)
            if result.returncode == 0:
                log_msg("Sucesso: Token obtido!")
                return True
            else:
                log_msg(f"Erro ao trocar codigo: {result.stderr}")
                return False
        else:
            log_msg("Codigo nao encontrado na URL")
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
