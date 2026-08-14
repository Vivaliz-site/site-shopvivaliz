#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Otimizacao COMPLETA e AUTOMATICA via Playwright Chrome.
Faz tudo sozinho: login, recupera produtos, otimiza, aplica.
"""
import sys
import time
import json
from pathlib import Path
from datetime import datetime

if sys.platform == "win32":
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

async def optimize_with_playwright():
    """Otimizacao completa usando Playwright."""
    try:
        from playwright.async_api import async_playwright

        print("\n" + "="*80)
        print("OTIMIZACAO AUTOMATICA - PLAYWRIGHT CHROME")
        print("="*80 + "\n")

        async with async_playwright() as p:
            # Abrir navegador
            print("[BROWSER] Iniciando Chrome...")
            browser = await p.chromium.launch(headless=False)
            page = await browser.new_page()

            try:
                # Acessar Shopee
                print("[BROWSER] Acessando https://seller.shopee.com.br...")
                await page.goto("https://seller.shopee.com.br/login", wait_until="load")

                print("[INFO] Aguarde login manual no navegador...")
                print("[INFO] Voce tem 3 minutos para fazer login")

                # Aguardar que usuario faca login
                start_time = time.time()
                timeout = 180  # 3 minutos

                while time.time() - start_time < timeout:
                    try:
                        current_url = page.url
                        if "login" not in current_url and "seller.shopee.com.br" in current_url:
                            print("[OK] Login detectado!")
                            break
                    except:
                        pass

                    await page.wait_for_timeout(2000)  # Verificar a cada 2s

                time_spent = time.time() - start_time
                if time_spent >= timeout:
                    print("[ERRO] Timeout - login nao foi realizado")
                    return False

                # Navegar para produtos
                print("[BROWSER] Acessando gerenciador de produtos...")
                await page.goto("https://seller.shopee.com.br/portal/product/list")
                await page.wait_for_timeout(3000)

                # Obter lista de produtos
                print("[BROWSER] Procurando produtos...")

                # Selectors potenciais
                selectors = [
                    "[data-item-id]",
                    ".product-item",
                    "[class*='product']",
                    "a[href*='product']",
                ]

                products_found = []
                for selector in selectors:
                    try:
                        products = await page.query_selector_all(selector)
                        if products:
                            products_found = products
                            print(f"[OK] {len(products)} produtos encontrados (selector: {selector})")
                            break
                    except:
                        pass

                if not products_found:
                    print("[AVISO] Nenhum produto detectado")
                    print("[DICA] Certifique-se que ha produtos publicados na loja")
                    return False

                # Processar cada produto
                stats = {
                    "total": len(products_found),
                    "optimized": 0,
                    "processed": 0,
                    "timestamp": datetime.now().isoformat(),
                }

                limit = min(20, len(products_found))

                print(f"\n[PROCESSANDO] {limit} produtos...\n")

                for idx in range(limit):
                    try:
                        # Recarregar lista
                        products = await page.query_selector_all("[data-item-id]")
                        if not products or idx >= len(products):
                            break

                        product = products[idx]
                        stats["processed"] += 1

                        # Extrair info
                        try:
                            title_elem = await product.query_selector("a[title]")
                            if title_elem:
                                title = await title_elem.get_attribute("title")
                            else:
                                title = await product.text_content()
                        except:
                            title = ""

                        if not title:
                            print(f"[{idx+1}/{limit}] Sem titulo - pulando")
                            continue

                        title = title.strip()[:100]

                        # Gerar otimizacao
                        optimized = generate_optimized_title({"item_name": title})

                        if optimized != title:
                            print(f"[{idx+1}/{limit}] Otimizando...")
                            print(f"  Antes : {title[:60]}")
                            print(f"  Depois: {optimized[:60]}")

                            # Clicar para abrir
                            try:
                                await product.click()
                                await page.wait_for_timeout(2000)

                                # Encontrar campo de titulo
                                title_inputs = await page.query_selector_all("input[type='text']")

                                if title_inputs:
                                    # Primeira input é geralmente o titulo
                                    input_field = title_inputs[0]

                                    # Limpar e digitar novo titulo
                                    await input_field.fill("")
                                    await input_field.type(optimized, delay=10)

                                    # Procurar botao Salvar
                                    save_buttons = await page.query_selector_all("button")
                                    for btn in save_buttons:
                                        text = await btn.text_content()
                                        if text and ("Salvar" in text or "Confirmar" in text):
                                            await btn.click()
                                            print("[OK] Salvo!")
                                            stats["optimized"] += 1
                                            await page.wait_for_timeout(1000)
                                            break
                                    else:
                                        print("[INFO] Clique em Salvar manualmente")

                                # Voltar
                                await page.go_back()
                                await page.wait_for_timeout(2000)

                            except Exception as e:
                                print(f"[ERRO] {e}")
                                try:
                                    await page.go_back()
                                except:
                                    pass
                        else:
                            if idx < 5:
                                print(f"[{idx+1}/{limit}] OK (sem mudancas)")

                        print()

                    except Exception as e:
                        print(f"[{idx+1}] Erro: {e}\n")

                # Resumo
                print("\n" + "="*80)
                print("RESUMO")
                print("="*80)
                print(f"Processados: {stats['processed']}")
                print(f"Otimizados: {stats['optimized']}")
                print()

                # Salvar relatorio
                report_dir = ROOT / "logs" / "shopee-optimizer"
                report_dir.mkdir(parents=True, exist_ok=True)

                report_file = report_dir / f"auto_{datetime.now().strftime('%Y%m%d_%H%M%S')}.json"
                with open(report_file, "w", encoding="utf-8") as f:
                    json.dump(stats, f, ensure_ascii=False, indent=2)

                print(f"[RELATORIO] {report_file.relative_to(ROOT)}\n")

                return stats["optimized"] > 0

            finally:
                await browser.close()

    except ImportError:
        print("[ERRO] Playwright nao instalado")
        print("       Instale com: pip install playwright")
        return False
    except Exception as e:
        print(f"[ERRO] {e}")
        return False

def generate_optimized_title(product: dict) -> str:
    """Gera titulo otimizado."""
    original = (product.get("item_name") or "").strip()

    if not original or len(original) > 110:
        return original[:120]

    # Adicionar keywords
    improved = original

    if "qualidade" not in improved.lower() and len(improved) < 100:
        improved = improved + " - Qualidade"

    if "novo" not in improved.lower():
        if len(improved) < 110:
            improved = improved + " Novo"

    return improved[:120].strip()

async def main():
    result = await optimize_with_playwright()
    return 0 if result else 1

if __name__ == "__main__":
    import asyncio

    print("\n" + "="*80)
    print("OTIMIZADOR AUTOMATICO SHOPEE - MODO COMPLETO")
    print("="*80)

    exit_code = asyncio.run(main())
    sys.exit(exit_code)
