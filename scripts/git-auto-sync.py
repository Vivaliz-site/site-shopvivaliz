#!/usr/bin/env python3
"""
Git Auto-Sync Daemon
Executado via cron a cada 30 minutos na VM Oracle
Sincroniza repositório com main branch
"""

import os
import sys
import subprocess
import json
import time
from datetime import datetime
from pathlib import Path

# Configuração
REPO_DIR = "/home/ubuntu/site-shopvivaliz"
BRANCH = "main"
LOG_DIR = "/var/log/shopvivaliz"
LOG_FILE = f"{LOG_DIR}/git-auto-sync-{datetime.now().strftime('%Y%m%d')}.log"
LOCK_FILE = f"{REPO_DIR}/.git-sync.lock"

def log_message(level: str, msg: str):
    """Log com timestamp"""
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    log_line = f"[{timestamp}] [{level}] {msg}"

    # Criar diretório de logs se não existir
    os.makedirs(LOG_DIR, exist_ok=True)

    # Escrever no arquivo
    with open(LOG_FILE, "a") as f:
        f.write(log_line + "\n")

    # Imprimir se for erro
    if level in ["ERROR", "WARNING"]:
        print(log_line, file=sys.stderr)

def is_locked():
    """Verificar se há lock ativo"""
    if not os.path.exists(LOCK_FILE):
        return False

    try:
        with open(LOCK_FILE, "r") as f:
            lock_data = json.load(f)
            lock_time = lock_data.get("timestamp", 0)
            # Se lock tem mais de 10 minutos, é stale
            if time.time() - lock_time > 600:
                log_message("WARNING", "Stale lock encontrado, removendo")
                os.remove(LOCK_FILE)
                return False
            return True
    except:
        return False

def create_lock():
    """Criar arquivo de lock"""
    lock_data = {
        "timestamp": time.time(),
        "pid": os.getpid(),
        "started": datetime.now().isoformat()
    }
    with open(LOCK_FILE, "w") as f:
        json.dump(lock_data, f)

def remove_lock():
    """Remover arquivo de lock"""
    if os.path.exists(LOCK_FILE):
        try:
            os.remove(LOCK_FILE)
        except:
            pass

def run_command(cmd: str, cwd: str = None) -> tuple[int, str, str]:
    """Executar comando e retornar exit code, stdout, stderr"""
    try:
        result = subprocess.run(
            cmd,
            shell=True,
            cwd=cwd or REPO_DIR,
            capture_output=True,
            text=True,
            timeout=300
        )
        return result.returncode, result.stdout, result.stderr
    except subprocess.TimeoutExpired:
        return 124, "", "Timeout"
    except Exception as e:
        return 1, "", str(e)

def sync():
    """Executar sincronização"""
    log_message("INFO", "Iniciando sincronização")

    # Verificar lock
    if is_locked():
        log_message("WARNING", "Sincronização já está em andamento")
        return False

    # Criar lock
    create_lock()

    try:
        # 1. Verificar se repositório existe
        if not os.path.isdir(REPO_DIR):
            log_message("ERROR", f"Repositório não encontrado: {REPO_DIR}")
            return False

        if not os.path.isdir(f"{REPO_DIR}/.git"):
            log_message("ERROR", f"Não é um repositório git: {REPO_DIR}")
            return False

        # 2. Fetch
        log_message("INFO", "Executando git fetch")
        code, out, err = run_command("git fetch origin")
        if code != 0:
            log_message("ERROR", f"git fetch falhou: {err}")
            return False
        log_message("INFO", "git fetch OK")

        # 3. Reset --hard
        log_message("INFO", f"Executando git reset --hard origin/{BRANCH}")
        code, out, err = run_command(f"git reset --hard origin/{BRANCH}")
        if code != 0:
            log_message("ERROR", f"git reset falhou: {err}")
            return False
        log_message("INFO", "git reset OK")

        # 4. Verificar HEAD
        code, commit, _ = run_command("git rev-parse HEAD")
        if code == 0:
            log_message("INFO", f"Sincronizado para commit: {commit.strip()[:10]}")

        log_message("INFO", "Sincronização concluída com sucesso")
        return True

    except Exception as e:
        log_message("ERROR", f"Erro durante sincronização: {str(e)}")
        return False
    finally:
        remove_lock()

def main():
    """Main"""
    log_message("INFO", "========== Git Auto-Sync ==========")

    success = sync()

    log_message("INFO", "========== Fim ==========")
    log_message("INFO", "")

    sys.exit(0 if success else 1)

if __name__ == "__main__":
    main()
