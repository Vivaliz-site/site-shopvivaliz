import time
import subprocess
from datetime import datetime

LOOP_INTERVAL = 60  # segundos

def log(msg):
    print(f"[{datetime.utcnow().isoformat()}] {msg}")

def run_executor():
    try:
        result = subprocess.run(
            ["python", "scripts/autonomous-executor.py"],
            capture_output=True,
            text=True,
            timeout=900
        )

        log(result.stdout)

        if result.returncode != 0:
            log(f"Erro executor: {result.stderr}")

    except Exception as e:
        log(f"Falha geral: {e}")

def health_check():
    try:
        subprocess.run(["git", "status"], capture_output=True)
        return True
    except:
        return False

def main():
    log("🚀 AI Orchestrator iniciado (modo 24/7)")

    while True:
        log("🔄 Novo ciclo iniciado")

        if not health_check():
            log("❌ Falha de saúde do sistema")
            time.sleep(30)
            continue

        run_executor()

        log(f"⏳ Aguardando {LOOP_INTERVAL}s para próximo ciclo")
        time.sleep(LOOP_INTERVAL)

if __name__ == "__main__":
    main()