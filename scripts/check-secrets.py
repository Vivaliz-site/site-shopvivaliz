#!/usr/bin/env python3
"""
Script para verificar quais chaves de API estao configuradas nos secrets
"""

import os
import sys

print("\n" + "="*70)
print("  VERIFICACAO DE SECRETS - CHAVES DE IA")
print("="*70 + "\n")

# Lista de variáveis a verificar
secrets_to_check = [
    ('OPENAI_API_KEY', 'OpenAI DALL-E (Gerador de Imagens)'),
    ('OPENAI_API_KEY_SK', 'OpenAI (Nome Alternativo)'),
    ('OPENAI_KEY', 'OpenAI (Nome Curto)'),
    ('OPENAI_SECRET', 'OpenAI (Nome Secret)'),
    ('ANTHROPIC_API_KEY', 'Claude (Anthropic)'),
    ('CLAUDE_API_KEY', 'Claude (Alias)'),
    ('GEMINI_API_KEY', 'Google Gemini'),
    ('GOOGLE_API_KEY', 'Google API'),
]

print("CHAVES DE IA ENCONTRADAS:\n")

found_count = 0
for var_name, description in secrets_to_check:
    value = os.getenv(var_name, '')
    if value:
        # Mostrar apenas primeiros e últimos caracteres por segurança
        masked = f"{value[:10]}...{value[-10:]}" if len(value) > 20 else "***"
        print(f"  [OK] {var_name:25} [{description}]")
        print(f"       Valor: {masked}\n")
        found_count += 1
    else:
        print(f"  [--] {var_name:25} [NAO CONFIGURADA]")

print("\n" + "="*70)
print(f"RESULTADO: {found_count} chave(s) encontrada(s) em {len(secrets_to_check)} verificadas")
print("="*70 + "\n")

if found_count == 0:
    print("AVISO: Nenhuma chave de IA foi encontrada!")
    print("\nConfigure pelo menos uma das seguintes no GitHub Secrets:")
    for var_name, description in secrets_to_check[:4]:
        print(f"  - {var_name}")
    sys.exit(1)
else:
    print("OK: Chaves de IA estao configuradas! A geracao de imagens funcionara.\n")
    sys.exit(0)
