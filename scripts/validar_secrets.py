#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Validador de Secrets - Verifica se todos os secrets estão configurados corretamente.

Uso:
    python3 scripts/validar_secrets.py              # Validação completa
    python3 scripts/validar_secrets.py --quick      # Validação rápida
    python3 scripts/validar_secrets.py --report     # Relatório detalhado
"""

import sys
import os

# Forçar UTF-8 no Windows
if sys.platform == 'win32':
    os.environ['PYTHONIOENCODING'] = 'utf-8'
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

from pathlib import Path
import json
import argparse
from typing import Dict, List, Tuple

# Adicionar config ao path
sys.path.insert(0, str(Path(__file__).parent.parent))

def main():
    parser = argparse.ArgumentParser(description="Validar secrets do ShopVivaliz")
    parser.add_argument("--quick", action="store_true", help="Validação rápida")
    parser.add_argument("--report", action="store_true", help="Relatório detalhado")
    parser.add_argument("--json", action="store_true", help="Saída em JSON")

    args = parser.parse_args()

    try:
        from config.secrets import (
            validate_secrets,
            get_all_secrets,
            REQUIRED_SECRETS,
            mask_secret,
        )
    except ImportError as e:
        print(f"❌ Erro ao importar config.secrets: {e}")
        print("   Certifique-se de que existe o arquivo config/secrets.py")
        sys.exit(1)

    print("[SECURITY] ShopVivaliz - Validador de Secrets")
    print("=" * 60)

    # Validação
    success, errors = validate_secrets()

    if args.json:
        result = {
            "valid": success,
            "errors": errors,
            "secrets": get_all_secrets() if args.report else {},
        }
        print(json.dumps(result, indent=2))
        sys.exit(0 if success else 1)

    if success:
        print("\n[SUCCESS] SUCESSO - Todos os secrets obrigatórios estão configurados!\n")

        if args.quick:
            sys.exit(0)

        if args.report:
            print("[INFO] Secrets Carregados (Valores Mascarados):")
            print("-" * 60)
            secrets = get_all_secrets()
            for key, value in sorted(secrets.items()):
                print(f"  {key:30} : {value}")
            print()

        print("[OK] Sistema pronto para uso!")
        sys.exit(0)
    else:
        print("\n[ERROR] ERRO - Secrets obrigatórios faltando:\n")
        for error in errors:
            print(f"  {error}")

        print("\n📝 Solução:")
        print("  1. Copie .env.example para .env.local")
        print("  2. Preencha os valores reais em .env.local")
        print("  3. Execute este validador novamente")
        print("\n  Variáveis obrigatórias:")
        for key, desc in REQUIRED_SECRETS.items():
            print(f"    • {key}: {desc}")

        sys.exit(1)

if __name__ == "__main__":
    main()
