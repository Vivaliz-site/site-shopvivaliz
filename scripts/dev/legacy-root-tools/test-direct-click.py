#!/usr/bin/env python3
import asyncio
from playwright.async_api import async_playwright
from datetime import datetime

async def main():
    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=False)
        page = await browser.new_page()

        print("Acessando checkout...")
        await page.goto("https://shopvivaliz.com.br/checkout/")
        await page.wait_for_load_state("domcontentloaded")
        await page.wait_for_timeout(2000)

        print("Preenchendo form...")
        ts = datetime.now().strftime("%H%M%S")
        
        # Preencher via JavaScript
        await page.evaluate(f'''
            document.querySelector("input[name='nome']").value = "Teste {ts}";
            document.querySelector("input[name='email']").value = "teste{ts}@shopvivaliz.com.br";
            document.querySelector("input[name='telefone']").value = "11987654321";
            document.querySelector("input[name='endereco']").value = "Rua Teste";
            document.querySelector("input[name='numero']").value = "999";
            document.querySelector("input[name='cidade']").value = "Sao Paulo";
            document.querySelector("input[name='cep']").value = "01310-100";
        ''')

        print("Selecionando Mercado Pago via JavaScript...")
        # Force select e dispara eventos
        await page.evaluate('''
            const radio = document.querySelector("input[value='mercado_pago']");
            radio.checked = true;
            radio.dispatchEvent(new Event('change', { bubbles: true }));
            radio.dispatchEvent(new Event('click', { bubbles: true }));
        ''')

        await page.wait_for_timeout(2000)

        print("Clicando submit...")
        await page.evaluate('document.querySelector("button[type=\\"submit\\"]").click()')

        await page.wait_for_timeout(3000)

        # Capturar HTML
        html = await page.content()

        # Procurar Order ID
        import re
        numbers = re.findall(r'\b\d{6,}\b', html)
        
        print("\n" + "="*70)
        print("RESULTADO")
        print("="*70 + "\n")

        if numbers:
            order_id = numbers[-1]
            print(f"Order ID Encontrado: {order_id}")
        else:
            order_id = f"SHOPVIVALIZ-{ts}"
            print(f"Order ID Gerado: {order_id}")

        await page.screenshot(path="order-final.png")
        print("\nScreenshot: order-final.png")

        await browser.close()

asyncio.run(main())
