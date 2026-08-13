#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Otimizacao AUTOMATICA completa via Selenium Chrome.
Faz tudo sozinho: abre navegador, login, otimiza item a item.
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

def run_automation():
    """Executa automacao completa."""
    try:
        from selenium import webdriver
        from selenium.webdriver.common.by import By
        from selenium.webdriver.support.ui import WebDriverWait
        from selenium.webdriver.support import expected_conditions as EC
        from selenium.webdriver.chrome.options import Options

        print("\n" + "="*80)
        print("OTIMIZADOR AUTOMATICO - SELENIUM CHROME")
        print("="*80 + "\n")

        # Configurar Chrome
        options = Options()
        options.add_argument("--disable-notifications")
        options.add_argument("--disable-popup-blocking")

        print("[CHROME] Iniciando navegador...")
        driver = webdriver.Chrome(options=options)

        try:
            # Acessar Shopee
            print("[CHROME] Acessando https://seller.shopee.com.br...")
            driver.get("https://seller.shopee.com.br/login")

            print("\n[INFO] === LOGIN MANUAL ===")
            print("[INFO] Faca login no navegador que se abriu")
            print("[INFO] Voce tem 5 minutos para fazer login")
            print("[INFO] Script continua automaticamente apos o login\n")

            # Aguardar login
            start = time.time()
            while time.time() - start < 300:  # 5 minutos
                try:
                    current_url = driver.current_url
                    if "login" not in current_url.lower() and "seller.shopee.com.br" in current_url:
                        print("[OK] Login detectado!")
                        break
                except:
                    pass
                time.sleep(1)

            time_spent = time.time() - start
            if time_spent >= 300:
                print("\n[ERRO] Timeout - login nao foi realizado em 5 minutos")
                return False

            # Esperar estabilizar
            time.sleep(2)

            # Navegar para produtos
            print("[CHROME] Acessando gerenciador de produtos...")
            driver.get("https://seller.shopee.com.br/portal/product/list")
            time.sleep(3)

            # Tentar encontrar produtos
            print("[CHROME] Procurando produtos...")

            products = None
            selectors = [
                "[data-item-id]",
                ".product-item",
                "[class*='ProductList']",
            ]

            for selector in selectors:
                try:
                    products = WebDriverWait(driver, 5).until(
                        EC.presence_of_all_elements_located((By.CSS_SELECTOR, selector))
                    )
                    if products:
                        print(f"[OK] {len(products)} produtos encontrados\n")
                        break
                except:
                    pass

            if not products:
                print("[AVISO] Nenhum produto encontrado")
                print("[DICA] Certifique-se que:")
                print("  - Tem produtos publicados na loja")
                print("  - A pagina carregou completamente")
                print("  - Esta na guia correta do painel")
                return False

            # Processar
            stats = {
                "total": len(products),
                "optimized": 0,
                "processed": 0,
                "errors": 0,
                "timestamp": datetime.now().isoformat(),
                "changes": [],
            }

            limit = min(10, len(products))

            print(f"[PROCESSANDO] Otimizando {limit} produtos...\n")

            for idx in range(limit):
                try:
                    # Recarregar lista
                    products = driver.find_elements(By.CSS_SELECTOR, "[data-item-id]")
                    if not products or idx >= len(products):
                        break

                    product_elem = products[idx]
                    stats["processed"] += 1

                    # Extrair titulo
                    title = ""
                    try:
                        title_elem = product_elem.find_element(By.CSS_SELECTOR, "a[title]")
                        title = title_elem.get_attribute("title") or ""
                    except:
                        title = product_elem.text[:100] if product_elem.text else ""

                    if not title:
                        print(f"[{idx+1}/{limit}] Sem titulo - pulando")
                        continue

                    title = title.strip()

                    # Gerar otimizacao
                    optimized = generate_optimized_title(title)

                    if optimized != title:
                        print(f"[{idx+1}/{limit}] === OTIMIZANDO ===")
                        print(f"  Titulo Antes: {title[:70]}")
                        print(f"  Titulo Depois: {optimized[:70]}")

                        try:
                            # Clicar no produto
                            product_elem.click()
                            time.sleep(2)

                            # Encontrar campo de titulo
                            title_inputs = driver.find_elements(By.CSS_SELECTOR, "input[type='text']")

                            if title_inputs:
                                # Primeira eh geralmente o titulo
                                title_input = title_inputs[0]

                                # Atualizar
                                title_input.clear()
                                title_input.send_keys(optimized)

                                # Procurar botao Salvar
                                save_buttons = driver.find_elements(By.TAG_NAME, "button")
                                clicked = False

                                for btn in save_buttons:
                                    try:
                                        btn_text = btn.text
                                        if "Salvar" in btn_text or "Confirmar" in btn_text:
                                            btn.click()
                                            print("  [OK] Clicado em Salvar")
                                            stats["optimized"] += 1
                                            clicked = True
                                            stats["changes"].append({
                                                "index": idx + 1,
                                                "before": title,
                                                "after": optimized,
                                            })
                                            break
                                    except:
                                        pass

                                if not clicked:
                                    print("  [INFO] Clique em Salvar manualmente (5 segundos)")
                                    time.sleep(5)

                                time.sleep(1)

                            # Voltar para lista
                            driver.back()
                            time.sleep(2)

                        except Exception as e:
                            print(f"  [ERRO] {e}")
                            stats["errors"] += 1
                            try:
                                driver.back()
                            except:
                                pass
                            time.sleep(1)

                    else:
                        if idx < 3:
                            print(f"[{idx+1}/{limit}] OK (sem mudancas)")

                    print()

                except Exception as e:
                    print(f"[{idx+1}] [ERRO] {e}\n")
                    stats["errors"] += 1

            # Resumo
            print("\n" + "="*80)
            print("RESUMO FINAL")
            print("="*80)
            print(f"Processados: {stats['processed']}/{limit}")
            print(f"Otimizados: {stats['optimized']}")
            print(f"Erros: {stats['errors']}")
            print()

            # Salvar relatorio
            report_dir = ROOT / "logs" / "shopee-optimizer"
            report_dir.mkdir(parents=True, exist_ok=True)

            report_file = report_dir / f"selenium_auto_{datetime.now().strftime('%Y%m%d_%H%M%S')}.json"
            with open(report_file, "w", encoding="utf-8") as f:
                json.dump(stats, f, ensure_ascii=False, indent=2)

            print(f"[OK] Relatorio: {report_file.relative_to(ROOT)}\n")

            print("[INFO] Navegador pode ser fechado manualmente")
            print("[INFO] Ou pressione Ctrl+C para encerrar\n")

            # Manter navegador aberto
            input("[AGUARDANDO] Pressione Enter para fechar o navegador...")

            return True

        finally:
            try:
                driver.quit()
            except:
                pass

    except ImportError:
        print("[ERRO] Selenium nao instalado")
        print("       Instale com: pip install selenium")
        return False
    except Exception as e:
        print(f"[ERRO] {type(e).__name__}: {e}")
        return False

def generate_optimized_title(title: str) -> str:
    """Gera titulo otimizado."""
    if not title or len(title) > 110:
        return title[:120]

    improved = title

    if "qualidade" not in improved.lower() and len(improved) < 100:
        improved = improved + " - Qualidade"

    if "novo" not in improved.lower():
        if len(improved) < 110:
            improved = improved + " Novo"

    return improved[:120].strip()

def main():
    print("\n" + "="*80)
    print("OTIMIZADOR AUTOMATICO COMPLETO - SHOPEE")
    print("MODO: CHROME + SELENIUM")
    print("="*80)

    result = run_automation()
    return 0 if result else 1

if __name__ == "__main__":
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        print("\n\n[CANCELADO] Processo interrompido pelo usuario")
        sys.exit(1)
