#!/usr/bin/env python3
"""
Sincronizar Olist - Manual (abre navegador, você faz login, script captura resultado)
"""
import webbrowser
import time
import requests
import json
from pathlib import Path
from datetime import datetime

# Configuração
SITE_BASE = "https://dev.shopvivaliz.com.br"
CONNECT_URL = f"{SITE_BASE}/olist/connect.php"
SYNC_URL = f"{SITE_BASE}/olist/sync-products.php"

print("\n" + "="*70)
print("SINCRONIZAR 198 PRODUTOS OLIST - MODO MANUAL")
print("="*70)

try:
    # ========================================================================
    # PASSO 1: Abrir navegador
    # ========================================================================

    print("\n[1] Abrindo navegador...")
    print(f"    URL: {CONNECT_URL}")

    webbrowser.open(CONNECT_URL)

    print("\n[2] INSTRUÇÕES PARA VOCÊ:")
    print("    1. Uma janela do navegador será aberta")
    print("    2. Você verá a tela de login da Olist")
    print("    3. Faça login com:")
    print("       Email: via OLIST_EMAIL/EMAIL_USER")
    print("       Senha: via OLIST_PASSWORD/EMAIL_PASSWORD")
    print("    4. Autorize a aplicação ShopVivaliz")
    print("    5. Você será redirecionado para callback.php")
    print("    6. Este script vai sincronizar automaticamente")

    print("\n[3] Aguardando você fazer login...")
    print("    (Você tem até 5 minutos...)")

    # Aguardar login (máx 5 minutos)
    time.sleep(10)  # Dar tempo de abrir o navegador

    # ========================================================================
    # PASSO 2: Acessar sync-products.php
    # ========================================================================

    print("\n[4] Acessando página de sincronização...")

    session = requests.Session()
    session.verify = False

    response = session.get(SYNC_URL, timeout=300)

    print(f"    Status: {response.status_code}")

    # ========================================================================
    # PASSO 3: Verificar resultado
    # ========================================================================

    print("\n[5] Verificando resultado...")

    if response.status_code == 200:
        try:
            data = response.json()

            print("\n" + "="*70)
            print("SUCESSO!")
            print("="*70)
            print(f"Total de produtos: {data.get('total_produtos', '?')}")
            print(f"Com imagem: {data.get('com_imagem', '?')}")
            print(f"Sem imagem: {data.get('sem_imagem', '?')}")
            print(f"Taxa de cobertura: {data.get('taxa_cobertura', '?')}%")
            print(f"Mensagem: {data.get('mensagem', '?')}")

            # Salvar resultado
            result_file = Path("logs/olist-sync-resultado.json")
            result_file.parent.mkdir(exist_ok=True)

            with open(result_file, 'w', encoding='utf-8') as f:
                json.dump({
                    'timestamp': datetime.now().isoformat(),
                    'sucesso': True,
                    'resultado': data
                }, f, ensure_ascii=False, indent=2)

            print(f"\nResultado salvo em: {result_file}")

        except:
            print("\n[AVISO] Resposta não é JSON, mas status é 200")
            print("Primeira linha da resposta:")
            print(response.text[:200])

    else:
        print(f"\n[ERRO] Status {response.status_code}")
        print("Resposta:")
        print(response.text[:500])

    print("\n" + "="*70)
    print("CONCLUÍDO!")
    print("="*70)
    print(f"\nVerifique o catálogo em: {SITE_BASE}/catalogo/")

except Exception as e:
    print(f"\n[ERRO] {str(e)}")
    import traceback
    traceback.print_exc()

finally:
    print("\nPressione Enter para sair...")
    input()
