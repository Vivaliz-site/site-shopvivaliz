#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Google Ads Token Renewer Daemon - ShopVivaliz
Renova o access_token do Google Ads automaticamente a cada 50 minutos
"""

import os
import sys
import time
import urllib.parse
import urllib.request
import urllib.error
import json
import tempfile
from pathlib import Path

ENV_PATH = Path("c:/site-shopvivaliz/.env") if os.name == "nt" else Path("/home/ubuntu/site-shopvivaliz/.env")
TOKEN_URL = "https://oauth2.googleapis.com/token"

def load_env() -> dict:
    config = {}
    if not ENV_PATH.is_file():
        return config
    with open(ENV_PATH, "r", encoding="utf-8", errors="ignore") as f:
        for raw in f.read().splitlines():
            line = raw.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, value = line.split("=", 1)
            config[key.strip()] = value.strip().strip('"').strip("'")
    return config

def write_env(new_values: dict) -> bool:
    if not ENV_PATH.is_file():
        print(f"[!] Erro: .env nao encontrado em {ENV_PATH}")
        return False
    with open(ENV_PATH, "r", encoding="utf-8", errors="ignore") as f:
        lines = f.readlines()
        
    updated_lines = []
    pending = dict(new_values)
    for line in lines:
        stripped = line.strip()
        if stripped and not stripped.startswith("#") and "=" in stripped:
            key, _ = stripped.split("=", 1)
            key = key.strip()
            if key in pending:
                updated_lines.append(f"{key}={pending[key]}\n")
                del pending[key]
                continue
        updated_lines.append(line)
        
    # Append any remaining keys
    for key, val in pending.items():
        updated_lines.append(f"{key}={val}\n")

    mode = ENV_PATH.stat().st_mode & 0o777
    fd, temp_name = tempfile.mkstemp(prefix=".env.google.", dir=str(ENV_PATH.parent))
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as f:
            f.writelines(updated_lines)
            f.flush()
            os.fsync(f.fileno())
        os.chmod(temp_name, mode)
        os.replace(temp_name, ENV_PATH)
        return True
    finally:
        if os.path.exists(temp_name):
            os.unlink(temp_name)

def renew_google_token(config: dict) -> str | None:
    client_id = config.get("GOOGLE_OAUTH_CLIENT_ID")
    client_secret = config.get("GOOGLE_OAUTH_CLIENT_SECRET")
    refresh_token = config.get("GOOGLE_ADS_REFRESH_TOKEN")
    
    if not all([client_id, client_secret, refresh_token]):
        print("[!] Erro: Credenciais do Google Ads incompletas no .env")
        return None
        
    payload = urllib.parse.urlencode({
        "grant_type": "refresh_token",
        "client_id": client_id,
        "client_secret": client_secret,
        "refresh_token": refresh_token
    }).encode("utf-8")
    
    req = urllib.request.Request(TOKEN_URL, data=payload, headers={
        "Content-Type": "application/x-www-form-urlencoded"
    })
    
    try:
        with urllib.request.urlopen(req, timeout=15) as response:
            res_data = json.loads(response.read().decode("utf-8"))
            access_token = res_data.get("access_token")
            new_refresh = res_data.get("refresh_token")

            if not access_token:
                print("[!] Erro: resposta do Google OAuth nao retornou access_token")
                return None
            
            updates = {"GOOGLE_ADS_ACCESS_TOKEN": access_token}
            if new_refresh:
                updates["GOOGLE_ADS_REFRESH_TOKEN"] = new_refresh

            if not write_env(updates):
                return None
            print(f"[{time.strftime('%Y-%m-%d %H:%M:%S')}] Token do Google Ads renovado com sucesso.")
            return access_token
    except urllib.error.HTTPError as e:
        try:
            body = e.read().decode("utf-8", errors="replace")
        except Exception:
            body = str(e)
        print(f"[!] Erro HTTP ao renovar token do Google Ads: status={e.code} body={body}")
        return None
    except Exception as e:
        print(f"[!] Erro ao renovar token do Google Ads: {e}")
        return None

def main() -> int:
    print("Iniciando Google Ads Token Renewer Daemon...")
    once = "--once" in sys.argv
    
    while True:
        config = load_env()
        token = renew_google_token(config)
        
        if once:
            return 0 if token else 1
        # Dorme por 50 minutos (3000 segundos) antes de renovar novamente
        time.sleep(3000)

if __name__ == "__main__":
    raise SystemExit(main())
