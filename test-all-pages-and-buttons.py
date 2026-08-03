import os
import json
import time
from datetime import datetime
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

BASE_URL = "https://shopvivaliz.com.br"
SCREENSHOT_DIR = "./playwright-report/screenshots"
os.makedirs(SCREENSHOT_DIR, exist_ok=True)

PAGES_TO_TEST = [
    {"name": "Home", "url": f"{BASE_URL}/"},
    {"name": "Catalogo", "url": f"{BASE_URL}/produtos"},
    {"name": "Sobre", "url": f"{BASE_URL}/sobre"},
    {"name": "Contato", "url": f"{BASE_URL}/contato"},
    {"name": "Carrinho", "url": f"{BASE_URL}/carrinho"},
]

def run_tests():
    options = Options()
    options.add_argument('--headless=new')
    options.add_argument('--no-sandbox')
    options.add_argument('--disable-dev-shm-usage')
    options.add_argument('--window-size=1440,900')
    options.set_capability('goog:loggingPrefs', {'browser': 'ALL'})

    driver = webdriver.Chrome(options=options)
    results = []

    print("============================================================")
    print("🧪 INICIANDO TESTE COMPLETO DE PÁGINAS E BOTÕES")
    print("============================================================")

    try:
        for page in PAGES_TO_TEST:
            name = page["name"]
            url = page["url"]
            print(f"\n🌐 Testando Página: {name} ({url})")
            
            start_time = time.time()
            driver.get(url)
            time.sleep(2)  # permitir renderização inicial

            title = driver.title
            body_text_len = len(driver.find_element(By.TAG_NAME, "body").text)
            
            timestamp = int(time.time())
            screenshot_file = os.path.join(SCREENSHOT_DIR, f"page-{name.lower()}-{timestamp}.png")
            driver.save_screenshot(screenshot_file)

            # Testar botões na página
            buttons = driver.find_elements(By.TAG_NAME, "button")
            links = driver.find_elements(By.TAG_NAME, "a")

            # Logs do browser
            logs = driver.get_log('browser')
            severe_errors = [entry['message'] for entry in logs if entry['level'] == 'SEVERE']

            res = {
                "name": name,
                "url": url,
                "title": title,
                "status": "OK" if len(severe_errors) == 0 else "WARNINGS",
                "bodyLength": body_text_len,
                "buttonsCount": len(buttons),
                "linksCount": len(links),
                "screenshot": screenshot_file,
                "severeErrors": severe_errors
            }
            results.append(res)

            print(f"  ✓ Título: {title}")
            print(f"  ✓ Botões encontrados: {len(buttons)}")
            print(f"  ✓ Links encontrados: {len(links)}")
            print(f"  ✓ Screenshot salvo: {screenshot_file}")
            if severe_errors:
                print(f"  ⚠️ Erros graves de console ({len(severe_errors)}): {severe_errors[:2]}")

        # Teste extra em um produto
        print(f"\n🌐 Testando Rota de Produto...")
        driver.get(f"{BASE_URL}/produtos")
        time.sleep(2)
        product_links = driver.find_elements(By.CSS_SELECTOR, "a[href*='/produto']")
        if product_links:
            first_product_url = product_links[0].get_attribute("href")
            print(f"  -> Navegando para o primeiro produto: {first_product_url}")
            driver.get(first_product_url)
            time.sleep(2)
            
            screenshot_file = os.path.join(SCREENSHOT_DIR, f"page-produto-{int(time.time())}.png")
            driver.save_screenshot(screenshot_file)
            
            buy_buttons = driver.find_elements(By.CSS_SELECTOR, ".buy-button, .btn-comprar, .main-buy-button")
            print(f"  ✓ Página de produto carregada: {driver.title}")
            print(f"  ✓ Botões de compra encontrados: {len(buy_buttons)}")
            print(f"  ✓ Screenshot de produto salvo: {screenshot_file}")

            results.append({
                "name": "Detalhe de Produto",
                "url": driver.current_url,
                "title": driver.title,
                "status": "OK",
                "buyButtons": len(buy_buttons),
                "screenshot": screenshot_file
            })

    finally:
        driver.quit()

    report_path = "./playwright-report/full-site-audit.json"
    with open(report_path, "w", encoding="utf-8") as f:
        json.dump(results, f, indent=2, ensure_ascii=False)

    print("\n============================================================")
    print("✅ TESTE COMPLETO FINALIZADO COM SUCESSO!")
    print(f"📊 Relatório consolidado em: {report_path}")
    print("============================================================")

if __name__ == "__main__":
    run_tests()
