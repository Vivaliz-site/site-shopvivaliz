#!/usr/bin/env python3
"""
Olist Direct Login - Autenticar diretamente na API Olist com email/senha
Sem precisar de OAuth/navegador
"""

import os
import sys
import json
import requests
from pathlib import Path
from datetime import datetime

PROJECT_ROOT = Path(__file__).parent.parent
TOKENS_DIR = PROJECT_ROOT / '.tokens'
CONFIG_FILE = TOKENS_DIR / 'olist-config.json'
LOG_FILE = PROJECT_ROOT / 'logs' / 'olist-direct-login.log'

OLIST_EMAIL = os.getenv('OLIST_EMAIL') or os.getenv('OLIST_USER') or os.getenv('EMAIL_USER') or ''
OLIST_PASSWORD = os.getenv('OLIST_PASSWORD') or os.getenv('EMAIL_PASSWORD') or ''
CLIENT_ID = os.getenv('OLIST_CLIENT_ID', 'SEU_OLIST_CLIENT_ID_AQUI')
CLIENT_SECRET = os.getenv('OLIST_CLIENT_SECRET', 'SEU_OLIST_CLIENT_SECRET_AQUI')

def log_msg(msg):
    """Log com timestamp"""
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    line = f"[{timestamp}] {msg}"
    print(line)

    LOG_FILE.parent.mkdir(parents=True, exist_ok=True)
    with open(LOG_FILE, 'a', encoding='utf-8') as f:
        f.write(line + '\n')

def login_direct():
    """Tentar autenticacao direta"""

    log_msg("=== OLIST DIRECT LOGIN INICIADO ===")

    # Endpoint direto de token com Resource Owner Password
    token_url = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token"

    # Tentar grant_type password (Resource Owner Password Credentials)
    log_msg("Tentando Resource Owner Password grant...")

    payload = {
        'grant_type': 'password',
        'client_id': CLIENT_ID,
        'client_secret': CLIENT_SECRET,
        'username': OLIST_EMAIL,
        'password': OLIST_PASSWORD,
        'scope': 'openid email offline_access'
    }

    try:
        response = requests.post(token_url, data=payload, timeout=30, verify=False)
        data = response.json()

        log_msg(f"Status: {response.status_code}")
        log_msg(f"Response: {json.dumps(data, indent=2)[:500]}")

        if response.status_code == 200 and 'access_token' in data:
            log_msg("Sucesso! Token obtido via password grant")

            # Salvar config
            config = {
                'access_token': data.get('access_token'),
                'refresh_token': data.get('refresh_token'),
                'token_type': data.get('token_type', 'Bearer'),
                'expires_in': data.get('expires_in', 14400),
                'created_at': datetime.now().isoformat()
            }

            TOKENS_DIR.mkdir(parents=True, exist_ok=True)
            with open(CONFIG_FILE, 'w') as f:
                json.dump(config, f, indent=2)

            log_msg(f"Token salvo em {CONFIG_FILE}")
            return True

        elif 'invalid_grant' in str(data):
            log_msg("Erro: invalid_grant - credenciais incorretas ou grant nao suportado")
            # Tentar client_credentials como fallback
            log_msg("Tentando client_credentials grant...")

            payload_cc = {
                'grant_type': 'client_credentials',
                'client_id': CLIENT_ID,
                'client_secret': CLIENT_SECRET,
                'scope': 'openid email'
            }

            response_cc = requests.post(token_url, data=payload_cc, timeout=30, verify=False)
            data_cc = response_cc.json()

            log_msg(f"Client Credentials Status: {response_cc.status_code}")

            if response_cc.status_code == 200 and 'access_token' in data_cc:
                log_msg("Sucesso com client_credentials!")

                config = {
                    'access_token': data_cc.get('access_token'),
                    'token_type': data_cc.get('token_type', 'Bearer'),
                    'expires_in': data_cc.get('expires_in', 14400),
                    'created_at': datetime.now().isoformat(),
                    'method': 'client_credentials'
                }

                TOKENS_DIR.mkdir(parents=True, exist_ok=True)
                with open(CONFIG_FILE, 'w') as f:
                    json.dump(config, f, indent=2)

                log_msg(f"Token salvo em {CONFIG_FILE}")
                return True
            else:
                log_msg(f"Erro client_credentials: {data_cc}")
                return False
        else:
            log_msg(f"Erro desconhecido: {data}")
            return False

    except Exception as e:
        log_msg(f"Erro: {str(e)}")
        import traceback
        log_msg(traceback.format_exc())
        return False

def main():
    log_msg("Iniciando login direto na Olist...")

    if login_direct():
        log_msg("OK! Token obtido com sucesso!")
        log_msg("Proximo passo: python scripts/call-sync-agora.py")
        return 0
    else:
        log_msg("FALHA no login")
        return 1

if __name__ == '__main__':
    sys.exit(main())
