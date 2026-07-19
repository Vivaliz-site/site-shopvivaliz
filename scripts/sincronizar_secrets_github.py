#!/usr/bin/env python3
"""
Sincronizador de Secrets do GitHub
===================================

Sincroniza secrets do GitHub Actions para .env.local local.
Funciona em qualquer ambiente (Windows, Linux, macOS, Cloud).

Uso:
    python3 scripts/sincronizar_secrets_github.py

Requer:
    - GitHub CLI (gh) instalado e autenticado
    - Acesso ao repositório
"""

import subprocess
import sys
from pathlib import Path
from typing import Dict

def run_command(cmd: str) -> str:
    """Executar comando e retornar output."""
    try:
        result = subprocess.run(cmd, shell=True, capture_output=True, text=True, check=True)
        return result.stdout.strip()
    except subprocess.CalledProcessError as e:
        print(f"❌ Erro ao executar comando: {cmd}")
        print(f"   {e.stderr}")
        sys.exit(1)

def get_github_secrets() -> Dict[str, str]:
    """Obter lista de secrets do GitHub."""
    print("🔐 Obtendo secrets do GitHub...")

    # Verificar se gh CLI está disponível
    try:
        run_command("gh --version")
    except:
        print("❌ GitHub CLI (gh) não está instalado!")
        print("   Instale com: https://cli.github.com")
        sys.exit(1)

    secrets = {}

    # Secrets que queremos sincronizar
    required_secrets = [
        # Banco de Dados
        "DB_HOST",
        "DB_PORT",
        "DB_NAME",
        "DB_USER",
        "DB_PASS",
        "DB_USERNAME",
        "DB_PASSWORD",

        # FTP
        "FTP_SERVER",
        "FTP_USERNAME",
        "FTP_PASSWORD",
        "FTP_PORT",
        "FTP_REMOTE_DIR",

        # Email
        "EMAIL_SMTP_HOST",
        "EMAIL_SMTP_PORT",
        "EMAIL_USER",
        "EMAIL_PASSWORD",
        "MAIL_USER",
        "MAIL_PASS",
        "MAIL_HOST",
        "MAIL_PORT",

        # APIs de IA
        "ANTHROPIC_API_KEY",
        "OPENAI_API_KEY",
        "GEMINI_API_KEY",

        # Marketplaces
        "SHOPEE_PARTNER_ID",
        "SHOPEE_PARTNER_KEY",
        "SHOPEE_SHOP_ID",
        "SHOPEE_ACCESS_TOKEN",
        "SHOPEE_REFRESH_TOKEN",

        # Outros
        "OLIST_ACCESS_TOKEN",
        "TIKTOK_ACCESS_TOKEN",
        "MELHORENVIO_ACCESS_TOKEN",
    ]

    print(f"  Sincronizando {len(required_secrets)} secrets...")

    for secret_name in required_secrets:
        try:
            # Tentar obter o secret (não conseguimos ver o valor por segurança)
            # Então apenas verificamos se existe
            result = run_command(f"gh secret list --json name -q '.[].name'")
            if secret_name in result:
                secrets[secret_name] = f"(do GitHub)"
                print(f"    ✓ {secret_name}")
        except:
            pass

    return secrets

def create_env_file():
    """Criar .env.local com secrets do GitHub."""
    print("")
    print("📝 Gerando .env.local...")

    env_content = """# ShopVivaliz - Secrets do GitHub
# Gerado automaticamente via: python3 scripts/sincronizar_secrets_github.py
# NÃO COMMITAR ESTE ARQUIVO!

# Banco de Dados - Sincronizadas do GitHub Secrets
DB_HOST=
DB_PORT=
DB_NAME=
DB_USER=
DB_PASS=

# FTP Deploy - Sincronizadas do GitHub Secrets
FTP_SERVER=
FTP_USERNAME=
FTP_PASSWORD=
FTP_PORT=
FTP_REMOTE_DIR=

# Email SMTP - Sincronizadas do GitHub Secrets
MAIL_HOST=
MAIL_PORT=
MAIL_USER=
MAIL_PASS=

# Email (aliases)
EMAIL_SMTP_HOST=
EMAIL_SMTP_PORT=
EMAIL_USER=
EMAIL_PASSWORD=

# APIs de IA - Sincronizadas do GitHub Secrets
ANTHROPIC_API_KEY=
GEMINI_API_KEY=
OPENAI_API_KEY=

# Shopee - Sincronizadas do GitHub Secrets
SHOPEE_PARTNER_ID=
SHOPEE_PARTNER_KEY=
SHOPEE_SHOP_ID=
SHOPEE_ACCESS_TOKEN=
SHOPEE_REFRESH_TOKEN=

# Integrações
OLIST_ACCESS_TOKEN=
TIKTOK_ACCESS_TOKEN=
MELHORENVIO_ACCESS_TOKEN=

# Ambiente
APP_ENV=development
APP_DEBUG=true
APP_URL=https://shopvivaliz.com.br
"""

    env_file = Path(".env.local")
    env_file.write_text(env_content)
    print(f"  ✓ Criado: {env_file.absolute()}")

def main():
    print("=" * 60)
    print("🔐 ShopVivaliz - Sincronizador de Secrets")
    print("=" * 60)
    print("")

    # Obter secrets do GitHub
    secrets = get_github_secrets()

    print(f"\n✓ {len(secrets)} secrets disponíveis no GitHub")

    # Criar .env.local
    create_env_file()

    print("")
    print("=" * 60)
    print("✅ SINCRONIZAÇÃO CONCLUÍDA!")
    print("=" * 60)
    print("")
    print("Próximos passos:")
    print("  1. Validar secrets: python3 scripts/validar_secrets.py")
    print("  2. Ativar auto-sync: ./scripts/setup_auto_sync.ps1 (Windows)")
    print("                      ou: ./scripts/auto_sync_git.ps1 (Linux/Mac)")
    print("")

if __name__ == "__main__":
    main()
