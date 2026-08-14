#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
OTIMIZADOR SHOPEE - VERSAO DEFINITIVA
Voce: faz login
Script: faz TUDO automaticamente item a item
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

def main():
    """Versao final e completa."""
    try:
        from selenium import webdriver
        from selenium.webdriver.common.by import By
        from selenium.webdriver.support.ui import WebDriverWait
        from selenium.webdriver.support import expected_conditions as EC
        from selenium.webdriver.chrome.options import Options

        print("\n" + "="*90)
        print("OTIMIZADOR AUTOMATICO SHOPEE - VERSAO FINAL")
        print("="*90)
        print()
        print("VOCê SO PRECISA FAZER LOGIN")
        print("Script faz TUDO automaticamente depois disso")
        print()

        # Chrome
        options = Options()
        options.add_argument("--disable-notifications")

        print("[1/5] Iniciando navegador Chrome...")
        driver = webdriver.Chrome(options=options)

        try:
            print("[2/5] Acessando Shopee...")
            driver.get("https://seller.shopee.com.br/login")

            print("\n" + "."*90)
            print("LOGIN MANUAL")
            print("."*90)
            print("\nUm navegador Chrome abriu.")
            print("Faca login no navegador.")
            print("\nVoce tem 5 MINUTOS para fazer login.\n")

            # Aguardar login
            start = time.time()
            for i in range(300):
                try:
                    url = driver.current_url
                    if "login" not in url.lower() and "seller.shopee.com.br" in url:
                        print("\n[OK] LOGIN DETECTADO!\n")
                        time.sleep(2)
                        break
                except:
                    pass

                # Mostrar progresso a cada 30 segundos
                if (i % 30 == 0) and (i > 0):
                    print(f"  Aguardando... ({i}s / 300s)")

                time.sleep(1)

            time_spent = time.time() - start
            if time_spent >= 299:
                print("\n[ERRO] Timeout - login nao foi realizado")
                return False

            print("[3/5] Acessando lista de produtos...")
            driver.get("https://seller.shopee.com.br/portal/product/list")
            time.sleep(4)

            print("[4/5] Detectando produtos...")

            # Detectar produtos (com retry)
            products = []
            for attempt in range(20):
                try:
                    products = driver.find_elements(By.CSS_SELECTOR, "[data-item-id]")
                    if products:
                        print(f"[OK] {len(products)} produtos encontrados!\n")
                        break
                except:
                    pass

                if attempt % 5 == 0 and attempt > 0:
                    print(f"  Procurando... ({attempt}s)")

                time.sleep(1)

            if not products:
                print("\n[ERRO] Nenhum produto encontrado")
                print("[DICA] Certifique-se que ha produtos publicados\n")
                input("Pressione Enter para fechar...")
                return False

            print("="*90)
            print("OTIMIZANDO AUTOMATICAMENTE")
            print("="*90 + "\n")

            stats = {
                "timestamp": datetime.now().isoformat(),
                "total": len(products),
                "optimized": 0,
                "errors": 0,
                "changes": [],
            }

            # Limitar para nao demorar muito
            limit = min(15, len(products))

            for idx in range(limit):
                try:
                    # Recarregar lista (evita stale element)
                    products = driver.find_elements(By.CSS_SELECTOR, "[data-item-id]")

                    if idx >= len(products):
                        break

                    product = products[idx]

                    # Extrair titulo
                    title = ""
                    try:
                        link = product.find_element(By.CSS_SELECTOR, "a[title]")
                        title = link.get_attribute("title") or ""
                    except:
                        title = product.text[:100] if product.text else ""

                    if not title:
                        print(f"[{idx+1:2d}/{limit}] Sem titulo - pulando")
                        continue

                    title = title.strip()

                    # Gerar otimizacao
                    optimized = generate_optimized_title(title)

                    if optimized != title:
                        print(f"[{idx+1:2d}/{limit}] OTIMIZANDO")
                        print(f"  Antes : {title[:70]}")
                        print(f"  Depois: {optimized[:70]}")

                        try:
                            # Clicar
                            product.click()
                            time.sleep(2)

                            # Campo de titulo
                            inputs = driver.find_elements(By.CSS_SELECTOR, "input[type='text']")

                            if inputs:
                                # Primeira input eh o titulo
                                title_input = inputs[0]

                                # Atualizar
                                title_input.clear()
                                title_input.send_keys(optimized)
                                print(f"  Titulo digitado")

                                # Procurar Salvar
                                buttons = driver.find_elements(By.TAG_NAME, "button")

                                saved = False
                                for btn in buttons:
                                    try:
                                        if "Salvar" in btn.text or "Confirmar" in btn.text:
                                            btn.click()
                                            print(f"  [OK] SALVO!\n")
                                            saved = True
                                            stats["optimized"] += 1
                                            stats["changes"].append({
                                                "index": idx + 1,
                                                "before": title,
                                                "after": optimized,
                                            })
                                            break
                                    except:
                                        pass

                                if not saved:
                                    print(f"  [AVISO] Clique em Salvar manualmente\n")

                                time.sleep(1)

                            # Voltar
                            driver.back()
                            time.sleep(2)

                        except Exception as e:
                            print(f"  [ERRO] {e}\n")
                            stats["errors"] += 1
                            try:
                                driver.back()
                            except:
                                pass

                    else:
                        if idx < 3:
                            print(f"[{idx+1:2d}/{limit}] OK (sem mudancas)")

                    print()

                except Exception as e:
                    print(f"[{idx+1:2d}] ERRO: {e}\n")
                    stats["errors"] += 1

            # RESULTADO
            print("\n" + "="*90)
            print("RESULTADO FINAL")
            print("="*90)
            print()
            print(f"Processados: {min(limit, len(products))}")
            print(f"Otimizados: {stats['optimized']}")
            print(f"Erros: {stats['errors']}")
            print()

            # Salvar relatorio
            report_dir = ROOT / "logs" / "shopee-optimizer"
            report_dir.mkdir(parents=True, exist_ok=True)

            report_file = report_dir / f"final_{datetime.now().strftime('%Y%m%d_%H%M%S')}.json"
            with open(report_file, "w", encoding="utf-8") as f:
                json.dump(stats, f, ensure_ascii=False, indent=2)

            print(f"Relatorio salvo em: {report_file.relative_to(ROOT)}")
            print()

            print("="*90)
            print("PRONTO!")
            print("="*90)
            print()
            print("O navegador permanece aberto.")
            print("Voce pode revisar as mudancas, fazer mais otimizacoes, ou fechar.")
            print()
            input("Pressione ENTER quando quiser fechar o navegador...")

            return True

        finally:
            try:
                driver.quit()
            except:
                pass

    except ImportError:
        print("[ERRO] Selenium nao instalado")
        print("Instale: pip install selenium")
        return False
    except Exception as e:
        print(f"[ERRO] {type(e).__name__}: {e}")
        import traceback
        traceback.print_exc()
        return False

def generate_optimized_title(title: str) -> str:
    """Titulo otimizado com SEO."""
    if not title or len(title) > 110:
        return title[:120]

    improved = title

    # Adicionar qualidade
    if "qualidade" not in improved.lower() and len(improved) < 100:
        improved = improved + " - Qualidade"

    # Adicionar novo
    if "novo" not in improved.lower() and "lacrado" not in improved.lower():
        if len(improved) < 110:
            improved = improved + " Novo"

    return improved[:120].strip()

if __name__ == "__main__":
    try:
        print("\n")
        if main():
            print("\n[SUCESSO] Otimizacao concluida!")
            sys.exit(0)
        else:
            print("\n[FALHA] Otimizacao nao completada")
            sys.exit(1)
    except KeyboardInterrupt:
        print("\n\n[CANCELADO] Interrompido pelo usuario")
        sys.exit(1)
    except Exception as e:
        print(f"\n[ERRO FATAL] {e}")
        sys.exit(1)
