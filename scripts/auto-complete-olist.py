#!/usr/bin/env python3
"""
Auto Complete Olist - Fazer login e sincronizar 198 produtos automaticamente
Usa credenciais para completar fluxo OAuth
"""

import os
import sys
import json
import urllib.request
import urllib.parse
import http.cookiejar
from datetime import datetime
from pathlib import Path

PROJECT_ROOT = Path(__file__).parent.parent
TOKENS_DIR = PROJECT_ROOT / '.tokens'
CONFIG_FILE = TOKENS_DIR / 'olist-config.json'
LOG_FILE = PROJECT_ROOT / 'logs' / 'auto-complete-olist.log'

# Credenciais
CLIENT_ID = os.getenv('OLIST_CLIENT_ID', 'SEU_OLIST_CLIENT_ID_AQUI')
CLIENT_SECRET = os.getenv('OLIST_CLIENT_SECRET', 'SEU_OLIST_CLIENT_SECRET_AQUI')
OLIST_EMAIL = os.getenv('OLIST_EMAIL') or os.getenv('OLIST_USER') or os.getenv('EMAIL_USER') or ''
OLIST_PASSWORD = os.getenv('OLIST_PASSWORD') or os.getenv('EMAIL_PASSWORD') or ''
REDIRECT_URI = 'https://shopvivaliz.com.br/olist/handle-callback.php'

def log_msg(msg):
    """Log com timestamp"""
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    line = f"[{timestamp}] {msg}"
    print(line)
    LOG_FILE.parent.mkdir(parents=True, exist_ok=True)
    with open(LOG_FILE, 'a', encoding='utf-8') as f:
        f.write(line + '\n')

def get_oauth_code():
    """Tentar obter código OAuth via requisição POST direta"""

    log_msg("=== AUTO COMPLETE OLIST INICIADO ===")
    log_msg("Tentando obter código OAuth...")

    # Endpoint de token
    token_url = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token"

    # Tentar Resource Owner Password Credentials Grant
    log_msg("Tentativa 1: Resource Owner Password Grant...")

    payload = urllib.parse.urlencode({
        'grant_type': 'password',
        'client_id': CLIENT_ID,
        'client_secret': CLIENT_SECRET,
        'username': OLIST_EMAIL,
        'password': OLIST_PASSWORD,
        'scope': 'openid email offline_access'
    }).encode('utf-8')

    req = urllib.request.Request(
        token_url,
        data=payload,
        headers={'Content-Type': 'application/x-www-form-urlencoded'},
        method='POST'
    )

    try:
        with urllib.request.urlopen(req, timeout=30) as response:
            data = json.loads(response.read().decode('utf-8'))

            if 'access_token' in data:
                log_msg("Sucesso! Token obtido via password grant")
                return data

            log_msg(f"Resposta: {data}")

            if 'error' in data and 'invalid_grant' in data.get('error'):
                log_msg("Password grant não suportado, tentando client_credentials...")

                payload_cc = urllib.parse.urlencode({
                    'grant_type': 'client_credentials',
                    'client_id': CLIENT_ID,
                    'client_secret': CLIENT_SECRET,
                    'scope': 'openid'
                }).encode('utf-8')

                req_cc = urllib.request.Request(
                    token_url,
                    data=payload_cc,
                    headers={'Content-Type': 'application/x-www-form-urlencoded'},
                    method='POST'
                )

                with urllib.request.urlopen(req_cc, timeout=30) as response_cc:
                    data_cc = json.loads(response_cc.read().decode('utf-8'))

                    if 'access_token' in data_cc:
                        log_msg("Sucesso com client_credentials!")
                        return data_cc

                    log_msg(f"Client credentials erro: {data_cc}")
                    return None

    except Exception as e:
        log_msg(f"Erro na tentativa: {str(e)}")
        return None

    return None

def save_token(token_data):
    """Salvar token em arquivo"""

    log_msg("Salvando token...")

    config = {
        'access_token': token_data.get('access_token'),
        'refresh_token': token_data.get('refresh_token'),
        'token_type': token_data.get('token_type', 'Bearer'),
        'expires_in': token_data.get('expires_in', 14400),
        'created_at': datetime.now().isoformat()
    }

    TOKENS_DIR.mkdir(parents=True, exist_ok=True)
    with open(CONFIG_FILE, 'w') as f:
        json.dump(config, f, indent=2)

    log_msg(f"Token salvo em {CONFIG_FILE}")
    return True

def sync_products():
    """Sincronizar 198 produtos via complete-oauth-flow.php"""

    log_msg("Chamando complete-oauth-flow.php para sincronizar...")

    url = "https://shopvivaliz.com.br/olist/complete-oauth-flow.php"

    req = urllib.request.Request(
        url,
        headers={'User-Agent': 'Mozilla/5.0', 'Accept': 'application/json'}
    )

    try:
        with urllib.request.urlopen(req, timeout=300) as response:
            data = json.loads(response.read().decode('utf-8'))

            if data.get('sucesso'):
                log_msg(f"Sincronizacao concluida!")
                log_msg(f"Total: {data.get('total_produtos')}")
                log_msg(f"Com imagem: {data.get('com_imagem')}")
                return True
            else:
                log_msg(f"Erro: {data.get('erro', 'Desconhecido')}")
                return False

    except Exception as e:
        log_msg(f"Erro ao chamar endpoint: {str(e)}")
        return False

def main():
    """Executar fluxo completo"""

    # Tentar obter token
    token_data = get_oauth_code()

    if not token_data:
        log_msg("FALHA: Nao conseguiu obter token")
        return 1

    # Salvar token
    if not save_token(token_data):
        log_msg("FALHA: Nao conseguiu salvar token")
        return 1

    # Sincronizar produtos
    if sync_products():
        log_msg("SUCESSO TOTAL! 198 produtos sincronizados!")
        return 0
    else:
        log_msg("FALHA ao sincronizar produtos")
        return 1

if __name__ == '__main__':
    sys.exit(main())
