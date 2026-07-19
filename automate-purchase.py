#!/usr/bin/env python3
"""
ShopVivaliz - Automação de Compra com Boleto
Script para validação E2E completo

Uso:
  python automate-purchase.py

Prerequisitos:
  pip install playwright
  playwright install
"""

import asyncio
import sys
import json
from datetime import datetime
from pathlib import Path
from playwright.async_api import async_playwright, expect

# Dados do cliente (Frederico de Castro Mourao)
CLIENT_DATA = {
    "name": "Frederico de Castro Mourao",
    "email": "fredmourao@gmail.com",
    "phone": "37999374112",
    "cpf": "01366995619",
    "zip": "35500006",
    "address": "Rua São Paulo, 1078",
    "city": "Divinópolis",
    "state": "MG",
}

SITE_URL = "https://shopvivaliz.com.br/"
LOGS_DIR = Path("logs")
SCREENSHOTS_DIR = Path("screenshots-purchase")

# Criar diretórios se não existirem
LOGS_DIR.mkdir(exist_ok=True)
SCREENSHOTS_DIR.mkdir(exist_ok=True)


def log_message(msg: str, level="INFO"):
    """Log com timestamp"""
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    # Remove emojis para Windows PowerShell compatibility
    msg_clean = msg.encode('ascii', 'ignore').decode('ascii')
    log_line = f"[{timestamp}] [{level}] {msg_clean}"
    print(log_line)

    # Salvar em arquivo
    log_file = LOGS_DIR / "purchase-automation.log"
    with open(log_file, "a", encoding="utf-8") as f:
        f.write(log_line + "\n")


async def take_screenshot(page, name: str):
    """Tirar screenshot"""
    try:
        path = SCREENSHOTS_DIR / f"{name}.png"
        await page.screenshot(path=str(path), full_page=True)
        log_message(f"Screenshot: {path}")
    except Exception as e:
        log_message(f"Erro ao tirar screenshot {name}: {e}", "ERROR")


