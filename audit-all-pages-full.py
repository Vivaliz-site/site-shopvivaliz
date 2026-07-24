import os
import json
import time
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By

BASE_URL = "https://shopvivaliz.com.br"
SCREENSHOT_DIR = "./playwright-report/screenshots/audit_full"
os.makedirs(SCREENSHOT_DIR, exist_ok=True)

PAGES = [
    {"name": "01_home", "url": f"{BASE_URL}/"},
    {"name": "02_catalogo", "url": f"{BASE_URL}/produtos"},
    {"name": "03_sobre", "url": f"{BASE_URL}/sobre"},
    {"name": "04_contato", "url": f"{BASE_URL}/contato"},
    {"name": "05_carrinho", "url": f"{BASE_URL}/carrinho"},
    {"name": "06_produto_detalhe", "url": f"{BASE_URL}/produto/kit4r-soprao-kit4rsopro"}
]

def audit():
    options = Options()
    options.add_argument('--headless=new')
    options.add_argument('--no-sandbox')
    options.add_argument('--disable-dev-shm-usage')
    options.add_argument('--window-size=1440,1000')

    driver = webdriver.Chrome(options=options)
    report = []

    print("============================================================")
    print("🔍 AUDITORIA COMPLETA DE PÁGINAS POR SCREENSHOT (LIVE)")
    print("============================================================")

    try:
        for p in PAGES:
            name = p["name"]
            url = p["url"]
            print(f"\n📸 Auditando: {name} -> {url}")
            driver.get(url)
            time.sleep(2)

            # Caputra de tela
            file_path = os.path.join(SCREENSHOT_DIR, f"{name}.png")
            driver.save_screenshot(file_path)

            title = driver.title
            body_text = driver.find_element(By.TAG_NAME, "body").text
            has_header = len(driver.find_elements(By.CSS_SELECTOR, "header.sv-navbar")) > 0
            has_footer = len(driver.find_elements(By.TAG_NAME, "footer")) > 0

            item = {
                "page": name,
                "url": url,
                "title": title,
                "hasHeader": has_header,
                "hasFooter": has_footer,
                "contentLength": len(body_text),
                "screenshot": file_path
            }
            report.append(item)
            print(f"  ✓ Título: {title}")
            print(f"  ✓ Header ativo: {has_header} | Footer ativo: {has_footer}")
            print(f"  ✓ Screenshot salvo: {file_path}")

    finally:
        driver.quit()

    report_file = "./playwright-report/audit-page-by-page.json"
    with open(report_file, "w", encoding="utf-8") as f:
        json.dump(report, f, indent=2, ensure_ascii=False)

    print("\n============================================================")
    print("✅ AUDITORIA COMPLETA CONCLUÍDA!")
    print("============================================================")

if __name__ == "__main__":
    audit()
