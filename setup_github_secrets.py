#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script para configurar GitHub Secrets do repositório
Executa: python setup_github_secrets.py
"""
import subprocess
import sys
import os

if sys.platform == 'win32':
    sys.stdout.reconfigure(encoding='utf-8')

# Credenciais e valores padrão para cada secret
SECRETS_CONFIG = {
    # APIs e IA
    "OPENAI_API_KEY": {
        "description": "Chave da API OpenAI para gerar imagens",
        "default": "sk-proj-",
        "category": "🤖 IA/APIs"
    },
    "ANTHROPIC_API_KEY": {
        "description": "Chave da API Anthropic (opcional)",
        "default": "sk-ant-",
        "category": "🤖 IA/APIs"
    },

    # Shopee
    "SHOPEE_PARTNER_ID": {
        "description": "ID do partner Shopee",
        "default": "1237032",
        "category": "🛍️  Shopee"
    },
    "SHOPEE_PARTNER_KEY": {
        "description": "API Key do Shopee",
        "default": "shpk_",
        "category": "🛍️  Shopee"
    },

    # TikTok Shop
    "TIKTOK_CLIENT_ID": {
        "description": "Client ID da aplicação TikTok",
        "default": "7",
        "category": "🎵 TikTok Shop"
    },
    "TIKTOK_CLIENT_SECRET": {
        "description": "Client Secret da aplicação TikTok",
        "default": "secret_",
        "category": "🎵 TikTok Shop"
    },

    # FTP (Upload de imagens)
    "FTP_SERVER": {
        "description": "Host do servidor FTP",
        "default": "ftp.shopvivaliz.com.br",
        "category": "📤 FTP Upload"
    },
    "FTP_USERNAME": {
        "description": "Usuário FTP",
        "default": "usuario_ftp",
        "category": "📤 FTP Upload"
    },
    "FTP_PASSWORD": {
        "description": "Senha FTP",
        "default": "senha_ftp",
        "category": "📤 FTP Upload"
    },
    "FTP_PORT": {
        "description": "Porta FTP",
        "default": "21",
        "category": "📤 FTP Upload"
    },

    # Email
    "EMAIL_FROM": {
        "description": "Email remetente",
        "default": "noreply@shopvivaliz.com.br",
        "category": "📧 Email"
    },
    "EMAIL_TO": {
        "description": "Email destinatário",
        "default": "fredmourao@gmail.com",
        "category": "📧 Email"
    },
    "EMAIL_SMTP_HOST": {
        "description": "Host SMTP",
        "default": "smtp.gmail.com",
        "category": "📧 Email"
    },
    "EMAIL_SMTP_PORT": {
        "description": "Porta SMTP",
        "default": "587",
        "category": "📧 Email"
    },
    "EMAIL_USER": {
        "description": "Usuário SMTP",
        "default": "seu-email@gmail.com",
        "category": "📧 Email"
    },
    "EMAIL_PASSWORD": {
        "description": "Senha SMTP (use app password para Gmail)",
        "default": "app-password",
        "category": "📧 Email"
    },

    # Olist (integração)
    "OLIST_CLIENT_ID": {
        "description": "Client ID da Olist",
        "default": "olist_",
        "category": "📦 Olist"
    },
    "OLIST_CLIENT_SECRET": {
        "description": "Client Secret da Olist",
        "default": "secret_",
        "category": "📦 Olist"
    },

    # GitHub
    "GH_REPO_TOKEN": {
        "description": "Token GitHub para automação",
        "default": "ghp_",
        "category": "🔐 GitHub"
    },
}


def run_command(cmd):
    """Executa comando shell"""
    result = subprocess.run(cmd, shell=True, capture_output=True, text=True)
    return result.returncode, result.stdout, result.stderr


def get_current_secrets():
    """Lista secrets atuais"""
    code, stdout, stderr = run_command('gh secret list --json name')
    if code == 0:
        import json
        try:
            secrets = json.loads(stdout)
            return [s['name'] for s in secrets]
        except:
            return []
    return []


def set_secret(name, value):
    """Configura um secret no GitHub"""
    if not value or value == "VALOR_AQUI" or value.startswith(("sk-proj-", "shpk_", "secret_", "olist_", "ghp_", "app-password")):
        return False, "Valor padrão (não configurado)"

    cmd = f'gh secret set {name}'
    process = subprocess.Popen(cmd, shell=True, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    stdout, stderr = process.communicate(input=value)

    return process.returncode == 0, stdout if process.returncode == 0 else stderr


def main():
    print("""
╔═══════════════════════════════════════════════════════════════════════╗
║                                                                       ║
║           🔐 CONFIGURADOR DE GITHUB SECRETS - SHOPVIVALIZ            ║
║                                                                       ║
║              Script para configurar credenciais no GitHub            ║
║                                                                       ║
╚═══════════════════════════════════════════════════════════════════════╝
""")

    # Verificar secrets atuais
    current = get_current_secrets()
    print(f"\n📋 Secrets atuais no repositório: {len(current)}")

    print("\n📝 VALORES DE EXEMPLO (CONFIGURAR COM VALORES REAIS):\n")

    current_category = None
    for secret_name, secret_info in SECRETS_CONFIG.items():
        category = secret_info.get('category', 'Outro')

        if category != current_category:
            print(f"\n{category}")
            print("─" * 70)
            current_category = category

        status = "✅ CONFIGURADO" if secret_name in current else "⚠️  NÃO CONFIGURADO"
        print(f"{status:20} {secret_name:25} {secret_info['description']}")
        print(f"{'':20} Valor padrão: {secret_info['default']}")

    print("\n" + "="*70)
    print("\n🚀 PARA CONFIGURAR OS SECRETS:\n")
    print("OPÇÃO 1 - Via GitHub Web Interface (Recomendado):")
    print("  1. Acesse: https://github.com/fredmourao-ai/site-shopvivaliz/settings/secrets/actions")
    print("  2. Clique em 'New repository secret'")
    print("  3. Adicione cada credencial\n")

    print("OPÇÃO 2 - Via CLI (Automático):")
    print("  $ gh secret set SHOPEE_PARTNER_ID")
    print("  $ gh secret set SHOPEE_PARTNER_KEY")
    print("  $ gh secret set TIKTOK_CLIENT_ID")
    print("  $ gh secret set OPENAI_API_KEY")
    print("  $ gh secret set FTP_SERVER")
    print("  $ etc...\n")

    print("="*70)
    print("\n✨ DEPOIS DE CONFIGURAR OS SECRETS:")
    print("  $ cd scripts/")
    print("  $ python main_advanced.py")
    print("\nO pipeline começará automaticamente!")
    print("="*70)


if __name__ == "__main__":
    main()
