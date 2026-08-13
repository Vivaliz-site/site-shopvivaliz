#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Otimizador inteligente de titulos para Shopee.
- Tenta usar API se tokens estiverem disponiveis
- Fallback para browser automation se necessario
"""
import argparse
import sys
import json
import os
from pathlib import Path

# Garantir UTF-8 no Windows
if sys.platform == "win32":
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8')

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

def try_api_optimization():
    """Tenta otimizar via API Shopee com SEO inteligente."""
    try:
        from utils.shopee_client import ShopeeClient

        print("[API] Conectando a Shopee Partner API...")
        client = ShopeeClient()

        print("[API] Recuperando catalogo...")
        products = list(client.iter_all_products(page_size=100))
        print(f"[OK] {len(products)} produtos encontrados")

        optimized = 0
        errors = []

        # Tentar usar geradores de SEO se disponivel
        seo_generator = None
        try:
            from ia.seo.shopee_seo import generate as generate_seo
            seo_generator = generate_seo
            print("[OK] Gerador SEO com IA disponivel")
        except ImportError:
            print("[INFO] Gerador SEO nao disponivel, usando heuristica")

        print("\n[INICIO] Otimizando titulos com SEO...")
        for i, product in enumerate(products, 1):
            try:
                item_id = int(product.get("item_id"))
                current_title = product.get("item_name", "")

                # Usar gerador SEO se disponivel, caso contrario usar otimizacao basica
                if seo_generator:
                    try:
                        seo_data = seo_generator(product)
                        optimized_title = seo_data.get("title", current_title)
                    except:
                        optimized_title = optimize_title(product)
                else:
                    optimized_title = optimize_title(product)

                if optimized_title != current_title:
                    if i <= 10 or i % 50 == 0:
                        print(f"[{i}/{len(products)}] ID: {item_id}")
                        print(f"  Antes : {current_title[:80]}")
                        print(f"  Depois: {optimized_title[:80]}")

                    # Atualizar na API
                    client.update_product(item_id, title=optimized_title)
                    optimized += 1

                    # Verificar leitura
                    verified = client.get_product_details([item_id])
                    if verified and verified[0].get("item_name") == optimized_title:
                        if i <= 10 or i % 50 == 0:
                            print("  [OK] Verificado")
                    else:
                        print("  [AVISO] Falha na verificacao")
                        errors.append(f"Item {item_id} nao persistiu corretamente")
                else:
                    if i <= 3 or i % 100 == 0:
                        print(f"[{i}/{len(products)}] ID: {item_id} - Sem mudancas")

            except Exception as e:
                errors.append(f"Item {item_id}: {str(e)}")
                if i <= 5:
                    print(f"  [ERRO] {e}")

        print(f"\n[RESULTADO] Otimizacao concluida!")
        print(f"   Total de produtos: {len(products)}")
        print(f"   Atualizados: {optimized}")
        print(f"   Erros: {len(errors)}")

        if errors:
            print("\n[ERROS] Encontrados:")
            for error in errors[:10]:
                print(f"   - {error}")

        return True

    except ImportError as e:
        print(f"[ERRO] Modulos nao disponiveis: {e}")
        return False
    except RuntimeError as e:
        print(f"[ERRO] Configuracao invalida: {e}")
        return False
    except Exception as e:
        print(f"[ERRO] Falha na API: {type(e).__name__}: {e}")
        return False

def optimize_title(product: dict) -> str:
    """
    Otimiza o título do produto adicionando atributos relevantes.
    Máximo 120 caracteres.
    """
    current = (product.get("item_name") or "").strip()

    # Extrair atributos relevantes
    attrs = product.get("attribute_list") or []
    key_attrs = {}

    for attr in attrs:
        name = (attr.get("attribute_name") or "").lower().strip()
        values = attr.get("attribute_value_list") or []

        if name in {"marca", "modelo", "material", "tamanho", "cor", "tipo"}:
            if values:
                val = str(values[0].get("value_unit") or values[0].get("original_value_name") or "").strip()
                if val and val.lower() not in current.lower():
                    key_attrs[name] = val

    # Construir título otimizado
    parts = [current]

    # Adicionar atributos por prioridade
    priority = ["marca", "modelo", "tipo", "material", "tamanho", "cor"]
    for key in priority:
        if key in key_attrs:
            val = key_attrs[key]
            # Verificar espaço disponível (120 chars max)
            test = " ".join(parts + [val])
            if len(test) <= 120:
                parts.append(val)
            else:
                break

    optimized = " ".join(parts)

    # Limpar caracteres especiais desnecessários
    import re
    optimized = re.sub(r"  +", " ", optimized)  # Espaços múltiplos
    optimized = re.sub(r"[^\w\s\-./À-ÿ]", "", optimized)  # Remover caracteres especiais

    return optimized[:120].strip()

def try_browser_optimization():
    """Fallback: otimizar via automacao de browser."""
    print("[BROWSER] Iniciando automacao de browser para Shopee...")
    print("   Nota: Este metodo eh mais lento, mas nao requer tokens API")

    try:
        from selenium import webdriver
        from selenium.webdriver.common.by import By
        from selenium.webdriver.support.ui import WebDriverWait
        from selenium.webdriver.support import expected_conditions as EC

        driver = webdriver.Chrome()
        driver.get("https://seller.shopee.com.br")

        print("[BROWSER] Aguardando login manual (60 segundos)...")
        WebDriverWait(driver, 60).until(
            EC.presence_of_element_located((By.CLASS_NAME, "product-item"))
        )

        print("[OK] Login detectado")
        print("[BROWSER] Procurando produtos...")

        # Procurar por produtos
        products = driver.find_elements(By.CLASS_NAME, "product-item")
        print(f"[OK] {len(products)} produtos encontrados")

        optimized = 0
        for i, product in enumerate(products[:50], 1):  # Limitar a 50 para nao demorar muito
            try:
                # Clicar no produto
                product.click()

                # Aguardar editor de titulo
                WebDriverWait(driver, 10).until(
                    EC.presence_of_element_located((By.CSS_SELECTOR, "input[name*='title']"))
                )

                title_field = driver.find_element(By.CSS_SELECTOR, "input[name*='title']")
                current = title_field.get_attribute("value") or ""

                # Otimizar titulo
                optimized_title = optimize_title({"item_name": current})

                if optimized_title != current:
                    print(f"[{i}] Otimizando: {current[:50]}...")
                    title_field.clear()
                    title_field.send_keys(optimized_title)

                    # Salvar
                    save_btn = driver.find_element(By.XPATH, "//button[contains(text(), 'Salvar')]")
                    save_btn.click()

                    optimized += 1

                # Voltar para lista
                driver.back()

            except Exception as e:
                if i <= 3:
                    print(f"   [AVISO] Erro no item {i}: {e}")

        driver.quit()

        print(f"\n[OK] Browser automation concluida!")
        print(f"   Otimizados: {optimized}")
        return True

    except ImportError:
        print("[ERRO] Selenium nao instalado. Instale com: pip install selenium")
        return False
    except Exception as e:
        print(f"[ERRO] Falha no browser: {e}")
        return False

def main():
    parser = argparse.ArgumentParser(description="Otimizar títulos de produtos na Shopee")
    parser.add_argument("--method", choices=["api", "browser", "auto"], default="auto",
                        help="Método: api (Shopee Partner API), browser (automação), ou auto (tenta API, fallback browser)")
    parser.add_argument("--dry-run", action="store_true",
                        help="Simular sem fazer alterações (não aplicável a browser)")

    args = parser.parse_args()

    print("=" * 60)
    print("🛍️  OTIMIZADOR INTELIGENTE DE TÍTULOS - SHOPEE")
    print("=" * 60)
    print()

    if args.method == "api" or args.method == "auto":
        print(f"Tentando método: API Shopee Partner")
        if try_api_optimization():
            return 0
        elif args.method == "api":
            return 1
        # Fallback automático
        print("\n" + "-" * 60)
        print("API indisponível. Tentando browser automation...\n")

    # Browser fallback
    if try_browser_optimization():
        return 0
    else:
        return 1

if __name__ == "__main__":
    sys.exit(main())
