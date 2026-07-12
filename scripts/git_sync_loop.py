import os
import subprocess
import time
import datetime
import sys

REPO_DIR = r"c:\site-shopvivaliz"

def log(msg):
    ts = datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    print(f"[{ts}] {msg}")
    sys.stdout.flush()

def run_cmd(cmd):
    try:
        result = subprocess.run(cmd, cwd=REPO_DIR, capture_output=True, text=True, check=False)
        return result
    except Exception as e:
        log(f"Erro ao executar {cmd}: {e}")
        return None

def sync():
    log("🔄 Iniciando sincronização (Auto Sync)...")
    
    # 1. Check for changes
    status = run_cmd(["git", "status", "--porcelain"])
    if status and status.stdout.strip():
        log("⚠️ Mudanças locais detectadas. Commitando...")
        run_cmd(["git", "add", "-A"])
        commit_msg = f"auto: sincronizar mudanças {datetime.datetime.now().strftime('%H:%M:%S')}"
        run_cmd(["git", "commit", "-m", commit_msg])
    else:
        log("✓ Sem mudanças locais.")

    # 2. Pull from origin main (or current branch)
    # Get current branch
    branch_res = run_cmd(["git", "rev-parse", "--abbrev-ref", "HEAD"])
    branch = branch_res.stdout.strip() if branch_res else "main"
    
    log(f"⬇️ Fazendo git pull origin {branch}...")
    pull_res = run_cmd(["git", "pull", "origin", branch])
    if pull_res and pull_res.returncode != 0:
        log(f"⚠️ Aviso no git pull: {pull_res.stderr.strip()}")
    
    # 3. Push to origin
    log(f"⬆️ Fazendo git push origin {branch}...")
    push_res = run_cmd(["git", "push", "origin", branch])
    if push_res and push_res.returncode == 0:
        log("✅ Push realizado com sucesso.")
    else:
        log(f"❌ Erro ao fazer push: {push_res.stderr.strip() if push_res else 'unknown'}")

    log("✅ Sincronização concluída.")

if __name__ == "__main__":
    log("🚀 Auto-Sync Python Iniciado!")
    interval_minutes = 5
    while True:
        sync()
        log(f"⏰ Próxima sincronização em {interval_minutes} minuto(s)...")
        time.sleep(interval_minutes * 60)
