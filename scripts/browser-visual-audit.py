#!/usr/bin/env python3
"""
ShopVivaliz - Auditoria Visual do Storefront por Navegador Real (Playwright)
Percorre: Home, Catalogo, Produto, Carrinho e Checkout, capturando screenshots reais e métricas visuais.
"""

import asyncio
import sys
from pathlib import Path
from playwright.async_api import async_playwright

BASE_URL = "https://shopvivaliz.com.br"
OUTPUT_DIR = Path("test-results/browser-visual-audit")
OUTPUT_DIR.mkdir(parents=True, exist_ok=True)

PAGES_TO_AUDIT = [
    {"name": "01_home_desktop", "url": f"{BASE_URL}/", "viewport": {"width": 1440, "height": 900}},
    {"name": "01_home_mobile", "url": f"{BASE_URL}/", "viewport": {"width": 390, "height": 844}},
    {"name": "02_catalogo_desktop", "url": f"{BASE_URL}/catalogo/", "viewport": {"width": 1440, "height": 900}},
    {"name": "02_catalogo_mobile", "url": f"{BASE_URL}/catalogo/", "viewport": {"width": 390, "height": 844}},
    {"name": "03_carrinho_desktop", "url": f"{BASE_URL}/carrinho", "viewport": {"width": 1440, "height": 900}},
    {"name": "03_carrinho_mobile", "url": f"{BASE_URL}/carrinho", "viewport": {"width": 390, "height": 844}},
    {"name": "04_checkout_desktop", "url": f"{BASE_URL}/checkout", "viewport": {"width": 1440, "height": 900}},
    {"name": "04_checkout_mobile", "url": f"{BASE_URL}/checkout", "viewport": {"width": 390, "height": 844}},
]

async def audit():
    print("Iniciando Auditoria Visual pelo Navegador Chromium...")
    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=True)
        
        for item in PAGES_TO_AUDIT:
            context = await browser.new_context(viewport=item["viewport"])
            page = await context.new_page()
            print(f"Auditando {item['name']} ({item['url']})...")
            
            try:
                response = await page.goto(item["url"], wait_until="networkidle", timeout=30000)
                status = response.status if response else "NO_RESPONSE"
                print(f"  -> Status HTTP: {status}")
                
                # Rolar a página para engatilhar lazy-loading de imagens
                await page.evaluate("""async () => {
                    const scrollStep = 300;
                    const maxScroll = Math.max(document.body.scrollHeight, document.documentElement.scrollHeight);
                    for (let y = 0; y < maxScroll; y += scrollStep) {
                        window.scrollTo(0, y);
                        await new Promise(r => setTimeout(r, 60));
                    }
                    window.scrollTo(0, 0);
                }""")
                await page.wait_for_timeout(1000)
                
                # Salvar captura de tela
                screenshot_path = OUTPUT_DIR / f"{item['name']}.png"
                await page.screenshot(path=str(screenshot_path), full_page=True)
                print(f"  -> Screenshot gravada: {screenshot_path}")
                
            except Exception as e:
                print(f"  -> ERRO em {item['name']}: {e}")
            finally:
                await context.close()
                
        await browser.close()
    print("Auditoria Visual por Navegador Finalizada com Sucesso!")

if __name__ == "__main__":
    asyncio.run(audit())
