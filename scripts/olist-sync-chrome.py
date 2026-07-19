#!/usr/bin/env python3
"""
Sincronizar Olist com Chrome + Selenium - Automação completa
Abre Chrome, faz login, captura código, sincroniza 198 produtos
"""
import sys
import time
import json
from pathlib import Path
from datetime import datetime

print("\n" + "="*70)
print("INSTALANDO WEBDRIVER...")
print("="*70)

try:
    from selenium import webdriver
    from selenium.webdriver.common.by import By
    from selenium.webdriver.chrome.service import Service
    from selenium.webdriver.support.ui import WebDriverWait
    from selenium.webdriver.support import expected_conditions as EC
    from selenium.webdriver.chrome.options import Options
    print("[OK] Selenium já instalado")
except ImportError:
    print("[INSTALANDO] pip install selenium webdriver-manager")
    import subprocess
    subprocess.check_call([sys.executable, "-m", "pip", "install", "selenium", "webdriver-manager", "-q"])
    from selenium import webdriver
    from selenium.webdriver.common.by import By
    from selenium.webdriver.chrome.service import Service
    from selenium.webdriver.support.ui import WebDriverWait
    from selenium.webdriver.support import expected_conditions as EC
    from selenium.webdriver.chrome.options import Options

try:
    from webdriver_manager.chrome import ChromeDriverManager
    print("[OK] WebDriver Manager instalado")
except ImportError:
    print("[AVISO] WebDriver Manager não encontrado")

import requests

# ============================================================================
# CONFIGURAÇÃO
# ============================================================================

BASE_URL = "https://shopvivaliz.com.br"
CONNECT_URL = f"{BASE_URL}/olist/connect.php"
SYNC_URL = f"{BASE_URL}/olist/sync-products.php"

EMAIL = os.getenv("OLIST_EMAIL") or os.getenv("OLIST_USER") or os.getenv("EMAIL_USER") or ""
SENHA = os.getenv("OLIST_PASSWORD") or os.getenv("EMAIL_PASSWORD") or ""

print("\n" + "="*70)
print("SINCRONIZAR 198 PRODUTOS - CHROME AUTOMATION")
print("="*70)

driver = None

