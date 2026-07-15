#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script para exportar secrets do GitHub e copiar para outro repositório.
Uso: python export-and-copy-secrets.py
"""

import subprocess
import json
import os
import sys
from pathlib import Path

# Força UTF-8 no Windows
if sys.platform == 'win32':
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

# Configurações
SOURCE_REPO = "fredmourao-ai/site-shopvivaliz"
TARGET_REPO = "fredmourao-ai/shopvivaliz-pipeline"
TOKEN = os.getenv("GH_TOKEN")

def run_command(cmd):
    """Executa comando e retorna output"""
    result = subprocess.run(cmd, shell=True, capture_output=True, text=True)
    return result.stdout.strip(), result.stderr.strip(), result.returncode

def get_secrets(repo):
    """Lista todos os secrets de um repositório"""
    cmd = f'gh secret list --repo {repo} --json name,updatedAt'
    stdout, stderr, code = run_command(cmd)

    if code != 0:
        print(f"❌ Erro ao listar secrets: {stderr}")
        return []

    try:
        secrets = json.loads(stdout)
        return [s['name'] for s in secrets]
    except:
        # Fallback para parsing manual
        lines = stdout.split('\n')
        secrets = []
        for line in lines:
            if line.strip():
                name = line.split()[0]
                secrets.append(name)
        return secrets

def create_secrets_file():
    """Cria um arquivo de template para preencher os valores"""
    source_secrets = get_secrets(SOURCE_REPO)

    print(f"\n📋 Encontrados {len(source_secrets)} secrets no {SOURCE_REPO}:")
    print("=" * 60)

    for secret in sorted(source_secrets):
        print(f"  • {secret}")

    print("\n" + "=" * 60)
    print("\n📝 Criando arquivo 'secrets-to-copy.txt' com template...")

    template = "# Preencha os valores dos secrets abaixo\n"
    template += "# Formato: CHAVE=VALOR\n"
    template += "# Remova as linhas que já existem no repositório destino\n\n"

    for secret in sorted(source_secrets):
        template += f"{secret}=VALOR_AQUI\n"

    with open("secrets-to-copy.txt", "w", encoding="utf-8") as f:
        f.write(template)

    print("✅ Arquivo 'secrets-to-copy.txt' criado!")
    print("\n📌 Próximos passos:")
    print("1. Abra o arquivo 'secrets-to-copy.txt'")
    print("2. Preencha os valores reais de cada secret")
    print("3. Salve o arquivo")
    print("4. Execute: python copy-secrets-from-file.py")

def check_token():
    """Verifica se o token está configurado"""
    if not TOKEN:
        print("❌ Token não encontrado!")
        print("\nConfigure o token assim:")
        print("  $env:GH_TOKEN = 'seu_token_aqui'")
        return False
    return True

def main():
    print("🚀 Exportador de Secrets GitHub")
    print("=" * 60)

    if not check_token():
        return

    create_secrets_file()

if __name__ == "__main__":
    main()
