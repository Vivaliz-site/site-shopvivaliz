#!/usr/bin/env python3
"""
Auto Sync Agora - Trocar OAuth code por token e sincronizar 198 produtos
Execução: python scripts/auto-sync-agora.py --code AUTH_CODE
"""

import sys
import argparse
import urllib.request
import urllib.parse
import json
from pathlib import Path
from datetime import datetime

PROJECT_ROOT = Path(__file__).parent.parent
LOG_FILE = PROJECT_ROOT / 'logs' / 'auto-sync-agora.log'

def log_msg(msg):
    """Log com timestamp"""
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    line = f"[{timestamp}] {msg}"
    print(line)

    LOG_FILE.parent.mkdir(parents=True, exist_ok=True)
    with open(LOG_FILE, 'a', encoding='utf-8') as f:
        f.write(line + '\n')

def get_token_from_code(code):
    """Chamar setup-oauth.php com código para obter token"""

    log_msg("=== AUTO SYNC AGORA INICIADO ===")
    log_msg(f"Codigo recebido: {code[:30]}...")

    # Chamar sync-agora.php com o código
    url = f"https://shopvivaliz.com.br/olist/sync-agora.php?refresh_token={code}"

    log_msg(f"Chamando: {url[:80]}...")

    try:
        with urllib.request.urlopen(url, timeout=300) as response:
            response_text = response.read().decode('utf-8')

            try:
                data = json.loads(response_text)

                if data.get('sucesso'):
                    log_msg(f"Sucesso!")
                    log_msg(f"Total produtos: {data.get('total_produtos', 'N/A')}")
                    log_msg(f"Com imagem: {data.get('com_imagem', 'N/A')}")
                    log_msg(f"Taxa cobertura: {data.get('taxa_cobertura', 'N/A')}")
                    return True
                else:
                    log_msg(f"Erro: {data.get('erro', 'Desconhecido')}")
                    return False
            except json.JSONDecodeError:
                log_msg(f"Resposta nao e JSON: {response_text[:200]}")
                return False

    except Exception as e:
        log_msg(f"Erro ao chamar endpoint: {str(e)}")
        return False

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--code', help='OAuth code to exchange')
    args = parser.parse_args()

    if not args.code:
        log_msg("Erro: --code parametro obrigatorio")
        return 1

    if get_token_from_code(args.code):
        log_msg("Token obtido com sucesso!")
        return 0
    else:
        log_msg("Falha ao obter token")
        return 1

if __name__ == '__main__':
    sys.exit(main())
