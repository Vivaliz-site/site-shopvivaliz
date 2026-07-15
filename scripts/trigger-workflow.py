#!/usr/bin/env python3
"""
Acionar workflow do GitHub Actions manualmente
"""
import os
import subprocess

owner = "fredmourao-ai"
repo = "site-shopvivaliz"
workflow = "ecommerce-multi-ai-build-24-7.yml"
branch = "main"

print(f"""
[TRIGGER] Disparando workflow: {workflow}
[REPO] {owner}/{repo}
[BRANCH] {branch}
""")

# Usar gh CLI se disponível
try:
    cmd = [
        "gh",
        "workflow",
        "run",
        workflow,
        "-r",
        branch,
        "-f", "pages=all"
    ]
    result = subprocess.run(cmd, capture_output=True, text=True, check=True)
    print(f"[OK] Workflow disparado com sucesso!")
    print(result.stdout)
except FileNotFoundError:
    print("[ERRO] 'gh' CLI não encontrado")
    print("\nTente instalar: https://cli.github.com")
except subprocess.CalledProcessError as e:
    print(f"[ERRO] {e.stderr}")
