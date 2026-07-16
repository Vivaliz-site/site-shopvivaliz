#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Verifica se todos os secrets necessários estão configurados
"""
import os
import sys
import json
from pathlib import Path
from datetime import datetime

# Force UTF-8 output on Windows
if sys.platform == 'win32':
    sys.stdout.reconfigure(encoding='utf-8')

REQUIRED_SECRETS = {
    'API Keys': [
        'ANTHROPIC_API_KEY',
        'OPENAI_API_KEY',
        'GEMINI_API_KEY',
    ],
    'Olist': [
        'OLIST_CLIENT_ID',
        'OLIST_CLIENT_SECRET',
        'TOKEN_API_OLIST',
        'CLIENT_ID_API_OLIST',
        'CLIENT_SECRET_OLIST',
        'OLIST_ACCESS_TOKEN',
        'OLIST_REFRESH_TOKEN',
    ],
    'FTP': [
        'FTP_SERVER',
        'FTP_USERNAME',
        'FTP_PASSWORD',
    ],
    'Email': [
        'EMAIL_FROM',
        'EMAIL_TO',
        'EMAIL_SMTP_HOST',
        'EMAIL_USER',
        'EMAIL_PASSWORD',
    ],
    'Database': [
        'DB_HOST',
        'DB_NAME',
    ],
    'Marketplace': [
        'SHOPEE_PARTNER_ID',
        'SHOPEE_PARTNER_KEY',
        'SHOPEE_TEST_PARTNER_ID',
        'SHOPEE_TEST_PARTNER_KEY',
        'TIKTOK_CLIENT_ID',
        'TIKTOK_CLIENT_SECRET',
        'TIKTOK_APP_KEY',
        'TIKTOK_APP_SECRET',
        'TIKTOK_ACCESS_TOKEN',
        'TIKTOK_SHOP_ID',
        'TIKTOK_SHOP_CIPHER',
    ],
}

def check_secret(key: str) -> bool:
    """Verifica se um secret está configurado"""
    return bool(os.environ.get(key))

def mask_value(value: str) -> str:
    """Mascara um valor para exibição segura"""
    if not value or len(value) < 10:
        return '***'
    return value[:3] + '*' * (len(value) - 6) + value[-3:]

def main():
    print("""
╔════════════════════════════════════════════════════════════════╗
║        🔐 VERIFICAÇÃO DE SECRETS - SHOPVIVALIZ                ║
╚════════════════════════════════════════════════════════════════╝
""")

    report = {
        'timestamp': datetime.now().isoformat(),
        'secrets_configured': {},
        'secrets_missing': {},
        'summary': {}
    }

    total_required = 0
    total_found = 0

    # Verificar por categoria
    for category, secrets in REQUIRED_SECRETS.items():
        print(f"\n📦 {category}")
        print("=" * 60)

        category_found = 0
        category_missing = 0

        report['secrets_configured'][category] = []
        report['secrets_missing'][category] = []

        for secret in secrets:
            total_required += 1
            value = os.environ.get(secret)

            if value:
                total_found += 1
                category_found += 1
                masked = mask_value(value)
                print(f"  ✅ {secret:30} {masked}")
                report['secrets_configured'][category].append(secret)
            else:
                category_missing += 1
                print(f"  ❌ {secret:30} NÃO CONFIGURADO")
                report['secrets_missing'][category].append(secret)

        percentage = 100 * category_found / (category_found + category_missing)
        status = "✅" if category_missing == 0 else "⚠️"
        print(f"{status} {category_found}/{category_found + category_missing} configurados ({percentage:.0f}%)")

    # Resumo
    print(f"\n{'='*60}")
    print(f"📊 RESUMO GERAL")
    print(f"{'='*60}")
    print(f"✅ Configurados: {total_found}/{total_required}")
    print(f"❌ Faltando: {total_required - total_found}/{total_required}")
    percentage = 100 * total_found / total_required if total_required > 0 else 0
    print(f"📈 Taxa de Conclusão: {percentage:.1f}%")

    # Recomendações
    print(f"\n{'='*60}")
    print(f"📋 PRÓXIMOS PASSOS")
    print(f"{'='*60}")

    if total_found == total_required:
        print("🎉 TODOS OS SECRETS ESTÃO CONFIGURADOS!")
        print("\n✅ Sistema pronto para executar pipelines.")
    else:
        print(f"⚠️  {total_required - total_found} secrets ainda faltam configurar.\n")
        print("Para adicionar um secret manualmente:")
        print("  gh secret set NOME_DO_SECRET")
        print("  # Digite o valor e confirme\n")
        print("Ou use o script setup_secrets.py:")
        print("  python scripts/setup_secrets.py")

    # Salvar relatório
    report['summary'] = {
        'total_required': total_required,
        'total_found': total_found,
        'completion_percentage': percentage
    }

    report_file = Path('logs/secrets_verification.json')
    report_file.parent.mkdir(exist_ok=True)
    with report_file.open('w', encoding='utf-8') as f:
        json.dump(report, f, indent=2, ensure_ascii=False)

    print(f"\n📄 Relatório salvo em {report_file}")
    print()

    # Retornar código apropriado
    return 0 if total_found == total_required else 1

if __name__ == '__main__':
    sys.exit(main())
