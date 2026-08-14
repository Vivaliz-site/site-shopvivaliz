#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Otimizador Shopee - MODO INTERATIVO
Mantém navegador aberto enquanto você trabalha.
Otimiza item a item conforme você abre cada um.
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

def run_interactive():
    """Modo interativo: usuario abre, script otimiza."""
    try:
        from selenium import webdriver
        from selenium.webdriver.common.by import By
        from selenium.webdriver.chrome.options import Options
        import keyboard

        print("\n" + "="*80)
        print("MODO INTERATIVO - OTIMIZAR ENQUANTO NAVEGA")
        print("="*80 + "\n")

        # Chrome setup
        options = Options()
        options.add_argument("--disable-notifications")

        print("[1] Abrindo Chrome...")
        driver = webdriver.Chrome(options=options)

        print("[2] Acessando Shopee...")
        driver.get("https://seller.shopee.com.br/login")

        print("[3] Faca login no navegador (5 minutos)\n")

        # Aguardar login
        start = time.time()
        while time.time() - start < 300:
            try:
                if "login" not in driver.current_url.lower():
                    print("[OK] Login detectado!\n")
                    break
            except:
                pass
            time.sleep(1)

        time.sleep(2)

        # Navegar para lista
        print("[4] Acessando lista de produtos...")
        driver.get("https://seller.shopee.com.br/portal/product/list")
        time.sleep(3)

        stats = {
            "session_start": datetime.now().isoformat(),
            "optimized": 0,
            "changes": [],
        }

        print("\n" + "="*80)
        print("MODO INTERATIVO - PRONTO PARA USAR")
        print("="*80 + "\n")

        print("INSTRUCOES:")
        print("1. Abra um produto no navegador (clique nele)")
        print("2. Veja o titulo atual")
        print("3. Script vai:")
        print("   - Detectar que voce abriu um produto")
        print("   - Gerar titulo otimizado")
        print("   - Mostrar a sugestao")
        print("   - Voce decide: aceitar (S) ou rejeitar (N)")
        print()
        print("TECLAS:")
        print("   [S] - Aceitar sugestao de otimizacao")
        print("   [N] - Rejeitar")
        print("   [Q] - Sair do programa")
        print()
        print("[Iniciando monitoramento...]\n")

        # Monitoramento
        monitored_titles = set()
        last_title = None

        while True:
            try:
                # Detectar titulo no editor
                title_inputs = driver.find_elements(By.CSS_SELECTOR, "input[type='text']")

                if title_inputs:
                    current_title = title_inputs[0].get_attribute("value") or ""

                    # Novo titulo detectado
                    if current_title and current_title != last_title and current_title not in monitored_titles:
                        last_title = current_title

                        print(f"\n[NOVO TITULO DETECTADO]")
                        print(f"Atual: {current_title[:80]}")

                        # Gerar otimizacao
                        optimized = generate_optimized_title(current_title)

                        if optimized != current_title:
                            print(f"Sugestao: {optimized[:80]}")
                            print()
                            print("[Voce tem 10 segundos para decidir...]")
                            print("[S] Aceitar  [N] Rejeitar\n")

                            # Aguardar resposta (com timeout)
                            start_input = time.time()
                            accepted = False

                            while time.time() - start_input < 10:
                                try:
                                    if keyboard.is_pressed('s'):
                                        print("[OK] Titulo atualizado!")
                                        title_inputs[0].clear()
                                        title_inputs[0].send_keys(optimized)
                                        accepted = True
                                        monitored_titles.add(current_title)

                                        stats["optimized"] += 1
                                        stats["changes"].append({
                                            "before": current_title,
                                            "after": optimized,
                                            "timestamp": datetime.now().isoformat(),
                                        })
                                        break

                                    elif keyboard.is_pressed('n'):
                                        print("[OK] Pulado")
                                        monitored_titles.add(current_title)
                                        break

                                    elif keyboard.is_pressed('q'):
                                        print("\n[Encerrando...]")
                                        raise KeyboardInterrupt()

                                except:
                                    pass

                                time.sleep(0.1)

                            if not accepted and current_title not in monitored_titles:
                                print("[Timeout] Pulado")
                                monitored_titles.add(current_title)

                        else:
                            print(f"Ja otimizado")
                            monitored_titles.add(current_title)

                        print()

                elif last_title:
                    # Editor foi fechado
                    last_title = None
                    print("[Voltando para lista...]")

                time.sleep(0.5)

            except KeyboardInterrupt:
                break
            except Exception as e:
                print(f"[ERRO] {e}")
                time.sleep(1)

        # Resumo
        print("\n" + "="*80)
        print("RESUMO DA SESSAO")
        print("="*80)
        print(f"Otimizados: {stats['optimized']}")
        print()

        # Salvar
        report_dir = ROOT / "logs" / "shopee-optimizer"
        report_dir.mkdir(parents=True, exist_ok=True)

        report_file = report_dir / f"interactive_{datetime.now().strftime('%Y%m%d_%H%M%S')}.json"
        with open(report_file, "w", encoding="utf-8") as f:
            json.dump(stats, f, ensure_ascii=False, indent=2)

        print(f"Relatorio: {report_file.relative_to(ROOT)}\n")

        print("[INFO] Navegador permanece aberto")
        print("[INFO] Feche-o manualmente quando terminar\n")

        return True

    except ImportError:
        print("[ERRO] keyboard nao instalado")
        print("       Instale com: pip install keyboard")
        return False
    except Exception as e:
        print(f"[ERRO] {e}")
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
    print("OTIMIZADOR SHOPEE - MODO INTERATIVO")
    print("="*80)

    result = run_interactive()
    return 0 if result else 1

if __name__ == "__main__":
    try:
        # Tentar usar keyboard se disponivel
        try:
            import keyboard
        except ImportError:
            print("[AVISO] Biblioteca 'keyboard' nao instalada")
            print("[DICA] Instale com: pip install keyboard")
            print("[INFO] Usando o script alterativo: shopee_selenium_auto.py\n")
            sys.exit(1)

        sys.exit(main())
    except KeyboardInterrupt:
        print("\n[CANCELADO] Programa encerrado")
        sys.exit(0)
