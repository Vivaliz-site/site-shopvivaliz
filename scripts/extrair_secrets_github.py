#!/usr/bin/env python3
"""
Script para extrair secrets do GitHub e adicionar ao .env.local

Uso:
    python3 scripts/extrair_secrets_github.py
"""

import subprocess
import sys
from pathlib import Path

def get_github_secrets():
    """Extrai secrets do GitHub usando gh CLI."""
    try:
        # Verificar se gh CLI está instalado
        result = subprocess.run(
            ["gh", "secret", "list"],
            capture_output=True,
            text=True,
            check=False
        )

        if result.returncode != 0:
            print("❌ GitHub CLI não está disponível ou não autenticado")
            print("   Instale com: choco install gh")
            print("   Ou configure com: gh auth login")
            return None

        secrets = {}
        for line in result.stdout.strip().split('\n'):
            if line:
                parts = line.split()
                if len(parts) >= 2:
                    secret_name = parts[0]
                    secrets[secret_name] = f"(do GitHub)"  # Não conseguimos ler o valor por segurança

        return secrets

    except FileNotFoundError:
        print("❌ GitHub CLI (gh) não está instalado")
        return None
    except Exception as e:
        print(f"❌ Erro ao conectar ao GitHub: {e}")
        return None

def extract_from_workflows():
    """Extrai valores de exemplo dos workflows."""
    print("\n📋 Buscando valores nos arquivos do projeto...\n")

    secrets_found = {}

    # Procurar em .github/workflows
    for workflow_file in Path(".github/workflows").glob("*.yml"):
        content = workflow_file.read_text()
        if "FTP_SERVER" in content or "FTP_USERNAME" in content:
            print(f"✓ Workflow encontrado: {workflow_file.name}")

    # Procurar em scripts Python
    for script_file in Path("scripts").glob("*.py"):
        content = script_file.read_text()
        if "FTP_HOST" in content or "SMTP_HOST" in content:
            print(f"✓ Script encontrado: {script_file.name}")

    return secrets_found

def main():
    print("🔐 Extrator de Secrets - GitHub ↔ .env.local")
    print("=" * 60)

    # Tentar extrair do GitHub
    gh_secrets = get_github_secrets()

    if gh_secrets:
        print("\n✅ Secrets disponíveis no GitHub:\n")
        for key in sorted(gh_secrets.keys()):
            if any(x in key for x in ["FTP", "SMTP", "MAIL", "EMAIL"]):
                print(f"  • {key}")
    else:
        print("\n⚠️  Não foi possível acessar GitHub Secrets")

    # Buscar em arquivos do projeto
    extract_from_workflows()

    print("\n" + "=" * 60)
    print("📝 Próximas ações:\n")
    print("  1. Ir para: https://github.com/Vivaliz-site/site-shopvivaliz/settings/secrets/actions")
    print("  2. Ver valores de: FTP_SERVER, FTP_USERNAME, FTP_PASSWORD, FTP_REMOTE_DIR")
    print("  3. Copiar e colar aqui ou em .env.local")
    print("\n  Ou use: gh secret get FTP_SERVER")

if __name__ == "__main__":
    main()
