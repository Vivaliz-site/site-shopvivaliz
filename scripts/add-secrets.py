#!/usr/bin/env python3
"""Adicionar secrets no GitHub via API"""
import json
import subprocess
import sys

# Configurações
REPO = "fredmourao-ai/site-shopvivaliz"

DEFAULTS = {
    "EMAIL_SMTP_HOST": "smtp.titan.email",
    "EMAIL_SMTP_PORT": "465",
    "SMTP_HOST": "smtp.titan.email",
    "SMTP_PORT": "465",
    "EMAIL_TO": "fredmourao@gmail.com,atendimento@shopvivaliz.com.br",
}

SECRETS = [
    "SMTP_HOST",
    "SMTP_PORT",
    "SMTP_USER",
    "SMTP_PASS",
    "EMAIL_USER",
    "EMAIL_PASSWORD",
    "EMAIL_TO",
]

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
            return True, f" {name} adicionado"
        else:
            return False, f" Erro: {result.stderr}"
    except FileNotFoundError:
        return False, " GitHub CLI não instalado"

def main():
    print("🔐 Adicionando secrets no GitHub...\n")

    success_count = 0

    for name in SECRETS:
        default = DEFAULTS.get(name, "")
        prompt = f"Informe o valor para {name}"
        if default:
            prompt += f" [{default}]"
        value = input(prompt + ": ").strip() or default
        if not value:
            print(f"  Pulando {name} (valor vazio)")
            continue
        success, msg = add_secret_via_gh_cli(name, value)
        print(f"  {msg}")

        if success:
            success_count += 1

    print(f"\n{'='*50}")
    print(f" {success_count}/{len(SECRETS)} secrets adicionados com sucesso!")
    print(f"{'='*50}\n")

    print(" Secrets configurados:")
    for name in SECRETS:
        print(f"  • {name}")

    print(f"\n Sistema pronto para notificações por email!")
    if DEFAULTS["EMAIL_TO"]:
        print(f"\n📧 Destino padrão sugerido: {DEFAULTS['EMAIL_TO']}")
    print("   Configure EMAIL_USER e EMAIL_PASSWORD via input seguro ou ambiente.")

if __name__ == "__main__":
    main()
