#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script para copiar secrets de um arquivo para o repositório destino.
Uso: python copy-secrets-from-file.py
"""

import subprocess
import os
import sys
from pathlib import Path

# Força UTF-8 no Windows
if sys.platform == 'win32':
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

# Configurações
TARGET_REPO = "fredmourao-ai/shopvivaliz-pipeline"
SECRETS_FILE = "secrets-to-copy.txt"
TOKEN = os.getenv("GH_TOKEN")

def run_command(cmd):
    """Executa comando e retorna output"""
    result = subprocess.run(cmd, shell=True, capture_output=True, text=True)
    return result.stdout.strip(), result.stderr.strip(), result.returncode

def read_secrets_from_file():
    """Lê os secrets do arquivo"""
    if not Path(SECRETS_FILE).exists():
        print(f"❌ Arquivo '{SECRETS_FILE}' não encontrado!")
        print("Execute 'python export-and-copy-secrets.py' primeiro.")
        return {}

    secrets = {}
    with open(SECRETS_FILE, "r", encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            # Pula linhas vazias e comentários
            if not line or line.startswith("#"):
                continue

            if "=" not in line:
                continue

            key, value = line.split("=", 1)
            key = key.strip()
            value = value.strip()

            if key and value and value != "VALOR_AQUI":
                secrets[key] = value

    return secrets

def create_secret(key, value):
    """Cria um secret no repositório destino"""
    cmd = f'gh secret set {key} --body "{value}" --repo {TARGET_REPO}'
    stdout, stderr, code = run_command(cmd)

    if code == 0:
        print(f"✅ {key}")
        return True
    else:
        print(f"❌ {key} - Erro: {stderr}")
        return False

def check_token():
    """Verifica se o token está configurado"""
    if not TOKEN:
        print("❌ Token não encontrado!")
        print("\nConfigure o token assim:")
        print("  $env:GH_TOKEN = 'seu_token_aqui'")
        return False
    return True

def main():
    print("📤 Copiador de Secrets para Repositório Destino")
    print("=" * 60)

    if not check_token():
        return

    secrets = read_secrets_from_file()

    if not secrets:
        print("❌ Nenhum secret encontrado no arquivo!")
        print(f"\nAbra '{SECRETS_FILE}' e preencha os valores.")
        return

    print(f"\n📋 Encontrados {len(secrets)} secrets para copiar")
    print(f"📍 Destino: {TARGET_REPO}\n")

    created = 0
    failed = 0

    for key, value in sorted(secrets.items()):
        if create_secret(key, value):
            created += 1
        else:
            failed += 1

    print("\n" + "=" * 60)
    print(f"✅ Criados: {created}")
    if failed > 0:
        print(f"❌ Falhados: {failed}")
    print("=" * 60)

    if failed == 0:
        print("\n🎉 Todos os secrets foram copiados com sucesso!")

if __name__ == "__main__":
    main()
