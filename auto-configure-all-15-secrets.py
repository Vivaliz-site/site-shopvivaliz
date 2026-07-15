#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script para configurar TODOS os 15 GitHub Secrets automaticamente
Sem pedir entrada - configura todos os itens
"""
import subprocess
import sys
import os

if sys.platform == 'win32':
    sys.stdout.reconfigure(encoding='utf-8')

# TODOS os 15 secrets com seus valores
# ALTERE OS VALORES ABAIXO COM SUS CREDENCIAIS REAIS
SECRETS = {
    # IA/APIs - ALTERE COM SUAS CREDENCIAIS
    "OPENAI_API_KEY": os.getenv("OPENAI_API_KEY", "sk-proj-seu-valor-aqui"),
    "ANTHROPIC_API_KEY": os.getenv("ANTHROPIC_API_KEY", "sk-ant-seu-valor-aqui"),

    # Shopee - ALTERE COM SUAS CREDENCIAIS
    "SHOPEE_PARTNER_ID": os.getenv("SHOPEE_PARTNER_ID", "1237032"),
    "SHOPEE_PARTNER_KEY": os.getenv("SHOPEE_PARTNER_KEY", "shpk_seu-valor-aqui"),

    # TikTok - ALTERE COM SUAS CREDENCIAIS
    "TIKTOK_CLIENT_ID": os.getenv("TIKTOK_CLIENT_ID", "7seu-valor-aqui"),
    "TIKTOK_CLIENT_SECRET": os.getenv("TIKTOK_CLIENT_SECRET", "secret_seu-valor-aqui"),

    # FTP - ALTERE COM SUAS CREDENCIAIS
    "FTP_SERVER": os.getenv("FTP_SERVER", "ftp.shopvivaliz.com.br"),
    "FTP_USERNAME": os.getenv("FTP_USERNAME", "usuario_ftp"),
    "FTP_PASSWORD": os.getenv("FTP_PASSWORD", "senha_ftp_aqui"),
    "FTP_PORT": os.getenv("FTP_PORT", "21"),

    # Email - ALTERE COM SUAS CREDENCIAIS
    "EMAIL_FROM": os.getenv("EMAIL_FROM", "noreply@shopvivaliz.com.br"),
    "EMAIL_TO": os.getenv("EMAIL_TO", "fredmourao@gmail.com"),
    "EMAIL_SMTP_HOST": os.getenv("EMAIL_SMTP_HOST", "smtp.gmail.com"),
    "EMAIL_SMTP_PORT": os.getenv("EMAIL_SMTP_PORT", "587"),
    "EMAIL_USER": os.getenv("EMAIL_USER", "seu-email@gmail.com"),
    "EMAIL_PASSWORD": os.getenv("EMAIL_PASSWORD", "app-password-aqui"),
}

print("""
╔═══════════════════════════════════════════════════════════════════════╗
║                                                                       ║
║     🔐 CONFIGURADOR AUTOMÁTICO - TODOS OS 15 GITHUB SECRETS          ║
║                                                                       ║
║     Configurando automaticamente TODOS os items sem parar            ║
║                                                                       ║
╚═══════════════════════════════════════════════════════════════════════╝
""")

print("\n⚠️  IMPORTANTE: Edite o script com suas credenciais REAIS!")
print("\nValores lidos do script ou variáveis de ambiente:")
print("─" * 70)

for i, (name, value) in enumerate(SECRETS.items(), 1):
    # Mostrar apenas primeiros caracteres por segurança
    if len(value) > 10:
        display_value = value[:5] + "..." + value[-3:]
    else:
        display_value = value

    print(f"{i:2}. {name:25} = {display_value}")

print("─" * 70)

print("\n📤 CONFIGURANDO TODOS OS 15 SECRETS NO GITHUB...")
print("═" * 70 + "\n")

configured = 0
failed = 0
skipped = 0

for secret_name, secret_value in SECRETS.items():
    # Verificar se valor é o padrão (não foi configurado)
    if secret_value.endswith("-aqui") or secret_value.endswith("aqui"):
        print(f"⏭️  {secret_name:30} PULADO (valor padrão não alterado)")
        skipped += 1
        continue

    print(f"⏳ {secret_name:30} Configurando...", end=" ", flush=True)

    try:
        # Usar gh secret set
        process = subprocess.Popen(
            ["gh", "secret", "set", secret_name,
             "--repo", "fredmourao-ai/site-shopvivaliz"],
            stdin=subprocess.PIPE,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True
        )

        stdout, stderr = process.communicate(input=secret_value)

        if process.returncode == 0:
            print("✅ CONFIGURADO")
            configured += 1
        else:
            print(f"❌ ERRO: {stderr}")
            failed += 1
    except Exception as e:
        print(f"❌ ERRO: {e}")
        failed += 1

print("\n" + "═" * 70)
print("\n📊 RESULTADO FINAL:")
print(f"   ✅ Configurados: {configured}")
print(f"   ⏭️  Pulados (padrão): {skipped}")
print(f"   ❌ Erros: {failed}")
print(f"   📊 Total: {configured + skipped + failed}/15")

if configured > 0:
    print("\n✅ PRÓXIMO PASSO:")
    print("   1. Edite o script com suas credenciais REAIS")
    print("   2. Execute novamente para configurar todos")
    print("   3. Ou configure manualmente em:")
    print("      https://github.com/fredmourao-ai/site-shopvivaliz/settings/secrets/actions")
    print("\n4. Fazer push:")
    print("   $ git push origin main")
    print("\n5. Sistema começará automaticamente!")

print("\n" + "═" * 70)

# Listar secrets configurados
print("\n📋 SECRETS CONFIGURADOS NO GITHUB:")
print("─" * 70)

try:
    result = subprocess.run(
        ["gh", "secret", "list", "--repo", "fredmourao-ai/site-shopvivaliz"],
        capture_output=True,
        text=True
    )

    if result.returncode == 0:
        print(result.stdout)
    else:
        print("❌ Erro ao listar secrets. Verifique manualmente em:")
        print("   https://github.com/fredmourao-ai/site-shopvivaliz/settings/secrets/actions")
except Exception as e:
    print(f"❌ Erro: {e}")

print("═" * 70)
print("\n✨ Para usar com valores reais, edite este arquivo e configure:")
print("   OPENAI_API_KEY, SHOPEE_PARTNER_ID, TIKTOK_CLIENT_ID, etc.")
print("\n" + "═" * 70)
