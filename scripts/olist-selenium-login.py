#!/usr/bin/env python3
"""
Login Olist com Selenium - Automação real de navegador
Abre Chrome/Firefox, faz login, captura authorization code e sincroniza
"""
import sys
import time
import json
import re
from pathlib import Path
from datetime import datetime

print("\n" + "="*70)
print("VERIFICANDO DEPENDENCIAS...")
print("="*70)

try:
    from selenium import webdriver
    from selenium.webdriver.common.by import By
    from selenium.webdriver.support.ui import WebDriverWait
    from selenium.webdriver.support import expected_conditions as EC
    from selenium.webdriver.chrome.options import Options
    print("[OK] Selenium instalado")
except ImportError:
    print("[ERRO] Selenium não instalado")
    print("\nPara instalar:")
    print("  pip install selenium")
    print("\nE baixar ChromeDriver de:")
    print("  https://chromedriver.chromium.org/")
    sys.exit(1)

import requests

# ============================================================================
# CONFIGURACAO
# ============================================================================

BASE_URL = "https://dev.shopvivaliz.com.br"
OLIST_AUTH_URL = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth"

CLIENT_ID = os.getenv("OLIST_CLIENT_ID") or os.getenv("TINY_CLIENT_ID") or ""
EMAIL = os.getenv("OLIST_EMAIL") or os.getenv("OLIST_USER") or os.getenv("EMAIL_USER") or ""
SENHA = os.getenv("OLIST_PASSWORD") or os.getenv("EMAIL_PASSWORD") or ""

print("\n" + "="*70)
print("OLIST LOGIN COM SELENIUM")
print("="*70)