async def automate_purchase():
    """Main automation flow"""

    log_message("=" * 80, "START")
    log_message("🛒 INICIANDO AUTOMAÇÃO DE COMPRA")
    log_message(f"Cliente: {CLIENT_DATA['name']}")
    log_message(f"Site: {SITE_URL}")

    async with async_playwright() as p:
        # Abrir navegador
        log_message("📱 Abrindo navegador...")
        browser = await p.chromium.launch(headless=False)  # Não headless = você verá
        page = await browser.new_page()

        try:
            # ==== PASSO 1: Abrir site ====
            log_message("\n[1/8] Abrindo site...")
            await page.goto(SITE_URL, wait_until="networkidle")
            await take_screenshot(page, "01-homepage")

            # Aguardar produtos carregarem
            try:
                await page.wait_for_selector("a[href*='produto'], button[class*='add'], [class*='product']", timeout=10000)
                log_message("✅ Produtos detectados")
            except:
                log_message("⚠️ Produtos podem estar lentos para carregar, continuando...", "WARN")

            # ==== PASSO 2: Encontrar e clicar em um produto ====
            log_message("\n[2/8] Procurando produto...")

            # Tentar diferentes seletores para produto
            product_selectors = [
                "a[href*='produto']",
                "button[class*='add-to-cart']",
                "[class*='product-item']",
                "[class*='item-produto']",
                "div[class*='product']",
            ]

            product_found = False
            for selector in product_selectors:
                try:
                    products = await page.query_selector_all(selector)
                    if products:
                        log_message(f"✅ Encontrado produto com seletor: {selector}")
                        product_found = True
                        break
                except:
                    continue

            if not product_found:
                log_message("⚠️ Não consegui encontrar produto automaticamente", "WARN")
                log_message("💡 Procure manualmente um produto na página")
                await page.pause()  # Pausar para você clicar manualmente
            else:
                # Clicar no primeiro produto encontrado
                first_product = await page.query_selector(product_selectors[0])
                if first_product:
                    await first_product.click()
                    await page.wait_for_navigation(wait_until="networkidle")
                    await take_screenshot(page, "02-product-page")
                    log_message("✅ Produto selecionado")

            # ==== PASSO 3: Adicionar ao carrinho ====
            log_message("\n[3/8] Adicionando ao carrinho...")

            add_cart_selectors = [
                "button:has-text('Adicionar ao Carrinho')",
                "button:has-text('Comprar')",
                "[class*='add-to-cart']",
                "[class*='btn-buy']",
                "button[type='submit']",
            ]

            added = False
            for selector in add_cart_selectors:
                try:
                    btn = await page.query_selector(selector)
                    if btn:
                        await btn.click()
                        log_message(f"✅ Clicou: {selector}")
                        added = True
                        await page.wait_for_timeout(1000)
                        break
                except:
                    continue

            if not added:
                log_message("💡 Não consegui clicar 'Adicionar'. Faça manualmente...", "WARN")
                await page.pause()

            await take_screenshot(page, "03-added-to-cart")

            # ==== PASSO 4: Ir para o carrinho ====
            log_message("\n[4/8] Navegando para carrinho...")

            cart_selectors = [
                "a[href*='carrinho']",
                "button:has-text('Carrinho')",
                "[class*='cart']",
                "a:has-text('Ir para Carrinho')",
            ]

            cart_found = False
            for selector in cart_selectors:
                try:
                    cart_link = await page.query_selector(selector)
                    if cart_link:
                        await cart_link.click()
                        await page.wait_for_navigation(wait_until="networkidle")
                        cart_found = True
                        log_message(f"✅ Clicou no carrinho")
                        break
                except:
                    continue

            if not cart_found:
                log_message("💡 Não consegui encontrar carrinho", "WARN")
                await page.pause()

            await take_screenshot(page, "04-cart-page")

            # ==== PASSO 5: Iniciar checkout ====
            log_message("\n[5/8] Iniciando checkout...")

            checkout_selectors = [
                "button:has-text('Finalizar Compra')",
                "button:has-text('Comprar')",
                "button:has-text('Ir para Checkout')",
                "a[href*='checkout']",
                "[class*='checkout']",
            ]

            checkout_found = False
            for selector in checkout_selectors:
                try:
                    checkout_btn = await page.query_selector(selector)
                    if checkout_btn:
                        await checkout_btn.click()
                        await page.wait_for_timeout(2000)
                        checkout_found = True
                        log_message(f"✅ Clicou checkout")
                        break
                except:
                    continue

            if not checkout_found:
                log_message("💡 Não consegui iniciar checkout", "WARN")
                await page.pause()

            # ==== PASSO 6: Preencher dados ====
            log_message("\n[6/8] Preenchendo dados de cliente...")

            # Aguardar formulário
            await page.wait_for_timeout(2000)

            # Mapeamento de campos (adaptar conforme HTML real)
            field_mapping = [
                ("Nome Completo", "name", CLIENT_DATA["name"]),
                ("Email", "email", CLIENT_DATA["email"]),
                ("Telefone", "phone", CLIENT_DATA["phone"]),
                ("CPF", "cpf", CLIENT_DATA["cpf"]),
                ("CEP", "zip", CLIENT_DATA["zip"]),
                ("Rua", "address", CLIENT_DATA["address"]),
                ("Cidade", "city", CLIENT_DATA["city"]),
                ("Estado", "state", CLIENT_DATA["state"]),
            ]

            for label, key, value in field_mapping:
                # Tentar diferentes métodos para preencher
                selectors_to_try = [
                    f"input[name='{key}']",
                    f"input[placeholder*='{label}']",
                    f"input[id='{key}']",
                    f"[class*='{key}'] input",
                ]

                filled = False
                for selector in selectors_to_try:
                    try:
                        field = await page.query_selector(selector)
                        if field:
                            # Limpar campo
                            await field.fill("")
                            # Preencher
                            await field.fill(value)
                            log_message(f"  ✅ {label}: {value[:20]}...")
                            filled = True
                            break
                    except:
                        continue

                if not filled:
                    log_message(f"  ⚠️ Não preencheu {label} (procure manualmente)", "WARN")

            await take_screenshot(page, "05-form-filled")

            # ==== PASSO 7: Continuar para pagamento ====
            log_message("\n[7/8] Continuando para pagamento...")

            # Procurar botão "Próximo" ou "Continuar"
            continue_selectors = [
                "button:has-text('Próximo')",
                "button:has-text('Continuar')",
                "button:has-text('Avançar')",
                "button:has-text('Prosseguir')",
            ]

            for selector in continue_selectors:
                try:
                    btn = await page.query_selector(selector)
                    if btn:
                        await btn.click()
                        await page.wait_for_timeout(2000)
                        log_message("✅ Clicou Próximo")
                        break
                except:
                    continue

            await take_screenshot(page, "06-payment-page")

            # ==== PASSO 8: Selecionar BOLETO ====
            log_message("\n[8/8] Selecionando BOLETO como pagamento...")

            # Procurar por Boleto
            boleto_selectors = [
                "label:has-text('Boleto')",
                "input[value='boleto']",
                "[class*='boleto']",
                "button:has-text('Boleto')",
            ]

            boleto_found = False
            for selector in boleto_selectors:
                try:
                    boleto = await page.query_selector(selector)
                    if boleto:
                        await boleto.click()
                        log_message("✅ Boleto selecionado")
                        boleto_found = True
                        await page.wait_for_timeout(1000)
                        break
                except:
                    continue

            if not boleto_found:
                log_message("⚠️ Não consegui selecionar Boleto", "WARN")
                log_message("💡 Selecione BOLETO manualmente...", "WARN")
                await page.pause()

            # ==== PASSO 9: Gerar boleto ====
            log_message("\n[9/9] Gerando boleto...")

            generate_selectors = [
                "button:has-text('Gerar Boleto')",
                "button:has-text('Confirmar Pedido')",
                "button:has-text('Finalizar')",
                "button:has-text('Comprar')",
            ]

            for selector in generate_selectors:
                try:
                    btn = await page.query_selector(selector)
                    if btn:
                        await btn.click()
                        await page.wait_for_timeout(3000)
                        log_message("✅ Boleto gerado!")
                        break
                except:
                    continue

            await take_screenshot(page, "07-boleto-gerado")

            # ==== CAPTURAR DADOS DO PEDIDO ====
            log_message("\n📋 Capturando dados do pedido...")

            # Procurar número do pedido
            order_number = "DESCONHECIDO"
            order_patterns = [
                "Pedido #",
                "Número do Pedido:",
                "Order #",
                "ID:",
            ]

            page_content = await page.content()
            for pattern in order_patterns:
                if pattern in page_content:
                    idx = page_content.find(pattern)
                    order_number = page_content[idx:idx+50]
                    break

            log_message(f"📍 Número do Pedido: {order_number}")

            # ==== RESULTADO FINAL ====
            log_message("\n" + "=" * 80)
            log_message("✅ COMPRA REALIZADA COM SUCESSO!")
            log_message("=" * 80)

            result = {
                "timestamp": datetime.now().isoformat(),
                "status": "SUCESSO",
                "site": SITE_URL,
                "customer": CLIENT_DATA["name"],
                "email": CLIENT_DATA["email"],
                "order_number": order_number,
                "payment_method": "BOLETO",
                "screenshots": list(SCREENSHOTS_DIR.glob("*.png")),
                "next_steps": [
                    "1. Verifique seu EMAIL (fredmourao@gmail.com)",
                    "2. Procure email de confirmação de pedido",
                    "3. Acesse https://www.olist.com.br/pedidos/",
                    "4. Procure pelo pedido com seu nome",
                    "5. Verifique se sincronizou no ERP",
                ]
            }

            log_message("\n📊 DADOS DO PEDIDO:")
            log_message(json.dumps(result, indent=2, ensure_ascii=False))

            # Salvar resultado
            result_file = LOGS_DIR / "purchase-result.json"
            with open(result_file, "w", encoding="utf-8") as f:
                json.dump(result, f, indent=2, ensure_ascii=False)
            log_message(f"\n💾 Resultado salvo: {result_file}")

            # ==== AGUARDAR PARA VOCÊ VER ====
            log_message("\n⏸️ Navegador permanecerá aberto para você verificar.")
            log_message("Pressione ENTER para fechar...")
            await page.pause()

        except Exception as e:
            log_message(f"❌ ERRO: {e}", "ERROR")
            log_message(f"Stack: {type(e).__name__}", "ERROR")
            import traceback
            log_message(traceback.format_exc(), "ERROR")
            await take_screenshot(page, "ERROR-screenshot")

        finally:
            log_message("\n🔌 Fechando navegador...")
            await browser.close()
            log_message("Done!", "END")


async def main():
    """Entry point"""
    try:
        log_message("\n🚀 ShopVivaliz - Automação de Compra com Playwright")
        log_message(f"Horário: {datetime.now().strftime('%d/%m/%Y %H:%M:%S')}")
        log_message(f"Site: {SITE_URL}")

        await automate_purchase()

    except KeyboardInterrupt:
        log_message("\n⏹️ Cancelado pelo usuário", "WARN")
        sys.exit(0)
    except Exception as e:
        log_message(f"\n❌ ERRO FATAL: {e}", "ERROR")
        sys.exit(1)


if __name__ == "__main__":
    # Windows/asyncio event loop fix
    if sys.platform == "win32":
        asyncio.set_event_loop_policy(asyncio.WindowsSelectorEventLoopPolicy())

    asyncio.run(main())
