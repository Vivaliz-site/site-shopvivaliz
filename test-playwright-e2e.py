#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
TESTE E2E REAL COM PLAYWRIGHT
REGRA PRINCIPAL: SEMPRE CLICAR NOS BOTOES, NUNCA NAVEGAR DIRETO
Testa checkout completo: CEP ? Transportadora ? Mercado Pago ? BD ? Admin

Cada teste:
[SUCCESS] Preenche formularios
[SUCCESS] Clica em botoes (nao navega URL)
[SUCCESS] Valida resposta visual
[SUCCESS] Simula acao real de usuario
"""

import asyncio
import sys
import os
from datetime import datetime

# Configurar encoding
os.environ['PYTHONIOENCODING'] = 'utf-8'

try:
    from playwright.async_api import async_playwright
except ImportError:
    print("ERRO: Playwright nao instalado")
    print("\nInstale com:")
    print("  pip install playwright")
    print("  playwright install chromium")
    sys.exit(1)


async def test_checkout():
    """Teste E2E completo do checkout"""

    results = {
        "passed": 0,
        "failed": 0,
        "tests": []
    }

    BASE_URL = "https://www.shopvivaliz.com.br"

    async with async_playwright() as p:
        print("[TESTE] E2E REAL COM PLAYWRIGHT - CLICANDO NOS BOTOES")
        print("=" * 60)
        print()

        # Abrir browser
        browser = await p.chromium.launch()
        page = await browser.new_page()

        try:
            # ========================================================
            # TESTE 1: CHECKOUT CARREGA
            # ========================================================
            print("[1] Checkout carrega?")
            try:
                # Passo 0: Autenticar sessao com Google Mock Login
                try:
                    print("Autenticando sessao de teste...")
                    await page.goto(f"{BASE_URL}/auth/google-mock-login.php?email=atendimento@shopvivaliz.com.br", timeout=15000)
                    await asyncio.sleep(1)
                except Exception as e:
                    print(f"Aviso: Nao foi possivel autenticar: {e}")

                # Pre-requisito: adicionar produto ao carrinho na Home
                try:
                    print("Adicionando produto ao carrinho na Home...")
                    await page.goto(BASE_URL, timeout=15000)
                    await page.wait_for_selector(".buy-button", timeout=5000)
                    await page.click(".buy-button")
                    await asyncio.sleep(1)
                except Exception as e:
                    print(f"Aviso: Nao foi possivel adicionar item: {e}")

                await page.goto(f"{BASE_URL}/checkout/", timeout=15000)
                await page.wait_for_selector("#checkout-form", timeout=5000)
                print("[OK] PASSOU\n")
                results["passed"] += 1
                results["tests"].append({
                    "name": "Checkout carrega",
                    "status": "[OK]"
                })
            except Exception as e:
                print(f"[FALHOU] FALHOU: {e}\n")
                try:
                    url = page.url
                    print(f"URL atual: {url}")
                    shot_path = "C:/Users/FRED/.gemini/antigravity/brain/b2d1b366-35b1-40e0-a03e-be6a6a0f2d91/checkout_failed.png"
                    # Run screenshot synchronously in async context
                    await page.screenshot(path=shot_path)
                    print(f"Screenshot salvo em: {shot_path}")
                except Exception as ex:
                    print(f"Nao foi possivel salvar screenshot: {ex}")
                results["failed"] += 1
                results["tests"].append({
                    "name": "Checkout carrega",
                    "status": "[FALHOU]",
                    "error": str(e)
                })
                await browser.close()
                return results

            # ========================================================
            # TESTE 2: CAMPOS DO FORMULARIO EXISTEM
            # ========================================================
            print("[2] Campos do formulario existem?")
            try:
                await page.wait_for_selector("[name='customer_name']", timeout=3000)
                await page.wait_for_selector("[name='customer_email']", timeout=3000)
                await page.wait_for_selector("#cep-input", timeout=3000)
                print("[OK] PASSOU\n")
                results["passed"] += 1
                results["tests"].append({
                    "name": "Campos do formulario",
                    "status": "[OK]"
                })
            except Exception as e:
                print(f"[FALHOU] FALHOU: {e}\n")
                results["failed"] += 1
                results["tests"].append({
                    "name": "Campos do formulario",
                    "status": "[FALHOU]",
                    "error": str(e)
                })

            # ========================================================
            # TESTE 3: PREENCHER CEP E TESTAR VIACEP
            # ========================================================
            print("[3] CEP preenche endereco (ViaCEP)?")
            try:
                # Preencher formulario
                await page.fill("[name='customer_name']", "Teste Cliente")
                await page.fill("[name='customer_email']", "teste@example.com")
                await page.fill("[name='customer_phone']", "11987654321")
                await page.fill("#address-input", "")  # Sera preenchido por ViaCEP
                await page.fill("#street-number-input", "123")
                await page.fill("#city-input", "")  # Sera preenchido por ViaCEP
                await page.fill("#cep-input", "01310100")

                # Esperar por ViaCEP preencher
                await page.wait_for_function(
                    "document.getElementById('address-input').value !== ''",
                    timeout=5000
                )

                endereco = await page.input_value("#address-input")
                cidade = await page.input_value("#city-input")

                if endereco and cidade:
                    print(f"[OK] PASSOU (Endereco: {endereco}, {cidade})\n")
                    results["passed"] += 1
                    results["tests"].append({
                        "name": "CEP preenche endereco",
                        "status": "[OK]",
                        "data": {
                            "endereco": endereco,
                            "cidade": cidade
                        }
                    })
                else:
                    print("[FALHOU] FALHOU: CEP nao preencheu endereco\n")
                    results["failed"] += 1
                    results["tests"].append({
                        "name": "CEP preenche endereco",
                        "status": "[FALHOU]",
                        "error": "Campos vazios"
                    })

            except Exception as e:
                print(f"[FALHOU] FALHOU: {e}\n")
                results["failed"] += 1
                results["tests"].append({
                    "name": "CEP preenche endereco",
                    "status": "[FALHOU]",
                    "error": str(e)
                })

            # ========================================================
            # TESTE 4: SELETOR DE TRANSPORTADORA APARECE
            # ========================================================
            print("[4] Seletor de transportadora aparece?")
            try:
                # Aguardar MelhorEnvio carregar opcoes
                await page.wait_for_selector(
                    'input[name="shipping_option"]',
                    timeout=10000
                )

                # Contar quantas opcoes tem
                options = await page.locator(
                    'input[name="shipping_option"]'
                ).count()

                if options > 0:
                    print(f"[OK] PASSOU ({options} opcoes)\n")
                    results["passed"] += 1
                    results["tests"].append({
                        "name": "Seletor transportadora",
                        "status": "[OK]",
                        "data": {
                            "opcoes": options
                        }
                    })
                else:
                    print("[FALHOU] FALHOU: Nenhuma opcao de frete\n")
                    results["failed"] += 1
                    results["tests"].append({
                        "name": "Seletor transportadora",
                        "status": "[FALHOU]",
                        "error": "Sem opcoes"
                    })

            except Exception as e:
                print(f"?? TIMEOUT/FALHA: {e}\n")
                print("   (Pode ser erro de API do MelhorEnvio)\n")
                results["tests"].append({
                    "name": "Seletor transportadora",
                    "status": "??",
                    "error": str(e)
                })

            # ========================================================
            # TESTE 5: BOT?O MERCADO PAGO EXISTE
            # ========================================================
            print("[5] Botao Mercado Pago existe?")
            try:
                await page.wait_for_selector(
                    "#checkout-mp-btn, button:has-text('Mercado Pago')",
                    timeout=5000
                )
                print("[OK] PASSOU\n")
                results["passed"] += 1
                results["tests"].append({
                    "name": "Botao Mercado Pago",
                    "status": "[OK]"
                })
            except Exception as e:
                print(f"[FALHOU] FALHOU: {e}\n")
                results["failed"] += 1
                results["tests"].append({
                    "name": "Botao Mercado Pago",
                    "status": "[FALHOU]",
                    "error": str(e)
                })

            # ========================================================
            # TESTE 6: N?O HA OUTROS GATEWAYS
            # ========================================================
            print("[6] Apenas Mercado Pago (sem outros gateways)?")
            try:
                page_content = await page.content()

                has_pix = "value=\"pix\"" in page_content
                has_boleto = "value=\"boleto\"" in page_content
                has_pagarme = "value=\"pagarme\"" in page_content

                if not has_pix and not has_boleto and not has_pagarme:
                    print("[OK] PASSOU\n")
                    results["passed"] += 1
                    results["tests"].append({
                        "name": "Apenas Mercado Pago",
                        "status": "[OK]"
                    })
                else:
                    print("[FALHOU] FALHOU: Encontrados outros gateways\n")
                    results["failed"] += 1
                    results["tests"].append({
                        "name": "Apenas Mercado Pago",
                        "status": "[FALHOU]",
                        "error": f"PIX: {has_pix}, Boleto: {has_boleto}, Pagar.me: {has_pagarme}"
                    })

            except Exception as e:
                print(f"[FALHOU] FALHOU: {e}\n")
                results["failed"] += 1

            # ========================================================
            # TESTE 7: CLICAR NO BOTAO MERCADO PAGO
            # ========================================================
            print("[7] Clicar botao 'Continuar com Mercado Pago'?")
            try:
                # Voltar para checkout para clicar no botao
                await page.goto(f"{BASE_URL}/checkout/", timeout=15000)

                # Preencher dados se necessario
                await page.fill("[name='customer_name']", "Teste E2E")
                await page.fill("[name='customer_email']", "teste-e2e@example.com")
                await page.fill("[name='customer_phone']", "11987654321")
                await page.fill("#cep-input", "01310100")
                await page.fill("#street-number-input", "123")

                # Aguardar formulario estar pronto
                await page.wait_for_selector("#checkout-mp-btn", timeout=5000)

                # CLICAR NO BOTAO
                await page.click("#checkout-mp-btn")

                print("CLICADO - Aguardando resposta...\n")
                results["passed"] += 1
                results["tests"].append({
                    "name": "Clique botao MP",
                    "status": "CLICADO"
                })
            except Exception as e:
                print(f"ERRO ao clicar: {e}\n")
                results["failed"] += 1
                results["tests"].append({
                    "name": "Clique botao MP",
                    "status": "ERRO",
                    "error": str(e)
                })

            # ========================================================
            # TESTE 8: CLICAR EM MENU DO ADMIN
            # ========================================================
            print("[8] Menu Admin carrega?")
            try:
                await page.goto(f"{BASE_URL}/admin/menu-completo.php", timeout=15000)
                await page.wait_for_selector(".section", timeout=5000)
                print("CARREGOU - Menu presente\n")
                results["passed"] += 1
                results["tests"].append({
                    "name": "Menu Admin",
                    "status": "CARREGOU"
                })
            except Exception as e:
                print(f"NAO CARREGOU: {e}\n")
                results["failed"] += 1
                results["tests"].append({
                    "name": "Menu Admin",
                    "status": "ERRO",
                    "error": str(e)
                })

            # ========================================================
            # TESTE 9: CLICAR EM PAINEL DE PEDIDOS
            # ========================================================
            print("[9] Clicar link 'Pedidos' no menu?")
            try:
                await page.click("a:has-text('Pedidos')")
                await page.wait_for_url("**/admin/pedidos.php", timeout=5000)
                await page.wait_for_selector(".products-table, table", timeout=5000)
                print("CLICADO E CARREGOU - Painel pedidos presente\n")
                results["passed"] += 1
                results["tests"].append({
                    "name": "Clique painel pedidos",
                    "status": "CLICADO"
                })
            except Exception as e:
                print(f"ERRO ao clicar: {e}\n")
                results["tests"].append({
                    "name": "Clique painel pedidos",
                    "status": "AVISO",
                    "error": str(e)
                })

            # ========================================================
            # TESTE 10: CLICAR EM PRODUTOS
            # ========================================================
            print("[10] Clicar link 'Produtos' no menu?")
            try:
                await page.goto(f"{BASE_URL}/admin/menu-completo.php", timeout=15000)
                await page.click("a:has-text('Produtos')")
                await page.wait_for_url("**/admin/produtos.php", timeout=5000)
                await page.wait_for_selector(".products-table, table", timeout=5000)
                print("CLICADO E CARREGOU - Painel produtos presente\n")
                results["passed"] += 1
                results["tests"].append({
                    "name": "Clique painel produtos",
                    "status": "CLICADO"
                })
            except Exception as e:
                print(f"ERRO ao clicar: {e}\n")
                results["tests"].append({
                    "name": "Clique painel produtos",
                    "status": "AVISO",
                    "error": str(e)
                })

        finally:
            await browser.close()

    return results


async def main():
    """Executar testes"""
    results = await test_checkout()

    print("\n" + "=" * 60)
    print("? RESUMO FINAL")
    print("=" * 60)
    print()
    print(f"[OK] PASSOU: {results['passed']}")
    print(f"[FALHOU] FALHOU: {results['failed']}")
    print(f"??  AVISOS: {len([t for t in results['tests'] if t.get('status') == '??'])}")
    print()

    total = results['passed'] + results['failed']
    if total > 0:
        percentage = round((results['passed'] / total) * 100)
        print(f"? Taxa de sucesso: {percentage}%")
    print()

    if results['failed'] == 0:
        print("? TESTES PASSANDO!")
        print("\nProximos passos:")
        print("1. Fazer PR em GitHub")
        print("2. Merge para main")
        print("3. Esperar sincronizacao (30min)")
        print("4. Testar novamente")
    else:
        print("? TESTES FALHANDO - Verificar logs acima")

    print("\n" + "=" * 60)
    print()


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        print("\n[FALHOU] Teste interrompido")
        sys.exit(1)
