#!/usr/bin/env python3
"""Chamar sync-agora.php para sincronizar 198 produtos"""

import urllib.request
import json
from datetime import datetime
from pathlib import Path

PROJECT_ROOT = Path(__file__).parent.parent
LOG_FILE = PROJECT_ROOT / 'logs' / 'call-sync-agora.log'

def log_msg(msg):
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    line = f"[{timestamp}] {msg}"
    print(line)
    LOG_FILE.parent.mkdir(parents=True, exist_ok=True)
    with open(LOG_FILE, 'a', encoding='utf-8') as f:
        f.write(line + '\n')

log_msg("=== CHAMANDO SYNC AGORA ===")

url = "https://shopvivaliz.com.br/olist/sync-agora.php"

req = urllib.request.Request(
    url,
    headers={
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        'Accept': 'application/json',
    }
)

try:
    with urllib.request.urlopen(req, timeout=300) as response:
        data = json.loads(response.read().decode('utf-8'))

        log_msg(f"Status: {response.status}")
        log_msg(f"Resposta: {json.dumps(data, indent=2)}")

        if data.get('sucesso'):
            log_msg(f"[OK] Sincronizacao concluida!")
            log_msg(f"Total: {data.get('total_produtos')}")
            log_msg(f"Com imagem: {data.get('com_imagem')}")
            print("SUCESSO!")
        else:
            log_msg(f"[ERRO] {data.get('erro', 'Desconhecido')}")
            print("ERRO!")

except Exception as e:
    log_msg(f"Erro: {str(e)}")
    print(f"ERRO: {e}")
