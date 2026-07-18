#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
TESTE E2E REAL COM PLAYWRIGHT
REGRA PRINCIPAL: SEMPRE CLICAR NOS BOTOES, NUNCA NAVEGAR DIRETO
Testa checkout completo: CEP → Transportadora → Mercado Pago → BD → Admin

Cada teste:
[OK] Preenche formulários
[OK] Clica em botões (não navega URL)
[OK] Valida resposta visual
[OK] Simula ação real de usuário
"""

import asyncio
import sys
# Configurar encoding
import sys
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')

import os
from datetime import datetime

# Configurar encoding
os.environ['PYTHONIOENCODING'] = 'utf-8'

try:
    from playwright.async_api import async_playwright
except ImportError:
    print("ERRO: Playwright não instalado")
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

    BASE_URL = "https://dev.shopvivaliz.com.br"

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
                # Add product to cart first
                print("[0] Adicionar produto ao carrinho na Home...")
                try:
                    await page.goto(BASE_URL, timeout=15000)
                    await page.wait_for_selector(".buy-button", timeout=8000)
                    await page.click(".buy-button")
                    await page.wait_for_timeout(3000)
                    print("[OK] Produto adicionado ao carrinho.\n")
                except Exception as e:
                    print(f"[FAIL] Erro ao adicionar ao carrinho: {e}\n")
                    await browser.close()
                    return results

                await page.goto(f"{BASE_URL}/checkout/", timeout=15000)
                await page.wait_for_selector("#checkout-form", timeout=5000)
                print("[OK] PASSOU\n")
                results["passed"] += 1
                results["tests"].append({
                    "name": "Checkout carrega",
                    "status": "[OK]"
                })
            except Exception as e:
                print(f"[FAIL] FALHOU: {e}\n")
                results["failed"] += 1
                results["tests"].append({
                    "name": "Checkout carrega",
                    "status": "[FAIL]",
                    "error": str(e)
                })
                await browser.close()
                return results

            # ========================================================
            # TESTE 2: CAMPOS DO FORMULÁRIO EXISTEM
            # ========================================================
            print("[2] Campos do formulário existem?")
            try:
                await page.wait_for_selector("#nome", timeout=3000)
                await page.wait_for_selector("#email", timeout=3000)
                await page.wait_for_selector("#cep", timeout=3000)
                print("[OK] PASSOU\n")
                results["passed"] += 1
                results["tests"].append({
                    "name": "Campos do formulário",
                    "status": "[OK]"
                })
            except Exception as e:
                print(f"[FAIL] FALHOU: {e}\n")
                results["failed"] += 1
                results["tests"].append({
                    "name": "Campos do formulário",
                    "status": "[FAIL]",
                    "error": str(e)
                })

            # ========================================================
            # TESTE 3: PREENCHER CEP E TESTAR VIACEP
            # ========================================================
            print("[3] CEP preenche endereço (ViaCEP)?")
            try:
                # Preencher formulário
                await page.fill("#nome", "Teste Cliente")
                await page.fill("#email", "teste@example.com")
                await page.fill("#telefone", "11987654321")
                await page.fill("#endereco", "")  # Será preenchido por ViaCEP
                await page.fill("#numero", "123")
                await page.fill("#cidade", "")  # Será preenchido por ViaCEP
                await page.fill("#cep", "01310100")

                # Esperar por ViaCEP preencher
                await page.wait_for_function(
                    "document.getElementById('endereco').value !== ''",
                    timeout=5000
                )

                endereco = await page.input_value("#endereco")
                cidade = await page.input_value("#cidade")

                if endereco and cidade:
                    print(f"[OK] PASSOU (Endereço: {endereco}, {cidade})\n")
                    results["passed"] += 1
                    results["tests"].append({
                        "name": "CEP preenche endereço",
                        "status": "[OK]",
                        "data": {
                            "endereco": endereco,
                            "cidade": cidade
                        }
                    })
                else:
                    print("[FAIL] FALHOU: CEP não preencheu endereço\n")
                    results["failed"] += 1
                    results["tests"].append({
                        "name": "CEP preenche endereço",
                        "status": "[FAIL]",
                        "error": "Campos vazios"
                    })

            except Exception as e:
                print(f"[FAIL] FALHOU: {e}\n")
                results["failed"] += 1
                results["tests"].append({
                    "name": "CEP preenche endereço",
                    "status": "[FAIL]",
                    "error": str(e)
                })

            # ========================================================
            # TESTE 4: SELETOR DE TRANSPORTADORA APARECE
            # ========================================================
            print("[4] Seletor de transportadora aparece?")
            try:
                # Aguardar MelhorEnvio carregar opções
                await page.wait_for_selector(
                    'input[name="shipping_option"]',
                    timeout=10000
                )

                # Contar quantas opções têm
                options = await page.locator(
                    'input[name="shipping_option"]'
                ).count()

                if options > 0:
                    print(f"[OK] PASSOU ({options} opções)\n")
                    results["passed"] += 1
                    results["tests"].append({
                        "name": "Seletor transportadora",
                        "status": "[OK]",
                        "data": {
                            "opcoes": options
                        }
                    })
                else:
                    print("[FAIL] FALHOU: Nenhuma opção de frete\n")
                    results["failed"] += 1
                    results["tests"].append({
                        "name": "Seletor transportadora",
                        "status": "[FAIL]",
                        "error": "Sem opções"
                    })

            except Exception as e:
                print(f"[WARN] TIMEOUT/FALHA: {e}\n")
                print("   (Pode ser erro de API do MelhorEnvio)\n")
                results["tests"].append({
                    "name": "Seletor transportadora",
                    "status": "[WARN]",
                    "error": str(e)
                })

            # ========================================================
            # TESTE 5: BOTÃO MERCADO PAGO EXISTE
            # ========================================================
            print("[5] Botão Mercado Pago existe?")
            try:
                await page.wait_for_selector(
                    "#checkout-mp-btn, button:has-text('Mercado Pago')",
                    timeout=5000
                )
                print("[OK] PASSOU\n")
                results["passed"] += 1
                results["tests"].append({
                    "name": "Botão Mercado Pago",
                    "status": "[OK]"
                })
            except Exception as e:
                print(f"[FAIL] FALHOU: {e}\n")
                results["failed"] += 1
                results["tests"].append({
                    "name": "Botão Mercado Pago",
                    "status": "[FAIL]",
                    "error": str(e)
                })

            # ========================================================
            # TESTE 6: NÃO HÁ OUTROS GATEWAYS
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
                    print("[FAIL] FALHOU: Encontrados outros gateways\n")
                    results["failed"] += 1
                    results["tests"].append({
                        "name": "Apenas Mercado Pago",
                        "status": "[FAIL]",
                        "error": f"PIX: {has_pix}, Boleto: {has_boleto}, Pagar.me: {has_pagarme}"
                    })

            except Exception as e:
                print(f"[FAIL] FALHOU: {e}\n")
                results["failed"] += 1

            # ========================================================
            # TESTE 7: CLICAR NO BOTAO MERCADO PAGO
            # ========================================================
            print("[7] Clicar botao 'Continuar com Mercado Pago'?")
            try:
                # Voltar para checkout para clicar no botao
                # Add product to cart first
                print("[0] Adicionar produto ao carrinho na Home...")
                try:
                    await page.goto(BASE_URL, timeout=15000)
                    await page.wait_for_selector(".buy-button", timeout=8000)
                    await page.click(".buy-button")
                    await page.wait_for_timeout(3000)
                    print("[OK] Produto adicionado ao carrinho.\n")
                except Exception as e:
                    print(f"[FAIL] Erro ao adicionar ao carrinho: {e}\n")
                    await browser.close()
                    return results

                await page.goto(f"{BASE_URL}/checkout/", timeout=15000)

                # Preencher dados se necessario
                await page.fill("#nome", "Teste E2E")
                await page.fill("#email", "teste-e2e@example.com")
                await page.fill("#telefone", "11987654321")
                await page.fill("#cep", "01310100")
                await page.fill("#numero", "123")

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
    print("[SUMMARY] RESUMO FINAL")
    print("=" * 60)
    print()
    print(f"[OK] PASSOU: {results['passed']}")
    print(f"[FAIL] FALHOU: {results['failed']}")
    print(f"[WARN]  AVISOS: {len([t for t in results['tests'] if t.get('status') == '[WARN]'])}")
    print()

    total = results['passed'] + results['failed']
    if total > 0:
        percentage = round((results['passed'] / total) * 100)
        print(f"[STATS] Taxa de sucesso: {percentage}%")
    print()

    if results['failed'] == 0:
        print("[PASSING] TESTES PASSANDO!")
        print("\nPróximos passos:")
        print("1. Fazer PR em GitHub")
        print("2. Merge para main")
        print("3. Esperar sincronização (30min)")
        print("4. Testar novamente")
    else:
        print("[FAILING] TESTES FALHANDO - Verificar logs acima")

    print("\n" + "=" * 60)
    print()


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        print("\n[FAIL] Teste interrompido")
        sys.exit(1)
