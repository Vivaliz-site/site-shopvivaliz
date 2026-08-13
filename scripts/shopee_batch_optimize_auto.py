#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Otimizacao automatica item a item para Shopee.
Tenta API primeiro, fallback para browser automation.
"""
import sys
import time
import os
from pathlib import Path

if sys.platform == "win32":
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
    sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8')

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))

# ============================================================================
# MODO 1: OTIMIZACAO VIA API (PREFERIDO)
# ============================================================================

def optimize_via_api():
    """Otimiza todos os produtos via Shopee Partner API."""
    try:
        from utils.shopee_client import ShopeeClient
        import json
        from datetime import datetime

        print("\n" + "="*80)
        print("OTIMIZACAO VIA API SHOPEE PARTNER V2")
        print("="*80 + "\n")

        # Conectar
        print("[API] Inicializando cliente Shopee...")
        client = ShopeeClient()
        print("[OK] Conectado a Shopee API\n")

        # Recuperar catálogo
        print("[API] Carregando catálogo...")
        all_products = []
        for product in client.iter_all_products(page_size=100):
            all_products.append(product)
            if len(all_products) % 100 == 0:
                print(f"  [+] {len(all_products)} produtos carregados...")

        print(f"[OK] Total: {len(all_products)} produtos\n")

        if not all_products:
            print("[AVISO] Nenhum produto encontrado")
            return False

        # Processar cada produto
        stats = {
            "total": len(all_products),
            "optimized": 0,
            "skipped": 0,
            "errors": 0,
            "changes": []
        }

        print("[PROCESSANDO] Otimizando cada item...\n")

        for idx, product in enumerate(all_products, 1):
            try:
                item_id = int(product.get("item_id"))
                current_title = product.get("item_name", "").strip()
                current_desc = product.get("description", "").strip()

                # Gerar título otimizado
                optimized_title = generate_optimized_title(product)
                optimized_desc = generate_optimized_description(product, optimized_title)

                # Verificar mudanças
                title_changed = optimized_title != current_title
                desc_changed = optimized_desc != current_desc

                if title_changed or desc_changed:
                    # Exibir mudança
                    print(f"[{idx}/{len(all_products)}] ID: {item_id}")
                    print(f"  Titulo: {current_title[:70]}")
                    print(f"  ->     {optimized_title[:70]}")

                    # Aplicar mudanças
                    try:
                        response = client.update_product(
                            item_id,
                            title=optimized_title if title_changed else None,
                            description=optimized_desc if desc_changed else None,
                        )

                        # Verificar
                        verified = client.get_product_details([item_id])
                        if verified:
                            verify_title = verified[0].get("item_name") == optimized_title or not title_changed
                            verify_desc = verified[0].get("description") == optimized_desc or not desc_changed

                            if verify_title and verify_desc:
                                print(f"  [OK] Atualizado e verificado")
                                stats["optimized"] += 1
                                stats["changes"].append({
                                    "item_id": item_id,
                                    "title_before": current_title,
                                    "title_after": optimized_title,
                                    "desc_before": current_desc[:50],
                                    "desc_after": optimized_desc[:50],
                                })
                            else:
                                print(f"  [ERRO] Verificacao falhou")
                                stats["errors"] += 1
                        else:
                            print(f"  [ERRO] Produto nao encontrado apos atualizacao")
                            stats["errors"] += 1

                    except Exception as e:
                        print(f"  [ERRO] Falha na atualizacao: {e}")
                        stats["errors"] += 1

                else:
                    if idx <= 5 or idx % 50 == 0:
                        print(f"[{idx}/{len(all_products)}] ID: {item_id} - Sem mudancas")
                    stats["skipped"] += 1

                print()

            except Exception as e:
                print(f"[{idx}] [ERRO] {e}\n")
                stats["errors"] += 1

            # Pequeno delay para nao sobrecarregar API
            if idx % 10 == 0:
                time.sleep(1)

        # Resumo
        print("\n" + "="*80)
        print("RESUMO DA OTIMIZACAO")
        print("="*80)
        print(f"Total de produtos: {stats['total']}")
        print(f"Otimizados: {stats['optimized']}")
        print(f"Pulados (sem mudancas): {stats['skipped']}")
        print(f"Erros: {stats['errors']}")
        print()

        # Salvar relatorio
        report_dir = ROOT / "logs" / "shopee-optimizer"
        report_dir.mkdir(parents=True, exist_ok=True)
        report_file = report_dir / f"batch_optimize_{datetime.now().strftime('%Y%m%d_%H%M%S')}.json"

        with open(report_file, "w", encoding="utf-8") as f:
            json.dump(stats, f, ensure_ascii=False, indent=2)

        print(f"[OK] Relatorio salvo em: {report_file}\n")

        return True

    except ImportError as e:
        print(f"[ERRO] Modulos indisponiveis: {e}\n")
        return False
    except RuntimeError as e:
        print(f"[ERRO] Credenciais nao configuradas: {e}\n")
        print("       Configure: SHOPEE_PARTNER_ID, SHOPEE_PARTNER_KEY, SHOPEE_SHOP_ID\n")
        return False
    except Exception as e:
        print(f"[ERRO] Falha na otimizacao: {type(e).__name__}: {e}\n")
        return False

# ============================================================================
# MODO 2: OTIMIZACAO VIA BROWSER AUTOMATION
# ============================================================================

def optimize_via_browser():
    """Otimiza via automacao de browser com Selenium."""
    try:
        from selenium import webdriver
        from selenium.webdriver.common.by import By
        from selenium.webdriver.support.ui import WebDriverWait
        from selenium.webdriver.support import expected_conditions as EC
        from selenium.webdriver.chrome.options import Options

        print("\n" + "="*80)
        print("OTIMIZACAO VIA BROWSER AUTOMATION (SELENIUM)")
        print("="*80 + "\n")

        # Chrome setup
        chrome_options = Options()
        chrome_options.add_argument("--disable-notifications")

        print("[BROWSER] Iniciando Chrome...")
        driver = webdriver.Chrome(options=chrome_options)

        try:
            print("[BROWSER] Acessando painel Shopee...")
            driver.get("https://seller.shopee.com.br/login")

            print("[INFO] Aguarde login manual (180 segundos)...")
            WebDriverWait(driver, 180).until(
                lambda d: "seller.shopee.com.br" in d.current_url and
                         "login" not in d.current_url
            )

            print("[OK] Login detectado!")
            time.sleep(2)

            # Navegar para produtos
            print("[BROWSER] Acessando gerenciador de produtos...")
            driver.get("https://seller.shopee.com.br/portal/product/list")
            time.sleep(3)

            # Encontrar produtos
            products = driver.find_elements(By.CSS_SELECTOR, "[data-item-id]")
            if not products:
                products = driver.find_elements(By.CLASS_NAME, "product-item")

            if not products:
                print("[AVISO] Nenhum produto encontrado")
                return False

            print(f"[OK] {len(products)} produtos encontrados\n")

            stats = {
                "total": len(products),
                "optimized": 0,
                "errors": 0,
            }

            # Processar cada produto (limitar a 50 para nao demorar muito)
            limit = min(50, len(products))

            for idx in range(limit):
                try:
                    products = driver.find_elements(By.CSS_SELECTOR, "[data-item-id]")
                    if not products:
                        break

                    product = products[idx]

                    # Extrair titulo
                    try:
                        title_element = product.find_element(By.CSS_SELECTOR, "a[title]")
                        current_title = title_element.get_attribute("title") or ""
                    except:
                        current_title = product.text[:100] if product.text else ""

                    if not current_title:
                        print(f"[{idx+1}/{limit}] Sem titulo - pulando")
                        continue

                    # Gerar titulo otimizado
                    optimized_title = generate_optimized_title({"item_name": current_title})

                    if optimized_title != current_title:
                        print(f"[{idx+1}/{limit}] Otimizando...")
                        print(f"  Antes: {current_title[:60]}")
                        print(f"  Depois: {optimized_title[:60]}")

                        # Clicar para editar
                        product.click()
                        time.sleep(2)

                        # Encontrar campo de titulo
                        try:
                            title_input = WebDriverWait(driver, 5).until(
                                EC.presence_of_element_located((By.CSS_SELECTOR, "input[name*='title'], input[type='text']"))
                            )

                            # Atualizar
                            title_input.clear()
                            title_input.send_keys(optimized_title)

                            # Procurar botao de salvar
                            try:
                                save_buttons = driver.find_elements(By.XPATH, "//button[contains(text(), 'Salvar') or contains(text(), 'Confirmar')]")
                                if save_buttons:
                                    save_buttons[0].click()
                                    print(f"  [OK] Salvo")
                                    stats["optimized"] += 1
                                else:
                                    print(f"  [INFO] Pressione Salvar manualmente")
                            except:
                                print(f"  [INFO] Pressione Salvar manualmente")

                            time.sleep(1)

                        except Exception as e:
                            print(f"  [ERRO] {e}")
                            stats["errors"] += 1

                        # Voltar
                        try:
                            driver.back()
                            time.sleep(2)
                        except:
                            pass

                    else:
                        if idx < 5 or idx % 10 == 0:
                            print(f"[{idx+1}/{limit}] OK (sem mudancas)")

                    print()

                except Exception as e:
                    print(f"[{idx+1}] [ERRO] {e}\n")
                    stats["errors"] += 1
                    try:
                        driver.back()
                    except:
                        pass

            print("\n" + "="*80)
            print("RESUMO DA OTIMIZACAO (BROWSER)")
            print("="*80)
            print(f"Processados: {limit}")
            print(f"Otimizados: {stats['optimized']}")
            print(f"Erros: {stats['errors']}")
            print()

            return True

        finally:
            driver.quit()

    except ImportError:
        print("[ERRO] Selenium nao instalado. Instale com: pip install selenium\n")
        return False
    except Exception as e:
        print(f"[ERRO] Falha na browser automation: {e}\n")
        return False

# ============================================================================
# FUNCOES DE OTIMIZACAO
# ============================================================================

def generate_optimized_title(product: dict) -> str:
    """Gera titulo otimizado para um produto."""
    original = (product.get("item_name") or "").strip()

    if not original:
        return original

    # Extrair atributos
    attrs = product.get("attribute_list") or []
    key_attrs = {}

    for attr in attrs:
        name = (attr.get("attribute_name") or "").lower().strip()
        values = attr.get("attribute_value_list") or []

        if name in {"marca", "modelo", "material", "tamanho", "cor", "tipo"}:
            if values:
                val = str(values[0].get("value_unit") or values[0].get("original_value_name") or "").strip()
                if val and val.lower() not in original.lower():
                    key_attrs[name] = val

    # Construir titulo melhorado
    parts = [original]

    # Adicionar atributos por prioridade
    for key in ["marca", "modelo", "tipo", "material", "tamanho", "cor"]:
        if key in key_attrs:
            test = " ".join(parts + [key_attrs[key]])
            if len(test) <= 120:
                parts.append(key_attrs[key])

    # Adicionar keywords de SEO
    title_lower = " ".join(parts).lower()
    seo_keywords = ["qualidade", "novo", "original", "premium"]

    for kw in seo_keywords:
        if kw not in title_lower and len(" ".join(parts)) < 110:
            if kw == "qualidade":
                parts.append("Qualidade")
                break

    optimized = " ".join(parts)

    # Limpeza
    import re
    optimized = re.sub(r"\s+", " ", optimized)
    optimized = re.sub(r"[^\w\s\-./À-ÿ]", "", optimized, flags=re.UNICODE)

    return optimized[:120].strip()

def generate_optimized_description(product: dict, title: str) -> str:
    """Gera descricao otimizada."""
    original = (product.get("description") or "").strip()

    # Se ja tem descricao boa, manter
    if len(original) > 100:
        return original

    # Construir descricao
    lines = [title]

    if original:
        lines.append("")
        lines.append(original)

    # Adicionar specs
    attrs = product.get("attribute_list") or []
    specs = []

    for attr in attrs:
        name = (attr.get("attribute_name") or "").strip()
        values = attr.get("attribute_value_list") or []

        if values:
            vals = [str(v.get("value_unit") or v.get("original_value_name") or "").strip() for v in values]
            vals = [v for v in vals if v]
            if vals:
                specs.append(f"- {name}: {', '.join(vals)}")

    if specs:
        lines += ["", "Especificacoes:", *specs[:10]]

    lines += ["", "Confira compatibilidade antes de comprar."]

    result = "\n".join(lines)
    return result[:3000]

# ============================================================================
# MAIN
# ============================================================================

def main():
    print("\n" + "="*80)
    print("OTIMIZADOR AUTOMATICO ITEM A ITEM - SHOPEE")
    print("="*80)

    # Tentar API primeiro
    if optimize_via_api():
        return 0

    # Fallback para browser
    print("\n[INFO] Tentando browser automation...\n")
    if optimize_via_browser():
        return 0

    print("[ERRO] Nenhum metodo disponivel")
    return 1

if __name__ == "__main__":
    sys.exit(main())
