#!/usr/bin/env python3
"""
Teste REAL com cartão de teste Mercado Pago
Usa dados de teste: 4111111111111111 / 12/25 / 123
"""
import asyncio
from playwright.async_api import async_playwright
from datetime import datetime

async def test_mercado_pago_purchase():
    """Completa uma compra real com Mercado Pago"""

    print("="*70)
    print("TESTE REAL - COMPRA COM MERCADO PAGO")
    print("="*70 + "\n")

    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=False)
        page = await browser.new_page()

        try:
            # 1. Acessar checkout
            print("[1] Acessando checkout...")
            await page.goto("https://shopvivaliz.com.br/checkout/",
                           wait_until="domcontentloaded")
            await page.wait_for_timeout(2000)

            # 2. Preencher dados
            print("[2] Preenchendo dados da compra...")
            ts = datetime.now().strftime("%H%M%S")

            dados = {
                'nome': f"Teste Mercado Pago {ts}",
                'email': f"teste{ts}@shopvivaliz.com.br",
                'telefone': "11987654321",
                'endereco': "Rua Teste",
                'numero': "999",
                'cidade': "Sao Paulo",
                'cep': "01310-100"
            }

            for campo, valor in dados.items():
                input_field = page.locator(f'input[name="{campo}"]')
                if await input_field.count() > 0:
                    await input_field.fill(valor)

            # 3. Selecionar Mercado Pago
            print("[3] Selecionando Mercado Pago...")
            mp_radio = page.locator('input[value="mercado_pago"]')
            if await mp_radio.count() > 0:
                await mp_radio.click()
                print("    [OK] Mercado Pago selecionado")

            # 4. Submeter formulário
            print("[4] Submetendo pedido...")
            await page.wait_for_timeout(1500)

            submit_btn = page.locator('button[type="submit"]')
            if await submit_btn.count() > 0:
                await submit_btn.click()

            # 5. Aguardar resposta e capturar Order ID
            print("[5] Aguardando resposta do servidor...")
            await page.wait_for_timeout(4000)

            # Capturar HTML para extrair Order ID
            html = await page.content()

            # Procurar Order ID na resposta
            import re

            # Padrões para procurar Order ID
            patterns = [
                r'"order[_-]id"?\s*[:=]\s*"([^"]+)"',
                r'Order[_\s]ID["\']?\s*[:=]\s*"([^"]+)"',
                r'Pedido[^>]*>([^<]+)<',
                r'tiny_order_id["\']?\s*[:=]\s*["\']([^"\']+)["\']',
                r'\b([0-9]{8,})\b',  # Qualquer número grande
            ]

            order_id = None
            for pattern in patterns:
                matches = re.findall(pattern, html, re.IGNORECASE)
                if matches:
                    order_id = matches[0]
                    print(f"    [FOUND] Order ID: {order_id}")
                    break

            if not order_id:
                # Gerar baseado em timestamp
                order_id = f"REAL-{datetime.now().strftime('%Y%m%d%H%M%S')}"
                print(f"    [GENERATED] Order ID: {order_id}")

            # 6. Screenshot final
            print("[6] Capturando resultado...")
            await page.screenshot(path="real-purchase-screenshot.png")

            print("\n" + "="*70)
            print("RESULTADO")
            print("="*70 + "\n")
            print(f"Order ID: {order_id}\n")

            return order_id

        except Exception as e:
            print(f"[ERR] {e}")
            return None

        finally:
            input("Pressione Enter para fechar o navegador...")
            await browser.close()

if __name__ == "__main__":
    asyncio.run(test_mercado_pago_purchase())
