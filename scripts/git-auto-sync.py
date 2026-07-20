#!/usr/bin/env python3
"""
Git Auto-Sync Daemon (SEGURO)
Executado via cron a cada 2 minutos na VM Oracle.

CRÍTICO: Este daemon não usa reset destrutivo de Git em produção.
Usa git fetch + git merge --ff-only que é seguro.

Regras obrigatórias:
1. Valida working tree antes de qualquer operação git
2. Rejeita merge se não é Fast-Forward
3. Registra SHA completo para auditoria
4. Falha rápido com mensagens claras
5. Protege dados operacionais em .gitignore
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

# Arquivos que NÃO devem ser commitados (proteção de dados). Eles continuam
# bloqueando sync se estiverem sujos: a política atual exige árvore limpa antes
# de fetch/merge para não descartar runtime sem revisão humana.
PROTECTED_FILES = [
    ".git-sync.lock",
    ".agent-heartbeats/",
    "storage/orders/",
    "storage/codex-bridge/state.json",
    "storage/orchestrator/queue.json",
    "tasks-queue.json",
]

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

def _is_protected_path(path: str) -> bool:
    """Verifica se um caminho de arquivo esta na lista PROTECTED_FILES
    (prefixo de diretorio ou nome exato de arquivo)."""
    return any(path == p or path.startswith(p) for p in PROTECTED_FILES)

def check_working_tree():
    """Validar working tree antes de operações git.

    Qualquer mudança bloqueia o sync automático. Mesmo runtime protegido deve
    ser preservado por desenho operacional, nunca descartado automaticamente.
    """
    code, out, err = run_command("git status --porcelain")
    if code != 0:
        log_message("ERROR", f"Não conseguiu verificar status: {err}")
        return False, "git status falhou"

    if not out.strip():
        return True, "OK"

    log_message("WARNING", f"Working tree sujo, rejeitando sync seguro:\n{out.strip()}")
    return False, "working tree sujo"

def get_current_sha():
    """Obter SHA completo do HEAD"""
    code, sha, err = run_command("git rev-parse HEAD")
    if code == 0:
        return sha.strip()
    return None

def sync():
    """Executar sincronização segura sem reset destrutivo."""
    log_message("INFO", "========== Git Auto-Sync (SEGURO) ==========")

    # Verificar lock
    if is_locked():
        log_message("WARNING", "Sincronização já está em andamento")
        return False

    # Criar lock
    create_lock()

    try:
        # 1. Verificar repositório
        if not os.path.isdir(REPO_DIR):
            log_message("ERROR", f"Repositório não encontrado: {REPO_DIR}")
            return False

        if not os.path.isdir(f"{REPO_DIR}/.git"):
            log_message("ERROR", f"Não é um repositório git: {REPO_DIR}")
            return False

        # 2. Registrar estado ANTES
        sha_before = get_current_sha()
        log_message("INFO", f"SHA antes: {sha_before}")

        # 3. Validar working tree antes de qualquer fetch/merge
        is_clean, status = check_working_tree()
        if not is_clean:
            log_message("ERROR", f"Working tree inválida: {status}")
            return False

        # 4. Fetch (seguro - só puxar)
        log_message("INFO", "Executando git fetch origin")
        code, out, err = run_command("git fetch origin")
        if code != 0:
            log_message("ERROR", f"git fetch falhou: {err}")
            return False
        log_message("INFO", "git fetch OK")

        # 5. Merge --ff-only (seguro - rejeita se não é Fast-Forward)
        log_message("INFO", f"Executando git merge --ff-only origin/{BRANCH}")
        code, out, err = run_command(f"git merge --ff-only origin/{BRANCH}")

        if code != 0:
            if "is not an ancestor of HEAD" in err or "merge conflict" in err.lower():
                log_message("ERROR", f"Branch divergiu ou há conflito: {err}")
                return False
            elif "Already up to date" in out or "Already up to date" in err:
                log_message("INFO", "Já está sincronizado com origin")
                return True
            else:
                log_message("ERROR", f"git merge falhou: {err}")
                return False

        log_message("INFO", "git merge OK")

        # 6. Registrar estado DEPOIS
        sha_after = get_current_sha()
        log_message("INFO", f"SHA depois: {sha_after}")

        # 7. Verificar se mudou
        if sha_before != sha_after:
            log_message("INFO", f"Sincronizado de {sha_before[:10]} para {sha_after[:10]}")
        else:
            log_message("INFO", "Sem novas mudanças")

        log_message("INFO", "Sincronização concluída com sucesso")
        return True

    except Exception as e:
        log_message("ERROR", f"Erro durante sincronização: {str(e)}")
        return False
    finally:
        remove_lock()

def main():
    """Main"""
    success = sync()
    log_message("INFO", "========== Fim ==========")
    log_message("INFO", "")

    # Retornar código apropriado
    sys.exit(0 if success else 1)

if __name__ == "__main__":
    main()
