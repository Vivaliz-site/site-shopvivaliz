#!/usr/bin/env python3
"""
Heartbeat Executor - Garante que o executor rode mesmo se scheduler falhar
Deve rodar periodicamente (via cron na máquina local ou CI)
"""
import subprocess
import json
from datetime import datetime
from pathlib import Path

def trigger_executor():
    """Aciona o executor via GitHub API"""
    import os

    token = os.getenv('GITHUB_TOKEN')
    if not token:
        print(" GITHUB_TOKEN não configurado")
        return False

    # Acionar workflow
    cmd = [
        "gh", "workflow", "run",
        "ai-autonomous-executor.yml",
        "--repo", "fredmourao-ai/site-shopvivaliz",
        "--ref", "main"
    ]

    try:
        result = subprocess.run(cmd, capture_output=True, text=True)
        if result.returncode == 0:
            print(f" Executor acionado em {datetime.now().isoformat()}")

            # Log do heartbeat
            logfile = Path(__file__).parent.parent / "logs" / "executor-heartbeat.log"
            logfile.parent.mkdir(parents=True, exist_ok=True)

            logfile.write_text(
                f"{datetime.now().isoformat()} - Executor acionado com sucesso\n",
                mode='a'
            )
            return True
        else:
            print(f" Erro ao acionar: {result.stderr}")
            return False
    except Exception as e:
        print(f" Erro: {e}")
        return False

if __name__ == "__main__":
    trigger_executor()
