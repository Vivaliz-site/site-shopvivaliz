#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script completo de teste do pipeline ShopVivaliz
"""

import os
import sys
import json
from pathlib import Path
from datetime import datetime

def print_header(title):
    """Print formatted header"""
    print("\n" + "=" * 70)
    print(f"  {title}")
    print("=" * 70 + "\n")

def test_structure():
    """Test directory structure"""
    print_header("1. TESTE DE ESTRUTURA")

    required_dirs = ['api', 'logs', 'storage', 'planilhas', 'scripts']
    passed = 0

    for dir_name in required_dirs:
        if Path(dir_name).exists():
            print(f"  [OK] {dir_name}/")
            passed += 1
        else:
            print(f"  [CRIAR] {dir_name}/")
            Path(dir_name).mkdir(exist_ok=True)

    print(f"\n  Resultado: {passed}/{len(required_dirs)} diretorios existem")
    return passed == len(required_dirs)

def test_dependencies():
    """Test Python dependencies"""
    print_header("2. TESTE DE DEPENDENCIAS")

    dependencies = [
        ('requests', 'HTTP requests'),
        ('openpyxl', 'Excel files'),
        ('PIL', 'Image processing'),
    ]

    passed = 0
    for module, description in dependencies:
        try:
            __import__(module)
            print(f"  [OK] {module:20} - {description}")
            passed += 1
        except ImportError:
            print(f"  [FALTA] {module:20} - {description}")

    print(f"\n  Resultado: {passed}/{len(dependencies)} dependencias instaladas")
    return passed == len(dependencies)

def test_integrations():
    """Test integrations setup"""
    print_header("3. TESTE DE INTEGRACOES")

    integrations = {
        'Shopee': Path('api/shopee-integration'),
        'TikTok': Path('api/tiktok-integration'),
    }

    passed = 0
    for name, path in integrations.items():
        if path.exists():
            scripts = list(path.glob('scripts/*.py'))
            print(f"  [OK] {name:20} - {len(scripts)} scripts")
            passed += 1
        else:
            print(f"  [FALTA] {name:20}")

    print(f"\n  Resultado: {passed}/{len(integrations)} integracoes configuradas")
    return passed == len(integrations)

def test_workflows():
    """Test GitHub workflows"""
    print_header("4. TESTE DE WORKFLOWS")

    workflow_dir = Path('.github/workflows')
    if not workflow_dir.exists():
        print("  [FALTA] .github/workflows/")
        return False

    workflows = list(workflow_dir.glob('*.yml'))
    print(f"  Workflows encontrados: {len(workflows)}")

    critical_workflows = [
        'deploy.yml',
        'shopee-email-pipeline.yml',
        'ecommerce-multi-ai-build-24-7.yml',
    ]

    passed = 0
    for workflow in critical_workflows:
        if (workflow_dir / workflow).exists():
            print(f"  [OK] {workflow}")
            passed += 1
        else:
            print(f"  [FALTA] {workflow}")

    print(f"\n  Resultado: {passed}/{len(critical_workflows)} workflows criticos")
    return passed == len(critical_workflows)

def test_secrets_structure():
    """Test secrets configuration"""
    print_header("5. TESTE DE SECRETS")

    secrets = {
        'SMTP': ['SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASS'],
        'EMAIL': ['EMAIL_FROM', 'EMAIL_TO'],
        'Shopee': ['SHOPEE_ACCESS_TOKEN', 'SHOPEE_SHOP_ID'],
        'TikTok': ['TIKTOK_APP_KEY', 'TIKTOK_APP_SECRET'],
    }

    total = 0
    configured = 0

    for category, secret_list in secrets.items():
        total += len(secret_list)
        for secret in secret_list:
            if os.getenv(secret):
                print(f"  [OK] {secret}")
                configured += 1
            else:
                print(f"  [NAO_CONFIGURADO] {secret}")

    print(f"\n  Resultado: {configured}/{total} secrets configurados")
    print(f"  (Secrets locais: execute com variaveis de ambiente)")
    return True

def test_scripts():
    """Test main scripts"""
    print_header("6. TESTE DE SCRIPTS")

    main_scripts = [
        'scripts/main.py',
        'scripts/autonomous-validator.py',
        'api/shopee-integration/scripts/get_access_token.py',
        'api/tiktok-integration/scripts/get_access_token.py',
    ]

    passed = 0
    for script in main_scripts:
        if Path(script).exists():
            print(f"  [OK] {script}")
            passed += 1
        else:
            print(f"  [FALTA] {script}")

    print(f"\n  Resultado: {passed}/{len(main_scripts)} scripts encontrados")
    return passed == len(main_scripts)

def generate_report():
    """Generate test report"""
    print_header("RELATORIO FINAL DO TESTE")

    tests = [
        ('Estrutura de diretorios', test_structure()),
        ('Dependencias Python', test_dependencies()),
        ('Integracoes', test_integrations()),
        ('Workflows', test_workflows()),
        ('Scripts', test_scripts()),
    ]

    passed = sum(1 for _, result in tests if result)
    total = len(tests)

    print("\nResultados:")
    for name, result in tests:
        status = "[OK]" if result else "[FALHA]"
        print(f"  {status} {name}")

    print("\n" + "=" * 70)
    print(f"RESULTADO FINAL: {passed}/{total} testes passaram")
    print("=" * 70)

    # Save report
    report = {
        'timestamp': datetime.now().isoformat(),
        'tests': [{'name': name, 'passed': result} for name, result in tests],
        'summary': f"{passed}/{total} testes passaram"
    }

    with open('logs/pipeline_test_report.json', 'w') as f:
        json.dump(report, f, indent=2)

    print("\nRelatorio salvo em: logs/pipeline_test_report.json")

    return passed == total

if __name__ == '__main__':
    try:
        success = generate_report()

        test_secrets_structure()

        print("\n" + "=" * 70)
        if success:
            print("✅ PIPELINE ESTA PRONTO PARA USO!")
        else:
            print("⚠️  ALGUMAS VERIFICACOES FALHARAM")
        print("=" * 70 + "\n")

        sys.exit(0 if success else 1)

    except Exception as e:
        print(f"\n[ERRO] {e}")
        sys.exit(1)
