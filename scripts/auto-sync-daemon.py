#!/usr/bin/env python3
"""Auto-sync daemon - sincroniza repositório a cada 30 segundos (desenvolvimento) ou 30 minutos (produção)."""
import subprocess
import time
import sys
from pathlib import Path
from datetime import datetime

REPO_DIR = Path(__file__).parent.parent
SYNC_INTERVAL = 30  # segundos em dev, 1800 em prod (30 min)
LOG_FILE = REPO_DIR / "logs" / "auto-sync-daemon.log"

def log(msg: str):
    """Log com timestamp."""
    ts = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    log_msg = f"[{ts}] {msg}"
    try:
        print(log_msg)
    except UnicodeEncodeError:
        print(log_msg.encode('utf-8', 'ignore').decode('utf-8'))
    LOG_FILE.parent.mkdir(exist_ok=True)
    with open(LOG_FILE, "a", encoding="utf-8") as f:
        f.write(log_msg + "\n")

def run_cmd(cmd: str, desc: str = "") -> bool:
    """Executa comando e retorna sucesso."""
    try:
        result = subprocess.run(cmd, shell=True, cwd=REPO_DIR, capture_output=True, text=True, timeout=30)
        if result.returncode != 0:
            log(f"[FAIL] {desc}: {result.stderr[:200]}")
            return False
        log(f"[OK] {desc}")
        return True
    except Exception as e:
        log(f"[FAIL] {desc}: {str(e)[:100]}")
        return False

def sync_cycle():
    """Uma iteração de sincronização."""
    log("🔄 Iniciando ciclo de sync...")

    # 1. Pull para trazer mudanças da outra estação
    if run_cmd("git fetch origin && git pull origin $(git rev-parse --abbrev-ref HEAD) --no-edit", "Pull remoto"):
        # 2. Commit pendências locais
        run_cmd("git add -A && git diff --cached --quiet || git commit -m \"auto: sync $(date '+%Y-%m-%d %H:%M:%S')\"", "Auto-commit local")

        # 3. Push para compartilhar com outra estação
        run_cmd("git push origin $(git rev-parse --abbrev-ref HEAD) --no-verify", "Push remoto")

    log("✅ Ciclo concluído\n")

def main():
    """Daemon contínuo."""
    log("🚀 Auto-sync daemon iniciado (intervalo: {}s)".format(SYNC_INTERVAL))

    try:
        while True:
            sync_cycle()
            time.sleep(SYNC_INTERVAL)
    except KeyboardInterrupt:
        log("⛔ Daemon parado pelo usuário")
        sys.exit(0)
    except Exception as e:
        log(f"💥 Erro fatal: {e}")
        sys.exit(1)

if __name__ == "__main__":
    main()
