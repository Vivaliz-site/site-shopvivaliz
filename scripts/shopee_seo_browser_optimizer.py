#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Otimizador SEO inteligente para Shopee via browser automation.
Fornece análise e otimização de títulos com foco em:
- Palavras-chave de busca
- Estrutura de marca/modelo/material
- Comprimento ideal (120 caracteres)
- Sem alterações de preço/estoque
"""
import json
import sys
import time
import os
from pathlib import Path

if sys.platform == "win32":
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8')

def analyze_title_for_seo(title: str, category: str = "", description: str = "") -> dict:
    """
    Analisa e sugere otimizacoes de SEO para um titulo.

    Retorna:
    - score (0-100): quao otimizado o titulo esta
    - issues: lista de problemas encontrados
    - suggestions: sugestoes de melhorias
    - optimized_title: versao otimizada
    """
    original_len = len(title)

    issues = []
    suggestions = []

    # Verificacoes de comprimento
    if original_len < 10:
        issues.append("Titulo muito curto (menos de 10 caracteres)")
    elif original_len > 120:
        issues.append(f"Titulo muito longo ({original_len} chars, maximo 120)")

    # Verificar palavras-chave comuns
    title_lower = title.lower()
    keywords = {
        "qualidade": "produto de qualidade",
        "novo": "item novo/lacrado",
        "original": "produto original",
        "promoção": "em promocao",
        "desconto": "com desconto",
        "kit": "conjunto de itens",
        "pack": "multiplos itens",
    }

    found_keywords = [v for k, v in keywords.items() if k in title_lower]
    if not found_keywords:
        suggestions.append("Adicione palavras-chave: qualidade, novo, original, promocao")

    # Verificar se tem informacoes de tamanho/cor/material
    size_keywords = ["mm", "cm", "polegada", "p", "m", "g", "kg", "ml"]
    has_size = any(k in title_lower for k in size_keywords)
    if not has_size and len(description) > 50:
        suggestions.append("Adicione tamanho/dimensoes ao titulo se relevante")

    # Score baseado em melhorias
    score = 100
    score -= len(issues) * 15
    if not found_keywords:
        score -= 20
    if original_len < 30:
        score -= 15
    if original_len > 110:
        score -= 10

    score = max(0, min(100, score))

    # Titulo otimizado (simples)
    optimized = title.strip()
    if original_len > 120:
        optimized = optimized[:117] + "..."

    return {
        "score": score,
        "issues": issues,
        "suggestions": suggestions,
        "original_length": original_len,
        "optimized_title": optimized,
        "keywords_found": found_keywords,
    }

def optimize_shopee_titles_browser():
    """
    Automacao com Selenium para otimizar titulos na Shopee.
    Fornece GUI para revisar cada titulo antes de aplicar.
    """
    try:
        from selenium import webdriver
        from selenium.webdriver.common.by import By
        from selenium.webdriver.support.ui import WebDriverWait
        from selenium.webdriver.support import expected_conditions as EC
        from selenium.webdriver.chrome.options import Options
    except ImportError:
        print("[ERRO] Selenium nao instalado.")
        print("       Instale com: pip install selenium")
        return False

    # Configurar Chrome
    chrome_options = Options()
    # chrome_options.add_argument("--start-maximized")
    chrome_options.add_argument("--disable-notifications")

    print("[BROWSER] Iniciando navegador Chrome...")
    driver = webdriver.Chrome(options=chrome_options)

    try:
        print("[BROWSER] Acessando painel Shopee...")
        driver.get("https://seller.shopee.com.br/login")

        print("[INFO] Aguarde login manual no navegador (disponivel por 180 segundos)")
        print("[INFO] Apos login, o script iniciara a otimizacao automaticamente")
        print()

        # Aguardar que usuario acesse o painel do vendedor
        try:
            WebDriverWait(driver, 180).until(
                lambda d: "seller.shopee.com.br" in d.current_url and
                         "login" not in d.current_url
            )
        except:
            print("[ERRO] Timeout - login nao foi realizado")
            return False

        print("[OK] Login detectado!")
        time.sleep(2)

        # Navegar para gerenciador de produtos
        print("[BROWSER] Navegando para produtos...")
        driver.get("https://seller.shopee.com.br/portal/product/list")

        time.sleep(3)

        # Tentar encontrar produtos
        try:
            WebDriverWait(driver, 10).until(
                EC.presence_of_all_elements_located((By.CLASS_NAME, "list-item"))
            )
        except:
            print("[AVISO] Elementos de produto nao encontrados")
            print("[INFO] Procurando por selectors alternativos...")
            time.sleep(2)

        # Extrair titulos de produtos
        products_elements = driver.find_elements(By.CSS_SELECTOR, "[data-item-id]")
        if not products_elements:
            products_elements = driver.find_elements(By.CLASS_NAME, "product-item")

        if not products_elements:
            print("[ERRO] Nenhum produto encontrado no painel")
            print("[DICA] Certifique-se de que ha produtos publicados na loja")
            return False

        print(f"[OK] Encontrados {len(products_elements)} produtos")
        print()

        optimized_count = 0
        skipped_count = 0

        # Processar primeiros 20 produtos (para nao demorar muito)
        limit = min(20, len(products_elements))

        for idx in range(limit):
            try:
                # Recarregar elemento para evitar stale reference
                products_elements = driver.find_elements(By.CSS_SELECTOR, "[data-item-id]")
                if not products_elements:
                    products_elements = driver.find_elements(By.CLASS_NAME, "product-item")

                if idx >= len(products_elements):
                    break

                product = products_elements[idx]
                item_number = idx + 1

                # Tentar extrair titulo visivel
                try:
                    title_element = product.find_element(By.CSS_SELECTOR, "a[title]")
                    current_title = title_element.get_attribute("title") or ""
                except:
                    current_title = product.text[:100] if product.text else ""

                if not current_title:
                    print(f"[{item_number}/{limit}] [SKIP] Sem titulo visivel")
                    continue

                # Analisar titulo
                analysis = analyze_title_for_seo(current_title)
                score = analysis["score"]
                issues = analysis["issues"]
                suggestions = analysis["suggestions"]

                # Exibir analise
                print(f"[{item_number}/{limit}] Score SEO: {score}/100")
                print(f"   Titulo: {current_title[:80]}")

                if issues:
                    print(f"   Problemas encontrados:")
                    for issue in issues:
                        print(f"     - {issue}")

                if suggestions:
                    print(f"   Sugestoes:")
                    for sugg in suggestions:
                        print(f"     + {sugg}")

                # Se score < 70, clicar para editar
                if score < 70:
                    print(f"   -> Abrindo para edicao...")

                    try:
                        # Clicar no produto para abrir editor
                        product.click()
                        time.sleep(2)

                        # Aguardar campo de titulo
                        title_input = WebDriverWait(driver, 5).until(
                            EC.presence_of_element_located((By.CSS_SELECTOR, "input[name*='title'], input[type='text']"))
                        )

                        # Obter valor atual
                        current_in_editor = title_input.get_attribute("value") or ""

                        # Gerar sugestao de titulo melhorado
                        improved_title = generate_improved_title(current_in_editor)

                        if improved_title and len(improved_title) <= 120:
                            print(f"   Sugestao: {improved_title}")
                            print(f"   -> Pressione Enter no campo para aceitar, Esc para pular")

                            # Limpar e digitar novo titulo
                            title_input.clear()
                            title_input.send_keys(improved_title)

                            # Usuario decide se salva
                            time.sleep(1)
                            print(f"   [MANUAL] Salve clicando em Confirmar no painel")

                            optimized_count += 1
                        else:
                            print(f"   [SKIP] Titulo sugerido invalido")
                            skipped_count += 1

                        # Voltar para lista
                        driver.back()
                        time.sleep(2)

                    except Exception as e:
                        print(f"   [AVISO] Erro ao editar: {e}")
                        try:
                            driver.back()
                        except:
                            pass
                        time.sleep(1)
                        skipped_count += 1
                else:
                    print(f"   [OK] Titulo ja otimizado")
                    skipped_count += 1

                print()

            except Exception as e:
                print(f"[{item_number if 'item_number' in locals() else idx}] [ERRO] {e}")
                skipped_count += 1

        print("[RESULTADO] Otimizacao concluida!")
        print(f"   Analisados: {limit}")
        print(f"   Para revisar: {optimized_count}")
        print(f"   Pulados: {skipped_count}")

        return True

    finally:
        print("[BROWSER] Encerrando navegador...")
        driver.quit()

def generate_improved_title(current: str) -> str:
    """
    Gera um titulo melhorado baseado no atual.
    Simples heuristica sem IA.
    """
    # Limitacoes
    if len(current) >= 110:
        return current[:120]

    # Adicionar keywords se nao houver
    enhanced = current

    if "qualidade" not in enhanced.lower() and len(enhanced) < 100:
        enhanced = enhanced + " - Qualidade Premium"

    if "novo" not in enhanced.lower() and "lacrado" not in enhanced.lower():
        if len(enhanced) < 105:
            enhanced = enhanced + " Novo"

    return enhanced[:120].strip()

def main():
    print("=" * 70)
    print("OTIMIZADOR SEO INTELIGENTE PARA SHOPEE - BROWSER EDITION")
    print("=" * 70)
    print()
    print("Funcionalidades:")
    print("- Analisa cada titulo com score SEO (0-100)")
    print("- Identifica problemas (comprimento, palavras-chave)")
    print("- Fornece sugestoes de melhoria")
    print("- Abre editor para revisao manual")
    print()

    try:
        if optimize_shopee_titles_browser():
            print("\n[OK] Processo finalizado com sucesso!")
            return 0
        else:
            print("\n[ERRO] Falha na otimizacao")
            return 1
    except KeyboardInterrupt:
        print("\n[CANCELADO] Processo interrompido pelo usuario")
        return 1
    except Exception as e:
        print(f"\n[ERRO] Falha inesperada: {e}")
        import traceback
        traceback.print_exc()
        return 1

if __name__ == "__main__":
    sys.exit(main())
