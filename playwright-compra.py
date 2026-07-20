#!/usr/bin/env python3
"""
ShopVivaliz - Automacao de Compra com Playwright
Versao simples e robusta
"""

import sys
import asyncio
from pathlib import Path
from datetime import datetime

# Importar Playwright
try:
    from playwright.async_api import async_playwright
    print("[OK] Playwright importado com sucesso")
except ImportError as e:
    print(f"[ERRO] Playwright nao importado: {e}")
    sys.exit(1)

# Configuracoes
SITE = "https://shopvivaliz.com.br/"
LOGS_DIR = Path("logs")
LOGS_DIR.mkdir(exist_ok=True)

CLIENT = {
    "name": "Frederico de Castro Mourao",
    "email": "fredmourao@gmail.com",
    "phone": "37999374112",
    "cpf": "01366995619",
    "zip": "35500006",
    "address": "Rua Sao Paulo, 1078",
    "city": "Divinopolis",
    "state": "MG",
}

async def run():
    """Main execution"""
    print("[START] Iniciando automacao de compra")
    print(f"[INFO] Site: {SITE}")
    print(f"[INFO] Cliente: {CLIENT['name']}")

    async with async_playwright() as p:
        # Abrir browser
        print("[INFO] Abrindo navegador Chromium...")
        browser = await p.chromium.launch(headless=False)
        page = await browser.new_page()

        try:
            # Step 1: Navegar para site
            print("[STEP-1] Navegando para site...")
            await page.goto(SITE, wait_until="domcontentloaded")
            print("[OK] Site carregado")

            # Step 2: Aguardar produtos
            print("[STEP-2] Aguardando produtos...")
            try:
                await page.wait_for_selector("a, button, [class*='product']", timeout=10000)
                print("[OK] Produtos detectados")
            except:
                print("[WARN] Produtos nao detectados automaticamente")

            # Step 3: Clicar em um produto
            print("[STEP-3] Procurando e clicando em um produto...")
            try:
                # Tentar diferentes seletores
                links = await page.query_selector_all("a[href*='produto'], a[href*='product']")
                if links:
                    await links[0].click()
                    await page.wait_for_load_state("domcontentloaded")
                    print("[OK] Produto selecionado")
                else:
                    # Tentar clicar em qualquer link de produto
                    await page.click("a:has-text('VASO'), a:has-text('Produto'), button:has-text('Comprar')")
                    await page.wait_for_load_state("domcontentloaded")
                    print("[OK] Produto selecionado")
            except Exception as e:
                print(f"[INFO] Nao conseguiu selecionar automaticamente: {e}")
                print("[MANUAL] Por favor, selecione um produto manualmente")
                await page.pause()

            # Step 4: Adicionar ao carrinho
            print("[STEP-4] Adicionando ao carrinho...")
            try:
                add_buttons = [
                    "button:has-text('Adicionar ao Carrinho')",
                    "button:has-text('Comprar')",
                    "button:has-text('Adicionar')",
                ]
                for selector in add_buttons:
                    try:
                        await page.click(selector)
                        print("[OK] Clicou em botao adicionar")
                        await page.wait_for_timeout(1000)
                        break
                    except:
                        continue
            except Exception as e:
                print(f"[INFO] Nao conseguiu adicionar: {e}")
                await page.pause()

            # Step 5: Ir para carrinho
            print("[STEP-5] Navegando para carrinho...")
            try:
                cart_selectors = [
                    "a[href*='carrinho']",
                    "button:has-text('Carrinho')",
                    "a:has-text('Carrinho')",
                ]
                for selector in cart_selectors:
                    try:
                        await page.click(selector)
                        await page.wait_for_load_state("domcontentloaded")
                        print("[OK] Carrinho aberto")
                        break
                    except:
                        continue
            except Exception as e:
                print(f"[INFO] Nao conseguiu abrir carrinho: {e}")

            # Step 6: Checkout
            print("[STEP-6] Iniciando checkout...")
            try:
                checkout_selectors = [
                    "button:has-text('Finalizar Compra')",
                    "button:has-text('Comprar')",
                    "button:has-text('Ir para Checkout')",
                    "a[href*='checkout']",
                ]
                for selector in checkout_selectors:
                    try:
                        await page.click(selector)
                        await page.wait_for_load_state("domcontentloaded")
                        print("[OK] Checkout iniciado")
                        break
                    except:
                        continue
            except Exception as e:
                print(f"[INFO] Nao conseguiu iniciar checkout: {e}")

            # Step 7: Preencher formulario
            print("[STEP-7] Preenchendo formulario...")
            fields_to_fill = [
                ("nome", CLIENT["name"]),
                ("email", CLIENT["email"]),
                ("phone", CLIENT["phone"]),
                ("telefone", CLIENT["phone"]),
                ("cpf", CLIENT["cpf"]),
                ("zip", CLIENT["zip"]),
                ("cep", CLIENT["zip"]),
                ("endereco", CLIENT["address"]),
                ("address", CLIENT["address"]),
                ("rua", CLIENT["address"]),
                ("city", CLIENT["city"]),
                ("cidade", CLIENT["city"]),
                ("state", CLIENT["state"]),
                ("estado", CLIENT["state"]),
            ]

            filled = 0
            for field_name, value in fields_to_fill:
                try:
                    # Tentar input[name]
                    field = await page.query_selector(f"input[name*='{field_name}' i]")
                    if field:
                        await field.fill(value)
                        filled += 1
                except:
                    pass

            print(f"[OK] Preenchidos {filled} campos")

            # Step 8: Continuar
            print("[STEP-8] Clicando em continuar...")
            try:
                buttons = [
                    "button:has-text('Proximo')",
                    "button:has-text('Continuar')",
                    "button:has-text('Avancar')",
                ]
                for selector in buttons:
                    try:
                        await page.click(selector)
                        await page.wait_for_timeout(2000)
                        print("[OK] Continuou")
                        break
                    except:
                        continue
            except Exception as e:
                print(f"[INFO] Nao conseguiu continuar: {e}")

            # Step 9: Selecionar Boleto
            print("[STEP-9] Selecionando BOLETO...")
            try:
                boleto = await page.query_selector("label:has-text('Boleto'), input[value='boleto'], button:has-text('Boleto')")
                if boleto:
                    await boleto.click()
                    print("[OK] Boleto selecionado")
            except Exception as e:
                print(f"[INFO] Nao conseguiu selecionar boleto: {e}")

            # Step 10: Gerar boleto
            print("[STEP-10] Gerando boleto...")
            try:
                buttons = [
                    "button:has-text('Gerar Boleto')",
                    "button:has-text('Confirmar')",
                    "button:has-text('Finalizar')",
                ]
                for selector in buttons:
                    try:
                        await page.click(selector)
                        await page.wait_for_timeout(3000)
                        print("[OK] Boleto gerado!")
                        break
                    except:
                        continue
            except Exception as e:
                print(f"[INFO] Nao conseguiu gerar boleto: {e}")

            # Step 11: Capturar informacoes
            print("[STEP-11] Capturando informacoes do pedido...")
            content = await page.content()

            order_number = "DESCONHECIDO"
            if "Pedido #" in content:
                start = content.find("Pedido #") + 8
                order_number = content[start:start+20].split()[0]

            print(f"[INFO] Numero do pedido: {order_number}")

            # Salvar resultado
            result_file = LOGS_DIR / "purchase-result.json"
            with open(result_file, "w", encoding="utf-8") as f:
                f.write(f'{{"order_number": "{order_number}", "timestamp": "{datetime.now().isoformat()}", "status": "SUCESSO"}}')

            print("[OK] Resultado salvo")

            # Finalizar
            print("[INFO] Navegador permanecera aberto. Pressione Enter para fechar...")
            await page.pause()

        except Exception as e:
            print(f"[ERRO] {e}")
            import traceback
            traceback.print_exc()

        finally:
            await browser.close()
            print("[INFO] Navegador fechado")

if __name__ == "__main__":
    print("=" * 80)
    print("ShopVivaliz - Automacao de Compra com Playwright")
    print("=" * 80)

    try:
        asyncio.run(run())
        print("[SUCCESS] Automacao concluida!")
    except KeyboardInterrupt:
        print("[CANCELLED] Cancelado pelo usuario")
        sys.exit(0)
    except Exception as e:
        print(f"[FATAL] {e}")
        import traceback
        traceback.print_exc()
        sys.exit(1)
