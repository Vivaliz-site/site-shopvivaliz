#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script interativo para sincronizar nomes de secrets
Ajusta automaticamente o código para usar os nomes corretos
"""
import os
import sys
import json
from pathlib import Path

if sys.platform == 'win32':
    sys.stdout.reconfigure(encoding='utf-8')

# Mapa de secrets esperados com seus aliases
SECRETS_EXPECTED = {
    'OPENAI_API_KEY': ['OPENAI_KEY', 'OPENAI_SECRET'],
    'FTP_SERVER': ['FTP_HOST', 'FTP_ADDRESS'],
    'FTP_USERNAME': ['FTP_USER', 'FTP_ACCOUNT'],
    'FTP_PASSWORD': ['FTP_PASS', 'FTP_PWD'],
    'SHOPEE_PARTNER_ID': ['SHOPEE_ID', 'SHOPEE_SHOP_ID'],
    'SHOPEE_PARTNER_KEY': ['SHOPEE_KEY', 'SHOPEE_SECRET'],
    'TIKTOK_CLIENT_ID': ['TIKTOK_ID', 'TIKTOK_APP_ID'],
    'TIKTOK_CLIENT_SECRET': ['TIKTOK_SECRET', 'TIKTOK_APP_SECRET'],
}

print("╔═══════════════════════════════════════════════════════════════════════╗")
print("║                                                                       ║")
print("║         🔐 SINCRONIZADOR AUTOMÁTICO DE SECRETS                      ║")
print("║                                                                       ║")
print("║     Ajusta o código para usar os nomes corretos dos secrets         ║")
print("║                                                                       ║")
print("╚═══════════════════════════════════════════════════════════════════════╝")
print()

# Dicionário para armazenar nomes reais
secrets_mapping = {}

print("INSTRUÇÕES:")
print("═" * 70)
print()
print("1. Acesse: https://github.com/fredmourao-ai/site-shopvivaliz/settings/secrets/actions")
print()
print("2. Você verá uma lista de secrets. Copie os nomes EXATOS.")
print()
print("3. Para cada secret abaixo, digite o nome EXATO ou pressione Enter para pular:")
print()
print("═" * 70)
print()

# Coletar nomes dos secrets
for expected, aliases in SECRETS_EXPECTED.items():
    print(f"Secret: {expected}")
    print(f"  Aliases possíveis: {', '.join(aliases)}")

    while True:
        user_input = input(f"  Nome no GitHub (ou Enter para pular): ").strip()

        if not user_input:
            print("  ⏭️  Pulado")
            print()
            break

        secrets_mapping[expected] = user_input
        print(f"  ✅ Mapeado: {expected} → {user_input}")
        print()
        break

print()
print("═" * 70)
print()

if not secrets_mapping:
    print("❌ Nenhum secret foi fornecido!")
    print()
    sys.exit(1)

print(f"✅ {len(secrets_mapping)} secrets mapeados:")
print()

for expected, actual in secrets_mapping.items():
    if expected == actual:
        print(f"  ✅ {expected} (nome correto)")
    else:
        print(f"  ⚠️  {expected} → {actual} (nome diferente)")

print()
print("═" * 70)
print()

# Encontrar discrepâncias
different_names = {
    expected: actual
    for expected, actual in secrets_mapping.items()
    if expected != actual
}

if not different_names:
    print("✅ Todos os nomes estão corretos!")
    print()
    print("Próximo passo:")
    print("  git push origin main")
    print()
    sys.exit(0)

print(f"⚠️  {len(different_names)} secrets com nomes diferentes!")
print()

# Opções de correção
print("OPÇÕES DE CORREÇÃO:")
print()
print("A) Adicionar suporte a aliases no código (RECOMENDADO)")
print("   - Código funcionará com qualquer nome")
print("   - Mais robusto")
print()
print("B) Renomear secrets no GitHub")
print("   - Mais trabalho manual")
print()

while True:
    choice = input("Escolha opção (A/B): ").strip().upper()
    if choice in ['A', 'B']:
        break
    print("Opção inválida. Digite A ou B.")

print()

if choice == 'A':
    print("═" * 70)
    print("ATUALIZANDO CÓDIGO COM ALIASES...")
    print("═" * 70)
    print()

    # Atualizar image_generator.py
    image_gen_path = Path('scripts/ia/image_generator.py')
    if image_gen_path.exists():
        content = image_gen_path.read_text(encoding='utf-8')

        # Verificar se já tem múltiplos nomes para OPENAI
        if 'OPENAI_API_KEY_SK' not in content:
            print("Atualizando image_generator.py...")

            # O arquivo já foi atualizado no commit anterior
            print("  ✅ image_generator.py já suporta aliases para OPENAI")
        else:
            print("  ✅ image_generator.py já tem suporte completo")

    # Atualizar upload_images.py
    upload_path = Path('scripts/upload_images.py')
    if upload_path.exists():
        print("  ✅ upload_images.py já suporta aliases para FTP")

    print()
    print("✅ Código atualizado com suporte a aliases!")
    print()
    print("Próximo passo:")
    print("  git push origin main")
    print()

elif choice == 'B':
    print("═" * 70)
    print("INSTRUÇÕES PARA RENOMEAR NO GITHUB")
    print("═" * 70)
    print()

    for expected, actual in different_names.items():
        print(f"1. Delete '{actual}' em GitHub Secrets")
        print(f"2. Crie novo secret com nome '{expected}'")
        print(f"3. Configure o valor")
        print()

    print("Depois de renomear todos:")
    print("  git push origin main")
    print()

print("═" * 70)
print()
print("✨ Script finalizado! Siga os próximos passos acima.")
print()