try:
    # ========================================================================
    # PASSO 1: Iniciar navegador Chrome
    # ========================================================================

    print("\n[1] Iniciando navegador Chrome...")

    chrome_options = Options()
    # chrome_options.add_argument("--headless")  # Descomente para modo headless
    chrome_options.add_argument("--no-sandbox")
    chrome_options.add_argument("--disable-dev-shm-usage")
    chrome_options.add_argument("--disable-blink-features=AutomationControlled")

    # Tentar iniciar Chrome
    try:
        driver = webdriver.Chrome(options=chrome_options)
        print("    [OK] Chrome iniciado")
    except Exception as e:
        print(f"    [AVISO] Chrome não encontrado: {e}")
        print("    Tentando Firefox...")
        try:
            driver = webdriver.Firefox()
            print("    [OK] Firefox iniciado")
        except Exception as e2:
            print(f"    [ERRO] Firefox também não funciona: {e2}")
            sys.exit(1)

    # ========================================================================
    # PASSO 2: Acessar connect.php para redirecionar a Olist
    # ========================================================================

    print("\n[2] Acessando connect.php para autorização...")
    connect_url = f"{BASE_URL}/olist/connect.php"
    driver.get(connect_url)

    # Aguardar redirecionamento para Olist
    print("    Aguardando redirecionamento para Olist...")
    wait = WebDriverWait(driver, 15)

    try:
        # Aguardar até estar em página de login (accounts.tiny.com.br)
        wait.until(lambda d: 'accounts.tiny.com.br' in d.current_url)
        print(f"    [OK] Redirecionado para: {driver.current_url[:60]}...")
    except:
        print(f"    [AVISO] Ainda em: {driver.current_url}")

    # ========================================================================
    # PASSO 3: Fazer login com email
    # ========================================================================

    print("\n[3] Preenchendo formulário de login...")

    try:
        # Preencher email
        email_input = wait.until(
            EC.presence_of_element_located((By.CSS_SELECTOR, 'input[type="email"], input[name="email"], input[id="email"]'))
        )
        email_input.clear()
        email_input.send_keys(EMAIL)
        print(f"    [OK] Email preenchido: {EMAIL}")

        # Buscar botão para próximo passo (pode ser "Continuar" ou "Login")
        time.sleep(1)
        try:
            next_btn = driver.find_element(By.CSS_SELECTOR, 'button:not([disabled])')
            next_btn.click()
            print("    [OK] Clicado em próximo...")
        except:
            print("    [AVISO] Botão não encontrado, tentando enviar formulário...")

        time.sleep(2)

    except Exception as e:
        print(f"    [ERRO] Erro ao preencher email: {e}")

    # ========================================================================
    # PASSO 4: Fazer login com senha
    # ========================================================================

    print("\n[4] Preenchendo senha...")

    try:
        senha_input = wait.until(
            EC.presence_of_element_located((By.CSS_SELECTOR, 'input[type="password"], input[name="senha"]')),
            timeout=10
        )
        senha_input.clear()
        senha_input.send_keys(SENHA)
        print("    [OK] Senha preenchida")

        # Buscar botão de login
        time.sleep(1)
        login_btn = driver.find_element(By.CSS_SELECTOR, 'button[type="submit"], button:not([disabled])')
        login_btn.click()
        print("    [OK] Clicado em Login...")

        time.sleep(3)

    except Exception as e:
        print(f"    [ERRO] Erro ao preencher senha: {e}")

    # ========================================================================
    # PASSO 5: Autorizar aplicação (se houver prompt)
    # ========================================================================

    print("\n[5] Procurando tela de autorização...")

    try:
        # Procurar por botão de autorização
        auth_btn = driver.find_element(By.CSS_SELECTOR, 'button:has-text("Autorizar"), button:has-text("Authorize"), button:has-text("Consentir"), button[type="submit"]')
        auth_btn.click()
        print("    [OK] Clicado em Autorizar...")
        time.sleep(2)
    except:
        print("    [INFO] Nenhuma tela de autorização encontrada (pode estar autorizado)")

    # ========================================================================
    # PASSO 6: Aguardar redirecionamento para callback.php
    # ========================================================================

    print("\n[6] Aguardando redirecionamento para callback.php...")

    authorization_code = None

    try:
        # Aguardar até estar em callback.php
        wait.until(lambda d: 'callback.php' in d.current_url, timeout=20)
        print(f"    [OK] Chegou a callback.php")

        # Procurar pelo código na página
        time.sleep(1)
        page_source = driver.page_source

        # Procurar no elemento com classe code-box
        code_match = re.search(r'<[^>]*class="code-box[^"]*"[^>]*>([^<]+)<', page_source)
        if code_match:
            authorization_code = code_match.group(1).strip()
            print(f"    [OK] Authorization code encontrado!")
            print(f"    Code: {authorization_code[:50]}...")

    except Exception as e:
        print(f"    [ERRO] Timeout esperando callback: {e}")

    # ========================================================================
    # PASSO 7: Se temos o code, acessar sync-products.php
    # ========================================================================

    if authorization_code:
        print("\n[7] Acessando sync-products.php para sincronizar...")

        sync_url = f"{BASE_URL}/olist/sync-products.php"
        driver.get(sync_url)

        print("    Aguardando sincronização (pode levar alguns minutos)...")

        try:
            # Aguardar resposta
            wait.until(lambda d: '"sucesso"' in d.page_source or '198' in d.page_source, timeout=120)
            print("    [OK] Sincronização parece ter completado!")

            # Extrair resultado
            result_json = re.search(r'\{[^{}]*"sucesso"[^{}]*\}', driver.page_source)
            if result_json:
                result = json.loads(result_json.group(0))
                print(f"\n[RESULTADO]")
                print(f"  Total: {result.get('total_produtos', 'desconhecido')}")
                print(f"  Com imagem: {result.get('com_imagem', 'desconhecido')}")
                print(f"  Sem imagem: {result.get('sem_imagem', 'desconhecido')}")
                print(f"  Mensagem: {result.get('mensagem', 'desconhecido')}")

        except Exception as e:
            print(f"    [AVISO] Timeout ou erro: {e}")

        # Salvar resultado
        result_file = Path("logs/olist-selenium-resultado.json")
        result_file.parent.mkdir(exist_ok=True)

        with open(result_file, 'w', encoding='utf-8') as f:
            json.dump({
                'timestamp': datetime.now().isoformat(),
                'authorization_code': authorization_code[:50] + '...',
                'url_final': driver.current_url,
                'sincronizacao_tentada': True
            }, f, ensure_ascii=False, indent=2)

        print(f"\n    Resultado salvo: {result_file}")

    else:
        print("\n[ERRO] Não conseguiu obter authorization code")

except Exception as e:
    print(f"\n[ERRO CRÍTICO] {str(e)}")
    import traceback
    traceback.print_exc()

finally:
    print("\n[8] Fechando navegador...")
    try:
        driver.quit()
    except:
        pass

print("\n" + "="*70)
print("[CONCLUÍDO]")
print("="*70 + "\n")
