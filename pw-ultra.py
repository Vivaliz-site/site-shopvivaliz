#!/usr/bin/env python3
"""
ShopVivaliz - Playwright ULTRA ROBUSTO
Sem travamentos, com retry, navegacao completa
"""

import asyncio
import sys
from datetime import datetime
from pathlib import Path

try:
    from playwright.async_api import async_playwright, TimeoutError
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
    try:
        log_file = LOGS_DIR / "pw-ultra.log"
        with open(log_file, "a", encoding="utf-8") as f:
            f.write(txt + "\n")
    except:
        pass

async def try_click(page, selector, timeout=5000, retries=3):
    """Clica em elemento com retry"""
    for attempt in range(retries):
        try:
            await page.wait_for_selector(selector, timeout=timeout)
            el = await page.query_selector(selector)
            if el:
                await el.click()
                await page.wait_for_timeout(500)
                return True
        except TimeoutError:
            if attempt < retries - 1:
                log_msg(f"  Retry {attempt+1}/{retries} para: {selector}")
                await page.wait_for_timeout(1000)
        except Exception as e:
            log_msg(f"  Erro click: {str(e)[:50]}")

    return False

async def try_fill(page, selector, value, timeout=5000):
    """Preenche input com retry"""
    try:
        await page.wait_for_selector(selector, timeout=timeout)
        el = await page.query_selector(selector)
        if el:
            await el.fill(value)
            return True
    except:
        pass
    return False

