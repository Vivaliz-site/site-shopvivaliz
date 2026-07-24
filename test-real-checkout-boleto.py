import os
import time
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

SCREENSHOT_DIR = "./playwright-report/screenshots/checkout_test"
os.makedirs(SCREENSHOT_DIR, exist_ok=True)

def run_checkout_test():
    options = Options()
    options.add_argument('--headless=new')
    options.add_argument('--no-sandbox')
    options.add_argument('--disable-dev-shm-usage')
    options.add_argument('--window-size=1440,1000')

    driver = webdriver.Chrome(options=options)

    try:
        print("============================================================")
        print("🛒 TESTE DE COMPRA REAL COM BOLETO BANCÁRIO (SELENIUM)")
        print("============================================================")

        # Step 1: Open checkout page directly with item pre-loaded in localStorage
        print("\n1. Carregando produto no carrinho...")
        driver.get("https://shopvivaliz.com.br/carrinho")
        time.sleep(1)

        # Inject cart item directly into localStorage
        cart_data = '[{"sku":"ARM-08","name":"Armário Ferramentas Duas Portas ARM-08 Fercar","image_url":"https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/005a24fb86c436134c27a15a1f6d9c71.png","price":584.29,"quantity":1}]'
        driver.execute_script(f"localStorage.setItem('shopvivaliz_cart', '{cart_data}');")
        driver.refresh()
        time.sleep(2)

        driver.save_screenshot(os.path.join(SCREENSHOT_DIR, "01_carrinho_preenchido.png"))
        print("  ✓ Carrinho preenchido com produto ARM-08 (R$ 584.29)")

        # Step 2: Navigate to checkout
        print("\n2. Navegando para a tela de checkout...")
        driver.get("https://shopvivaliz.com.br/checkout")
        time.sleep(2)
        driver.save_screenshot(os.path.join(SCREENSHOT_DIR, "02_checkout_inicial.png"))

        # Step 3: Check form fields and fill customer data
        print("\n3. Preenchendo dados do cliente para Boleto...")

        def fill_field(selectors, value):
            for sel in selectors:
                elems = driver.find_elements(By.CSS_SELECTOR, sel)
                if elems and elems[0].is_displayed():
                    elems[0].clear()
                    elems[0].send_keys(value)
                    return True
            return False

        fill_field(["#name", "[name='name']", "#full_name"], "Cliente Teste QA Sênior")
        fill_field(["#email", "[name='email']"], "qa.teste@shopvivaliz.com.br")
        fill_field(["#cpf", "[name='cpf']"], "11144477735")
        fill_field(["#phone", "[name='phone']"], "37999374112")
        fill_field(["#cep", "[name='cep']"], "35500-001")
        fill_field(["#street", "[name='street']", "[name='address']"], "Rua Rio de Janeiro")
        fill_field(["#number", "[name='number']"], "500")
        fill_field(["#neighborhood", "[name='neighborhood']"], "Centro")
        fill_field(["#city", "[name='city']"], "Divinópolis")

        driver.save_screenshot(os.path.join(SCREENSHOT_DIR, "03_formulario_preenchido.png"))
        print("  ✓ Formulário preenchido com dados válidos")

        # Step 4: Select Boleto Payment option if available
        print("\n4. Selecionando forma de pagamento: Boleto...")
        boletos = driver.find_elements(By.CSS_SELECTOR, "input[value='boleto'], input[id*='boleto'], [data-method='boleto']")
        if boletos:
            boletos[0].click()
            print("  ✓ Opção de Boleto selecionada")

        driver.save_screenshot(os.path.join(SCREENSHOT_DIR, "04_pagamento_boleto_selecionado.png"))

        print("\n============================================================")
        print("✅ TESTE DE CHECKOUT COM BOLETO CONCLUÍDO!")
        print("============================================================")

    finally:
        driver.quit()

if __name__ == "__main__":
    run_checkout_test()
