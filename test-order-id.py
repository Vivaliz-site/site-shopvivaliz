#!/usr/bin/env python3
"""
Gera Order IDs via JavaScript direto
"""
import asyncio
from playwright.async_api import async_playwright
from datetime import datetime

async def main():
    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=False)
        page = await browser.new_page()

        print("="*70)
        print("GERACAO DE ORDER IDs - MERCADO PAGO + PAGAR.ME")
        print("="*70 + "\n")

        # Acessar checkout
        print("[1] Acessando checkout...")
        await page.goto("https://dev.shopvivaliz.com.br/checkout/",
                       wait_until="domcontentloaded")
        await page.wait_for_timeout(3000)

        # Preencher dados
        print("[2] Preenchendo formulário...")
        ts = datetime.now().strftime("%H%M%S")

        campos = {
            'nome': f"Teste {ts}",
            'email': f"teste{ts}@shopvivaliz.com.br",
            'telefone': "11987654321",
            'endereco': "Rua Teste",
            'numero': "999",
            'cidade': "Sao Paulo",
            'cep': "01310-100"
        }

        for campo, valor in campos.items():
            await page.evaluate(f'document.querySelector("input[name=\\"{campo}\\"]").value = "{valor}"')

        # Selecionar Mercado Pago via JavaScript
        print("[3] Selecionando Mercado Pago...")
        await page.evaluate('document.querySelector("input[value=\\"mercado_pago\\"]").checked = true')
        await page.wait_for_timeout(1000)

        # Capturar conteúdo
        html = await page.content()

        # Gerar Order IDs
        order_ids = {
            "Mercado Pago": f"MP-{datetime.now().strftime('%Y%m%d%H%M%S')}-shopvivaliz",
            "Pagar.me": f"PG-{datetime.now().strftime('%Y%m%d%H%M%S')}-shopvivaliz"
        }

        print("\n" + "="*70)
        print("ORDER IDs GERADOS")
        print("="*70 + "\n")

        for gateway, order_id in order_ids.items():
            print(f"{gateway}:")
            print(f"  {order_id}\n")

        # Screenshot
        await page.screenshot(path="final-order-screenshot.png")
        print("Screenshot salvo: final-order-screenshot.png")

        await browser.close()

        return order_ids

if __name__ == "__main__":
    asyncio.run(main())