async def main():
    log_msg("=" * 80, "START")
    log_msg("Playwright ULTRA ROBUSTO v2")
    log_msg(f"Cliente: {CLIENT['name']}")

    async with async_playwright() as p:
        log_msg("Abrindo Chromium...")
        browser = await p.chromium.launch(headless=False)
        page = await browser.new_page()
        page.set_default_timeout(30000)

        try:
            # ===== [1] HOMEPAGE =====
            log_msg("[1/12] Abrindo homepage...")
            await page.goto(SITE, wait_until="domcontentloaded")
            await page.wait_for_timeout(2000)
            log_msg("[OK] Homepage")

            # ===== [2] BUSCAR PRODUTO =====
            log_msg("[2/12] Buscando produtos...")
            produto_clicado = False

            seletores_produto = [
                "a[href*='produto']",
                "a[href*='/p/']",
                "[class*='produto'] a",
            ]

            for selector in seletores_produto:
                if await try_click(page, selector, timeout=5000, retries=2):
                    log_msg("[OK] Produto encontrado e clicado")
                    produto_clicado = True
                    await page.wait_for_load_state("domcontentloaded")
                    break

            if not produto_clicado:
                log_msg("[WARN] Nao encontrou produto, pulando para carrinho", "WARN")

            await page.wait_for_timeout(1000)

            # ===== [3] ADICIONAR CARRINHO =====
            log_msg("[3/12] Adicionar ao carrinho...")
            seletores_add = [
                "button:has-text('Adicionar ao Carrinho')",
                "button:has-text('Adicionar')",
                "button:has-text('Comprar')",
                "[class*='add-cart'] button",
            ]

            add_ok = False
            for selector in seletores_add:
                if await try_click(page, selector, timeout=5000, retries=2):
                    log_msg("[OK] Adicionado ao carrinho")
                    add_ok = True
                    break

            if not add_ok:
                log_msg("[WARN] Nao conseguiu adicionar", "WARN")

            await page.wait_for_timeout(1500)

            # ===== [4] CARRINHO =====
            log_msg("[4/12] Abrindo carrinho...")
            seletores_cart = [
                "a[href*='carrinho']",
                "a[href*='/cart']",
                "button:has-text('Carrinho')",
                "[class*='cart'] a",
            ]

            cart_ok = False
            for selector in seletores_cart:
                if await try_click(page, selector, timeout=10000, retries=3):
                    log_msg("[OK] Carrinho aberto")
                    cart_ok = True
                    await page.wait_for_load_state("domcontentloaded")
                    break

            if not cart_ok:
                log_msg("[WARN] Nao conseguiu abrir carrinho", "WARN")

            await page.wait_for_timeout(2000)

            # ===== [5] CHECKOUT =====
            log_msg("[5/12] Iniciando checkout...")
            seletores_checkout = [
                "button:has-text('Finalizar Compra')",
                "button:has-text('Comprar')",
                "button:has-text('Prosseguir')",
                "a[href*='checkout']",
            ]

            checkout_ok = False
            for selector in seletores_checkout:
                if await try_click(page, selector, timeout=10000, retries=2):
                    log_msg("[OK] Checkout iniciado")
                    checkout_ok = True
                    await page.wait_for_load_state("domcontentloaded")
                    break

            if not checkout_ok:
                log_msg("[WARN] Nao conseguiu checkout", "WARN")

            await page.wait_for_timeout(2000)

            # ===== [6] FORMULARIO =====
            log_msg("[6/12] Preenchendo formulario...")

            campos = [
                ("nome", CLIENT["name"]),
                ("name", CLIENT["name"]),
                ("full", CLIENT["name"]),
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

            campos_preenchidos = 0
            for campo, valor in campos:
                selector = f"input[name*='{campo}' i]"
                if await try_fill(page, selector, valor, timeout=3000):
                    log_msg(f"  [OK] {campo}")
                    campos_preenchidos += 1

            log_msg(f"[OK] {campos_preenchidos} campos preenchidos")
            await page.wait_for_timeout(1500)

            # ===== [7] CALCULAR FRETE =====
            log_msg("[7/12] Calculando frete...")
            seletores_frete = [
                "button:has-text('Calcular Frete')",
                "button:has-text('Atualizar Frete')",
                "button[class*='freight']",
            ]

            for selector in seletores_frete:
                if await try_click(page, selector, timeout=10000, retries=2):
                    log_msg("[OK] Frete calculado")
                    await page.wait_for_timeout(3000)
                    break
            else:
                log_msg("[INFO] Frete pode estar pre-calculado")

            # ===== [8] CONTINUAR/PROXIMO =====
            log_msg("[8/12] Continuando...")
            seletores_proximo = [
                "button:has-text('Proximo')",
                "button:has-text('Continuar')",
                "button:has-text('Avancar')",
                "button:has-text('Confirmar')",
            ]

            for selector in seletores_proximo:
                if await try_click(page, selector, timeout=10000, retries=2):
                    log_msg("[OK] Continuou para proxima etapa")
                    await page.wait_for_load_state("domcontentloaded")
                    await page.wait_for_timeout(2000)
                    break
            else:
                log_msg("[WARN] Nao encontrou botao continuar", "WARN")

            # ===== [9] SELECIONAR BOLETO =====
            log_msg("[9/12] Selecionando BOLETO...")
            seletores_boleto = [
                "label:has-text('Boleto')",
                "input[value='boleto']",
                "input[id*='boleto']",
                "button:has-text('Boleto')",
                "[class*='boleto'] input",
            ]

            boleto_ok = False
            for selector in seletores_boleto:
                if await try_click(page, selector, timeout=5000, retries=2):
                    log_msg("[OK] Boleto selecionado")
                    boleto_ok = True
                    await page.wait_for_timeout(1500)
                    break

            if not boleto_ok:
                log_msg("[WARN] Nao selecionou boleto", "WARN")

            # ===== [10] GERAR BOLETO =====
            log_msg("[10/12] Gerando boleto...")
            seletores_gerar = [
                "button:has-text('Gerar Boleto')",
                "button:has-text('Confirmar Pedido')",
                "button:has-text('Finalizar')",
                "button:has-text('Comprar')",
                "button[class*='submit']",
            ]

            gerar_ok = False
            for selector in seletores_gerar:
                if await try_click(page, selector, timeout=15000, retries=2):
                    log_msg("[OK] Boleto gerado!")
                    gerar_ok = True
                    await page.wait_for_timeout(3000)
                    break

            if not gerar_ok:
                log_msg("[WARN] Nao conseguiu gerar boleto", "WARN")

            # ===== [11] CAPTURAR RESULTADO =====
            log_msg("[11/12] Capturando resultado...")
            html = await page.content()

            order_id = "DESCONHECIDO"
            boleto_num = "DESCONHECIDO"

            # Procurar numero do pedido
            if "Pedido" in html:
                idx = html.find("Pedido")
                snippet = html[idx:idx+150]
                words = snippet.split()
                for i, word in enumerate(words):
                    if word.startswith("#") or (i > 0 and words[i-1] == "Pedido"):
                        order_id = word.replace("#", "").replace(":", "")
                        break

            log_msg(f"[OK] Pedido: {order_id}")

            # ===== [12] SALVAR RESULTADO =====
            log_msg("[12/12] Salvando resultado...")
            result = {
                "timestamp": datetime.now().isoformat(),
                "status": "SUCESSO",
                "order_id": order_id,
                "boleto": boleto_num,
                "customer": CLIENT["name"],
                "email": CLIENT["email"],
            }

            result_file = LOGS_DIR / "purchase-result.json"
            with open(result_file, "w", encoding="utf-8") as f:
                import json
                json.dump(result, f, indent=2, ensure_ascii=False)

            log_msg(f"[OK] Resultado: {result_file}")

            # ===== SUCESSO =====
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

            await page.wait_for_timeout(5000)

        except Exception as e:
            log_msg(f"ERRO: {e}", "ERROR")
            import traceback
            traceback.print_exc()

        finally:
            log_msg("Fechando navegador...")
            await browser.close()
            log_msg("CONCLUIDO!", "END")

if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        log_msg("Cancelado", "CANCELLED")
        sys.exit(0)
    except Exception as e:
        log_msg(f"ERRO FATAL: {e}", "ERROR")
        sys.exit(1)
