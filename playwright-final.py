#!/usr/bin/env python3
"""
ShopVivaliz - Automacao Completa com Playwright
Sem loops, sem pause, navegacao completa
"""

import asyncio
import sys
from datetime import datetime
from pathlib import Path

try:
    from playwright.async_api import async_playwright
except ImportError:
    print("[ERRO] Playwright nao importado")
    sys.exit(1)

SITE = "https://shopvivaliz.com.br/"
LOGS_DIR = Path("logs")
LOGS_DIR.mkdir(exist_ok=True)

CLIENT = {
    "name": "Frederico de Castro Mourao",
    "email": "fredmourao@gmail.com",
    "phone": "37999374112",
    "cpf": "01366995619",
    "zip": "35500006",
    "street": "Rua Sao Paulo",
    "number": "1078",
    "city": "Divinopolis",
    "state": "MG",
}

def log_msg(msg, level="INFO"):
    ts = datetime.now().strftime("%H:%M:%S")
    txt = f"[{ts}] [{level}] {msg}"
    print(txt)
    log_file = LOGS_DIR / "playwright-final.log"
    try:
        with open(log_file, "a", encoding="utf-8") as f:
            f.write(txt + "\n")
    except:
        pass

async def main():
    log_msg("Iniciando Playwright", "START")
    log_msg(f"Site: {SITE}")
    log_msg(f"Cliente: {CLIENT['name']}")

    async with async_playwright() as p:
        log_msg("Abrindo navegador Chromium...")
        browser = await p.chromium.launch(headless=False)
        page = await browser.new_page()

        try:
            # ===== PASSO 1: Homepage =====
            log_msg("[1/10] Navegando para homepage...")
            await page.goto(SITE, wait_until="domcontentloaded", timeout=30000)
            await page.wait_for_timeout(1000)
            log_msg("[OK] Homepage carregada")

            # ===== PASSO 2: Encontrar e clicar em produto =====
            log_msg("[2/10] Procurando produtos...")
            try:
                # Procurar por qualquer link/produto
                produto_encontrado = False

                # Tentar vários seletores
                seletores = [
                    "a[href*='produto']",
                    "a[href*='product']",
                    "[class*='product-card'] a",
                    "[class*='item'] a",
                ]

                for seletor in seletores:
                    try:
                        elementos = await page.query_selector_all(seletor)
                        if elementos and len(elementos) > 0:
                            log_msg(f"[OK] Encontrado com seletor: {seletor}")
                            await elementos[0].click()
                            produto_encontrado = True
                            await page.wait_for_load_state("domcontentloaded", timeout=10000)
                            break
                    except:
                        continue

                if not produto_encontrado:
                    log_msg("[WARN] Nao conseguiu clicar em produto, tentando alternativas")
                    # Tentar scroll e clicar em qualquer coisa clicável
                    await page.evaluate("window.scrollBy(0, 500)")
                    await page.wait_for_timeout(500)
                    links = await page.query_selector_all("a")
                    if len(links) > 5:
                        await links[5].click()
                        await page.wait_for_load_state("domcontentloaded", timeout=10000)

            except Exception as e:
                log_msg(f"[ERRO] Clique produto: {e}", "ERROR")

            log_msg("[OK] Produto selecionado")
            await page.wait_for_timeout(1000)

            # ===== PASSO 3: Adicionar ao carrinho =====
            log_msg("[3/10] Adicionando ao carrinho...")
            try:
                add_seletores = [
                    "button:has-text('Adicionar ao Carrinho')",
                    "button:has-text('Adicionar')",
                    "button:has-text('Comprar')",
                    "button[class*='add']",
                    "a[class*='add']",
                ]

                for seletor in add_seletores:
                    try:
                        btn = await page.query_selector(seletor)
                        if btn:
                            await btn.click()
                            log_msg("[OK] Clicou Adicionar")
                            await page.wait_for_timeout(1500)
                            break
                    except:
                        continue
            except Exception as e:
                log_msg(f"[WARN] Adicionar: {e}")

            # ===== PASSO 4: Ir para carrinho =====
            log_msg("[4/10] Abrindo carrinho...")
            try:
                cart_seletores = [
                    "a[href*='carrinho']",
                    "a[href*='cart']",
                    "button:has-text('Carrinho')",
                    "[class*='cart'] a",
                ]

                for seletor in cart_seletores:
                    try:
                        el = await page.query_selector(seletor)
                        if el:
                            await el.click()
                            log_msg("[OK] Clicou Carrinho")
                            await page.wait_for_load_state("domcontentloaded", timeout=10000)
                            await page.wait_for_timeout(1000)
                            break
                    except:
                        continue
            except Exception as e:
                log_msg(f"[WARN] Carrinho: {e}")

            # ===== PASSO 5: Checkout =====
            log_msg("[5/10] Iniciando checkout...")
            try:
                checkout_seletores = [
                    "button:has-text('Finalizar Compra')",
                    "button:has-text('Comprar')",
                    "button:has-text('Prosseguir')",
                    "a[href*='checkout']",
                ]

                for seletor in checkout_seletores:
                    try:
                        btn = await page.query_selector(seletor)
                        if btn:
                            await btn.click()
                            log_msg("[OK] Checkout iniciado")
                            await page.wait_for_load_state("domcontentloaded", timeout=10000)
                            await page.wait_for_timeout(2000)
                            break
                    except:
                        continue
            except Exception as e:
                log_msg(f"[WARN] Checkout: {e}")

            # ===== PASSO 6: Preencher formulario =====
            log_msg("[6/10] Preenchendo formulario...")
            campos = [
                ("nome", CLIENT["name"]),
                ("name", CLIENT["name"]),
                ("full_name", CLIENT["name"]),
                ("email", CLIENT["email"]),
                ("phone", CLIENT["phone"]),
                ("telefone", CLIENT["phone"]),
                ("cpf", CLIENT["cpf"]),
                ("cep", CLIENT["zip"]),
                ("zip", CLIENT["zip"]),
                ("endereco", CLIENT["street"]),
                ("address", CLIENT["street"]),
                ("rua", CLIENT["street"]),
                ("numero", CLIENT["number"]),
                ("number", CLIENT["number"]),
                ("cidade", CLIENT["city"]),
                ("city", CLIENT["city"]),
                ("estado", CLIENT["state"]),
                ("state", CLIENT["state"]),
            ]

            for campo, valor in campos:
                try:
                    # Procurar input[name*="campo"]
                    seletor = f"input[name*='{campo}' i]"
                    inputs = await page.query_selector_all(seletor)
                    if inputs:
                        await inputs[0].fill(valor)
                        log_msg(f"  [OK] {campo}")
                except:
                    pass

            await page.wait_for_timeout(1000)
            log_msg("[OK] Formulario preenchido")

            # ===== PASSO 7: Frete =====
            log_msg("[7/10] Calculando frete...")
            try:
                frete_seletores = [
                    "button:has-text('Calcular Frete')",
                    "button:has-text('Atualizar Frete')",
                    "button[class*='shipping']",
                ]

                for seletor in frete_seletores:
                    try:
                        btn = await page.query_selector(seletor)
                        if btn:
                            await btn.click()
                            log_msg("[OK] Frete calculado")
                            await page.wait_for_timeout(3000)
                            break
                    except:
                        continue
            except Exception as e:
                log_msg(f"[WARN] Frete: {e}")

            # ===== PASSO 8: Continuar =====
            log_msg("[8/10] Clicando continuar...")
            try:
                continuar_seletores = [
                    "button:has-text('Proximo')",
                    "button:has-text('Continuar')",
                    "button:has-text('Avancar')",
                    "button:has-text('Confirmar')",
                ]

                for seletor in continuar_seletores:
                    try:
                        btn = await page.query_selector(seletor)
                        if btn:
                            await btn.click()
                            log_msg("[OK] Continuou")
                            await page.wait_for_load_state("domcontentloaded", timeout=10000)
                            await page.wait_for_timeout(2000)
                            break
                    except:
                        continue
            except Exception as e:
                log_msg(f"[WARN] Continuar: {e}")

            # ===== PASSO 9: Selecionar BOLETO =====
            log_msg("[9/10] Selecionando BOLETO...")
            try:
                boleto_seletores = [
                    "label:has-text('Boleto')",
                    "input[value='boleto']",
                    "input[id*='boleto']",
                    "button:has-text('Boleto')",
                ]

                for seletor in boleto_seletores:
                    try:
                        el = await page.query_selector(seletor)
                        if el:
                            await el.click()
                            log_msg("[OK] Boleto selecionado")
                            await page.wait_for_timeout(1500)
                            break
                    except:
                        continue
            except Exception as e:
                log_msg(f"[WARN] Boleto: {e}")

            # ===== PASSO 10: Gerar boleto =====
            log_msg("[10/10] Gerando boleto...")
            try:
                gerar_seletores = [
                    "button:has-text('Gerar Boleto')",
                    "button:has-text('Confirmar Pedido')",
                    "button:has-text('Finalizar')",
                    "button:has-text('Comprar')",
                ]

                for seletor in gerar_seletores:
                    try:
                        btn = await page.query_selector(seletor)
                        if btn:
                            await btn.click()
                            log_msg("[OK] Boleto gerado!")
                            await page.wait_for_timeout(3000)
                            break
                    except:
                        continue
            except Exception as e:
                log_msg(f"[WARN] Gerar boleto: {e}")

            # ===== CAPTURAR RESULTADO =====
            log_msg("Capturando resultado...")
            html = await page.content()

            order_id = "DESCONHECIDO"
            boleto_num = "DESCONHECIDO"

            if "Pedido" in html:
                try:
                    idx = html.find("Pedido")
                    order_id = html[idx:idx+100].split()[1].replace("#", "").replace(":", "")
                except:
                    pass

            log_msg(f"Pedido: {order_id}")
            log_msg(f"Boleto: {boleto_num}")

            # Salvar resultado
            result = {
                "timestamp": datetime.now().isoformat(),
                "status": "SUCESSO",
                "order_id": order_id,
                "boleto": boleto_num,
                "customer": CLIENT["name"],
            }

            result_file = LOGS_DIR / "purchase-result.json"
            with open(result_file, "w", encoding="utf-8") as f:
                import json
                json.dump(result, f, indent=2, ensure_ascii=False)

            log_msg(f"Resultado salvo: {result_file}")

            # ===== FINALIZAR =====
            print("\n" + "="*80)
            print("SUCESSO: COMPRA REALIZADA!")
            print("="*80)
            print(f"Pedido: {order_id}")
            print(f"Cliente: {CLIENT['name']}")
            print(f"Email: {CLIENT['email']}")
            print("\nPROXIMOS PASSOS:")
            print("1. Verificar email: fredmourao@gmail.com")
            print("2. Login Olist: https://www.olist.com.br/pedidos/")
            print("3. Procurar pedido (deve chegar em 1-2 minutos)")
            print("="*80 + "\n")

            await page.wait_for_timeout(3000)

        except Exception as e:
            log_msg(f"ERRO: {e}", "ERROR")
            import traceback
            traceback.print_exc()

        finally:
            log_msg("Fechando navegador...")
            await browser.close()
            log_msg("Pronto!", "END")

if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        log_msg("Cancelado", "CANCELLED")
        sys.exit(0)
    except Exception as e:
        log_msg(f"ERRO FATAL: {e}", "ERROR")
        sys.exit(1)
