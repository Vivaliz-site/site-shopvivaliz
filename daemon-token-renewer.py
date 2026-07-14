#!/usr/bin/env python3
"""
Daemon: Renova token OAuth automaticamente a cada 3 horas
Evita expiração mantendo access_token + refresh_token sempre válidos
"""

import json
import time
import urllib.request
import urllib.parse
from pathlib import Path
from datetime import datetime

def get_config():
    """Carregar credenciais do .env"""
    env_file = Path(".env")
    config = {}

    for line in env_file.read_text().split('\n'):
        if '=' in line and not line.strip().startswith('#'):
            k, v = line.split('=', 1)
            config[k.strip()] = v.strip().strip('"').strip("'")

    return config

def renew_token(config):
    """Renovar token via refresh_token"""
    client_id = config.get('OLIST_CLIENT_ID')
    client_secret = config.get('OLIST_CLIENT_SECRET')
    refresh_token = config.get('OLIST_REFRESH_TOKEN')

    if not all([client_id, client_secret, refresh_token]):
        print("[!] Credenciais incompletas no .env")
        return None

    payload = urllib.parse.urlencode({
        'grant_type': 'refresh_token',
        'client_id': client_id,
        'client_secret': client_secret,
        'refresh_token': refresh_token,
    }).encode()

    req = urllib.request.Request(
        'https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token',
        data=payload,
        headers={'Content-Type': 'application/x-www-form-urlencoded'}
    )

    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            result = json.loads(r.read())
            return result
    except Exception as e:
        print(f"[!] Erro ao renovar: {e}")
        return None

def update_env(new_token, new_refresh_token):
    """Atualizar .env com novo token"""
    env_file = Path(".env")
    content = env_file.read_text()

    # Replace tokens
    lines = []
    for line in content.split('\n'):
        if line.startswith('OLIST_ACCESS_TOKEN='):
            lines.append(f'OLIST_ACCESS_TOKEN={new_token}')
        elif line.startswith('TINY_ACCESS_TOKEN='):
            lines.append(f'TINY_ACCESS_TOKEN={new_token}')
        elif line.startswith('OLIST_REFRESH_TOKEN='):
            lines.append(f'OLIST_REFRESH_TOKEN={new_refresh_token}')
        elif line.startswith('TINY_REFRESH_TOKEN='):
            lines.append(f'TINY_REFRESH_TOKEN={new_refresh_token}')
        else:
            lines.append(line)

    env_file.write_text('\n'.join(lines))
    print(f"[✓] .env atualizado com novo token")

def main():
    """Loop principal - renova a cada 3 horas"""
    print("[*] Daemon de Renovação de Token OAuth")
    print("[*] Renovando a cada 3 horas")
    print("[*] Pressione Ctrl+C para parar\n")

    iteration = 0
    while True:
        iteration += 1
        timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')

        print(f"\n[{timestamp}] [Iteração {iteration}] Renovando token...")

        config = get_config()
        result = renew_token(config)

        if result and 'access_token' in result:
            new_token = result['access_token']
            new_refresh = result.get('refresh_token', config.get('OLIST_REFRESH_TOKEN'))

            update_env(new_token, new_refresh)
            print(f"[✓] Token renovado com sucesso!")
            print(f"[*] Próxima renovação em 2 horas...")
        else:
            print(f"[!] Falha ao renovar token")
            print(f"[*] Tentando novamente em 1 hora...")
            time.sleep(3600)  # Tentar novamente em 1 hora
            continue

        # Aguardar 2 horas (7200 segundos)
        time.sleep(7200)

if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\n[*] Daemon parado")