try:
    # ========================================================================
    # PASSO 1: Iniciar Chrome
    # ========================================================================

    print("\n[1] Iniciando Chrome...")

    options = Options()
    # options.add_argument("--headless")  # Descomente para headless
    options.add_argument("--no-sandbox")
    options.add_argument("--disable-dev-shm-usage")
    options.add_argument("--disable-blink-features=AutomationControlled")
    options.add_argument("--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36")

    try:
        from webdriver_manager.chrome import ChromeDriverManager
        driver = webdriver.Chrome(
            service=Service(ChromeDriverManager().install()),
            options=options
        )
    except:
        # Tentar sem webdriver-manager
        driver = webdriver.Chrome(options=options)

    print("    [OK] Chrome iniciado")

    # ========================================================================
    # PASSO 2: Acessar página de conexão OAuth
    # ========================================================================

    print(f"\n[2] Acessando: {CONNECT_URL}")
    driver.get(CONNECT_URL)
    time.sleep(3)

    # Aguardar redirecionamento para Olist
    print("[3] Aguardando redirecionamento para Olist...")

    wait = WebDriverWait(driver, 30)
    wait.until(lambda d: "accounts.tiny.com.br" in d.current_url or "id.olist.com" in d.current_url)
    print(f"    [OK] Redirecionado para: {driver.current_url[:60]}...")

    # ========================================================================
    # PASSO 3: Preencher email
    # ========================================================================

    print("\n[4] Preenchendo email...")

    email_selectors = [
        "input[type='email']",
        "input[name='email']",
        "input[name='username']",
        "input[type='text'][autocomplete='email']",
        "input[type='text'][placeholder*='mail' i]"
    ]

    email_input = None
    for selector in email_selectors:
        try:
            email_input = wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, selector)), timeout=10)
            if email_input:
                break
        except:
            pass

    if email_input:
        email_input.clear()
        email_input.send_keys(EMAIL)
        print(f"    [OK] Email: {EMAIL}")

        # Procurar botão "Continuar" ou "Próximo"
        time.sleep(1)
        try:
            next_btn = driver.find_element(By.CSS_SELECTOR, "button[type='submit'], button:not([disabled])")
            next_btn.click()
            print("    [OK] Clicado em Continuar")
            time.sleep(2)
        except:
            print("    [AVISO] Botão não encontrado")
    else:
        print("    [ERRO] Campo de email não encontrado")

    # ========================================================================
    # PASSO 4: Preencher senha
    # ========================================================================

    print("\n[5] Preenchendo senha...")

    password_selectors = [
        "input[type='password']",
        "input[name='password']",
        "input[name='senha']"
    ]

    password_input = None
    for selector in password_selectors:
        try:
            password_input = wait.until(EC.presence_of_element_located((By.CSS_SELECTOR, selector)), timeout=10)
            if password_input:
                break
        except:
            pass

    if password_input:
        password_input.clear()
        password_input.send_keys(SENHA)
        print("    [OK] Senha preenchida")

        time.sleep(1)
        try:
            login_btn = driver.find_element(By.CSS_SELECTOR, "button[type='submit'], input[type='submit']")
            login_btn.click()
            print("    [OK] Clicado em Login")
            time.sleep(3)
        except:
            print("    [AVISO] Botão de login não encontrado")
    else:
        print("    [ERRO] Campo de senha não encontrado")

    # ========================================================================
    # PASSO 5: Autorizar aplicação
    # ========================================================================

    print("\n[6] Procurando botão de autorização...")

    try:
        auth_btn = driver.find_element(By.CSS_SELECTOR, "button:not([disabled])")
        if auth_btn and ("autorizar" in auth_btn.text.lower() or "consentir" in auth_btn.text.lower() or "permitir" in auth_btn.text.lower()):
            auth_btn.click()
            print("    [OK] Clicado em Autorizar")
            time.sleep(2)
    except:
        print("    [INFO] Nenhum botão de autorização encontrado (pode estar autorizado)")

    # ========================================================================
    # PASSO 6: Aguardar callback
    # ========================================================================

    print("\n[7] Aguardando redirecionamento para callback...")

    max_wait = 120
    start_time = time.time()

    while time.time() - start_time < max_wait:
        try:
            current_url = driver.current_url
            if "callback.php" in current_url:
                print(f"    [OK] Callback recebido!")
                break
        except:
            pass

        time.sleep(2)

    # ========================================================================
    # PASSO 7: Acessar sync-products.php
    # ========================================================================

    print("\n[8] Acessando página de sincronização...")
    print(f"    URL: {SYNC_URL}")

    driver.get(SYNC_URL)

    print("    Aguardando sincronização (pode levar 1-5 minutos)...")

    # Aguardar resultado
    max_sync_wait = 300  # 5 minutos
    sync_start = time.time()

    while time.time() - sync_start < max_sync_wait:
        try:
            page_source = driver.page_source

            if '"sucesso": true' in page_source or '"total_produtos"' in page_source:
                print("\n    [OK] Sincronização completada!")

                # Extrair JSON da página
                import re
                json_match = re.search(r'\{[^{}]*"sucesso"[^{}]*\}', page_source, re.DOTALL)
                if json_match:
                    try:
                        result = json.loads(json_match.group(0))

                        print("\n" + "="*70)
                        print("RESULTADO DA SINCRONIZAÇÃO")
                        print("="*70)
                        print(f"Total: {result.get('total_produtos', '?')} produtos")
                        print(f"Com imagem: {result.get('com_imagem', '?')}")
                        print(f"Sem imagem: {result.get('sem_imagem', '?')}")
                        print(f"Taxa: {result.get('taxa_cobertura', '?')}%")
                        print(f"Mensagem: {result.get('mensagem', '?')}")

                        # Salvar resultado
                        result_file = Path("logs/olist-sync-resultado.json")
                        result_file.parent.mkdir(exist_ok=True)

                        with open(result_file, 'w', encoding='utf-8') as f:
                            json.dump({
                                'timestamp': datetime.now().isoformat(),
                                'sucesso': True,
                                'resultado': result
                            }, f, ensure_ascii=False, indent=2)

                        print(f"\nResultado salvo: {result_file}")

                    except Exception as e:
                        print(f"Erro ao parsear JSON: {e}")

                break

        except Exception as e:
            print(f"Erro ao verificar resultado: {e}")

        time.sleep(5)

    print("\n" + "="*70)
    print("PROCESSO CONCLUÍDO")
    print("="*70)

except Exception as e:
    print(f"\n[ERRO CRÍTICO] {str(e)}")
    import traceback
    traceback.print_exc()

finally:
    if driver:
        print("\nFechando navegador...")
        try:
            driver.quit()
        except:
            pass

print("\nPressione Enter para sair...")
input()
