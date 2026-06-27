#!/usr/bin/env python3
"""Adicionar secrets no GitHub via API"""
import json
import subprocess
import sys

# Configurações
REPO = "fredmourao-ai/site-shopvivaliz"
SECRETS = {
    "EMAIL_USER": "agentes@shopvivaliz.com.br",
    "EMAIL_PASSWORD": "Zoomp123-",
    "EMAIL_SMTP_HOST": "smtp.titan.email",
    "EMAIL_SMTP_PORT": "465",
    "EMAIL_TO": "fredmourao@gmail.com"
}

def add_secret_via_gh_cli(name, value):
    """Adicionar secret usando GitHub CLI"""
    try:
        cmd = [
            "gh", "secret", "set", name,
            "--repo", REPO,
            "--body", value
        ]
        result = subprocess.run(cmd, capture_output=True, text=True)

        if result.returncode == 0:
            return True, f"✅ {name} adicionado"
        else:
            return False, f"❌ Erro: {result.stderr}"
    except FileNotFoundError:
        return False, "❌ GitHub CLI não instalado"

def main():
    print("🔐 Adicionando secrets no GitHub...\n")

    success_count = 0

    for name, value in SECRETS.items():
        success, msg = add_secret_via_gh_cli(name, value)
        print(f"  {msg}")

        if success:
            success_count += 1

    print(f"\n{'='*50}")
    print(f"✅ {success_count}/{len(SECRETS)} secrets adicionados com sucesso!")
    print(f"{'='*50}\n")

    print("📊 Secrets configurados:")
    for name in SECRETS.keys():
        print(f"  • {name}")

    print(f"\n🎯 Sistema pronto para notificações por email!")
    print(f"\n📧 Emails serão enviados para: fredmourao@gmail.com")
    print(f"   Usando: agentes@shopvivaliz.com.br")

if __name__ == "__main__":
    main()
