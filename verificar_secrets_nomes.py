#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Verificar nomes dos secrets configurados
Testa variáveis de ambiente e seus aliases
"""
import os
import sys

if sys.platform == 'win32':
    sys.stdout.reconfigure(encoding='utf-8')

# Mapa de nomes esperados com possíveis aliases
SECRETS_MAP = {
    'OPENAI_API_KEY': ['OPENAI_KEY', 'OPENAI_SECRET'],
    'ANTHROPIC_API_KEY': ['ANTHROPIC_KEY', 'ANTHROPIC_SECRET'],
    'FTP_SERVER': ['FTP_HOST', 'FTP_ADDRESS', 'FTPHOST', 'FTP_SERVER_ADDRESS'],
    'FTP_USERNAME': ['FTP_USER', 'FTP_ACCOUNT', 'FTP_LOGIN'],
    'FTP_PASSWORD': ['FTP_PASS', 'FTP_PWD', 'FTP_SECRET'],
    'FTP_PORT': ['FTPPORT', 'FTP_PORT_NUMBER'],
    'SHOPEE_PARTNER_ID': ['SHOPEE_ID', 'SHOPEE_SHOP_ID', 'SHOPEE_PARTNER_ID'],
    'SHOPEE_PARTNER_KEY': ['SHOPEE_KEY', 'SHOPEE_SECRET', 'SHOPEE_PARTNER_SECRET'],
    'TIKTOK_CLIENT_ID': ['TIKTOK_ID', 'TIKTOK_APP_ID', 'TIKTOK_CLIENT_ID'],
    'TIKTOK_CLIENT_SECRET': ['TIKTOK_SECRET', 'TIKTOK_APP_SECRET', 'TIKTOK_CLIENT_SECRET'],
    'EMAIL_FROM': ['SENDER_EMAIL', 'EMAIL_SENDER', 'MAIL_FROM'],
    'EMAIL_TO': ['RECIPIENT_EMAIL', 'EMAIL_RECIPIENT'],
    'EMAIL_USER': ['SMTP_USER', 'EMAIL_ACCOUNT', 'EMAIL_LOGIN'],
    'EMAIL_PASSWORD': ['EMAIL_PASS', 'SMTP_PASSWORD', 'EMAIL_SECRET'],
    'EMAIL_SMTP_HOST': ['SMTP_HOST', 'SMTP_SERVER'],
    'EMAIL_SMTP_PORT': ['SMTP_PORT'],
}

print("╔═══════════════════════════════════════════════════════════════════════╗")
print("║                                                                       ║")
print("║         🔍 VERIFICADOR DE SECRETS - Encontrar Nomes Reais           ║")
print("║                                                                       ║")
print("║         Verifica variáveis de ambiente e seus possíveis aliases      ║")
print("║                                                                       ║")
print("╚═══════════════════════════════════════════════════════════════════════╝")
print()

def check_secret(expected_name, aliases=None):
    """Verifica se secret existe com nome esperado ou aliases"""
    if aliases is None:
        aliases = []

    # Verificar nome esperado
    value = os.getenv(expected_name)
    if value:
        return expected_name, True, len(value)

    # Verificar aliases
    for alias in aliases:
        value = os.getenv(alias)
        if value:
            return alias, True, len(value)

    return expected_name, False, 0

print("VERIFICANDO SECRETS CONFIGURADOS...")
print("═" * 70)
print()

found_secrets = {}
missing_secrets = {}

for expected, aliases in SECRETS_MAP.items():
    actual_name, found, length = check_secret(expected, aliases)

    if found:
        found_secrets[expected] = (actual_name, length)
        status = "✅ ENCONTRADO"
        print(f"{status}")
        print(f"  Nome esperado: {expected}")
        print(f"  Nome real:     {actual_name}")
        if actual_name != expected:
            print(f"  ⚠️  NOME DIFERENTE! Precisa ser corrigido no código")
        print(f"  Tamanho:       {length} caracteres")
        print()
    else:
        missing_secrets[expected] = expected
        status = "❌ NÃO ENCONTRADO"
        print(f"{status}")
        print(f"  Nome esperado: {expected}")
        print(f"  Aliases:       {', '.join(aliases) if aliases else 'nenhum'}")
        print()

print("═" * 70)
print()
print("RESUMO:")
print(f"  ✅ Encontrados: {len(found_secrets)}")
print(f"  ❌ Faltando:    {len(missing_secrets)}")
print()

if found_secrets:
    print("SECRETS ENCONTRADOS:")
    for expected, (actual, length) in found_secrets.items():
        if actual != expected:
            print(f"  ⚠️  {expected} → {actual} (REQUER CORREÇÃO NO CÓDIGO)")
        else:
            print(f"  ✅ {actual}")
    print()

if missing_secrets:
    print("SECRETS FALTANDO:")
    for secret in missing_secrets:
        print(f"  ❌ {secret}")
    print()

print("═" * 70)
print()

# Gerar relatório de nomes diferentes
different_names = {
    expected: (actual, length)
    for expected, (actual, length) in found_secrets.items()
    if actual != expected
}

if different_names:
    print("⚠️  NOMES DIFERENTES ENCONTRADOS!")
    print()
    print("Você precisa corrigir o código para usar os nomes reais:")
    print()

    for expected, (actual, length) in different_names.items():
        print(f"Mudar em todos os scripts:")
        print(f"  os.getenv('{expected}') → os.getenv('{actual}')")
        print(f"  ou")
        print(f"  os.getenv('{expected}', ['{actual}'])")
        print()

print("═" * 70)
print()
print("📋 PRÓXIMOS PASSOS:")
print()

if different_names:
    print("1. ⚠️  CORRIGIR NOMES DE SECRETS:")
    print("   Edite os scripts para usar os nomes encontrados")
    print()

if missing_secrets:
    print(f"2. ❌ CONFIGURAR {len(missing_secrets)} SECRETS FALTANDO:")
    for secret in missing_secrets:
        print(f"   - {secret}")
    print()
    print("   Via GitHub: https://github.com/fredmourao-ai/site-shopvivaliz/settings/secrets/actions")
    print()

print("3. ✅ VALIDAR:")
print("   Rodar este script novamente para confirmar")
print()
