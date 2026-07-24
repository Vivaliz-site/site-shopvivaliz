#!/usr/bin/env python3
"""
Teste Visual - Homepage ShopVivaliz
Tira screenshot e verifica estrutura DOM renderizada

Requer:
  pip install selenium pillow
"""

import json
import os
import sys
from datetime import datetime
from pathlib import Path

# Try Selenium/Playwright
try:
    from selenium import webdriver
    from selenium.webdriver.common.by import By
    from selenium.webdriver.support.ui import WebDriverWait
    from selenium.webdriver.support import expected_conditions as EC
    from selenium.webdriver.chrome.options import Options
    SELENIUM_AVAILABLE = True
except ImportError:
    SELENIUM_AVAILABLE = False
    print("⚠️  Selenium não instalado. Use: pip install selenium")

SITE_URL = "https://shopvivaliz.com.br/"
SCREENSHOT_DIR = "./playwright-report/screenshots"
REPORT_FILE = "./playwright-report/visual-test-report.json"

# Criar diretório
os.makedirs(SCREENSHOT_DIR, exist_ok=True)

def test_with_selenium():
    """Teste com Selenium WebDriver"""
    if not SELENIUM_AVAILABLE:
        print("❌ Selenium não disponível")
        return False

    print("🌐 Abrindo página com Selenium...")

    try:
        # Setup Chrome options
        chrome_options = Options()
        chrome_options.add_argument("--no-sandbox")
        chrome_options.add_argument("--disable-dev-shm-usage")

        # Iniciar driver
        driver = webdriver.Chrome(options=chrome_options)
        driver.get(SITE_URL)

        # Esperar pelo hero carousel carregar
        WebDriverWait(driver, 10).until(
            lambda d: d.find_elements(By.CLASS_NAME, "hero-carousel-section")
        )

        print("✅ Página carregada")

        # Screenshot
        screenshot_path = os.path.join(SCREENSHOT_DIR, f"homepage-{int(datetime.now().timestamp())}.png")
        driver.save_screenshot(screenshot_path)
        print(f"📸 Screenshot: {screenshot_path}")

        # Verificações DOM
        checks = {
            "timestamp": datetime.now().isoformat(),
            "url": driver.current_url,
            "title": driver.title,
            "heroCarousel": {
                "exists": len(driver.find_elements(By.CLASS_NAME, "hero-carousel-section")) > 0,
                "elements": len(driver.find_elements(By.CSS_SELECTOR, "[class*='hero-carousel']")),
            },
            "homeCategories": {
                "exists": len(driver.find_elements(By.CLASS_NAME, "home-categories")) > 0,
                "categoryCards": len(driver.find_elements(By.CLASS_NAME, "category-card")),
            },
            "products": {
                "productCards": len(driver.find_elements(By.CLASS_NAME, "product-card")),
                "images": len(driver.find_elements(By.CSS_SELECTOR, ".product-card img")),
            },
        }

        print("\n📋 Verificações DOM:")
        print(json.dumps(checks, indent=2, default=str))

        # Salvar relatório
        report = {
            "timestamp": datetime.now().isoformat(),
            "url": SITE_URL,
            "checks": checks,
            "screenshotPath": screenshot_path,
        }

        with open(REPORT_FILE, 'w', encoding='utf-8') as f:
            json.dump(report, f, indent=2, default=str)

        print(f"\n✅ Relatório: {REPORT_FILE}")

        # Capturar console logs
        try:
            print("\n🚨 CONSOLE LOGS DO BROWSER:")
            for entry in driver.get_log('browser'):
                print(f"[{entry['level']}] {entry['message']}")
        except Exception as log_err:
            print(f"⚠️ Não foi possível obter logs do browser: {log_err}")

        driver.quit()
        return True

    except Exception as e:
        print(f"❌ Erro: {e}")
        return False

def test_simple_curl():
    """Teste simples com curl (sem JavaScript)"""
    print("🌐 Testando com curl (sem JavaScript renderizado)...")

    try:
        import subprocess
        result = subprocess.run(
            ["curl", "-s", SITE_URL],
            capture_output=True,
            text=True,
            timeout=10
        )

        html = result.stdout
        checks = {
            "timestamp": datetime.now().isoformat(),
            "url": SITE_URL,
            "htmlSize": len(html),
            "hasHeroCarousel": "hero-carousel" in html,
            "hasHomeCategories": "home-categories" in html,
            "hasProductCards": "product-card" in html,
            "cssLinks": html.count("<link rel=\"stylesheet\""),
            "scriptTags": html.count("<script"),
        }

        print("\n📋 Verificações HTML (curl):")
        print(json.dumps(checks, indent=2, default=str))

        # Procurar por erros
        if "error" in html.lower():
            print("⚠️  Possíveis erros encontrados no HTML")

        # Salvar relatório
        os.makedirs(os.path.dirname(REPORT_FILE), exist_ok=True)
        with open(REPORT_FILE, 'w', encoding='utf-8') as f:
            json.dump(checks, f, indent=2, default=str)

        print(f"✅ Relatório: {REPORT_FILE}")
        return True

    except Exception as e:
        print(f"❌ Erro: {e}")
        return False

if __name__ == "__main__":
    print("=" * 60)
    print("🧪 Teste Visual - ShopVivaliz Homepage")
    print("=" * 60)

    # Tentar Selenium primeiro, depois curl
    if SELENIUM_AVAILABLE:
        success = test_with_selenium()
    else:
        print("⚠️  Selenium não disponível, usando curl simples...")
        success = test_simple_curl()

    if success:
        print("\n✅ Teste concluído com sucesso!")
        print(f"📊 Verifique: {REPORT_FILE}")
        sys.exit(0)
    else:
        print("\n❌ Teste falhou")
        sys.exit(1)
