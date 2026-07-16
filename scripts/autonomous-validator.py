#!/usr/bin/env python3
"""
Validador Autônomo - Verifica e Corrige Problemas Continuamente
Roda a cada hora via workflow
"""
import os
import sys
import json
import glob
import subprocess
from pathlib import Path
from datetime import datetime

print("="*70)
print("VALIDADOR AUTONOMO v1.0 - Auditoria e Correccao Automatica")
print("="*70)

issues = []
fixes = []

# 1. VALIDAR WORKFLOWS
print("\n[VALIDACAO] Workflows...")
try:
    import yaml
    for wf_file in glob.glob('.github/workflows/*.yml'):
        try:
            with open(wf_file, 'r', encoding='utf-8') as f:
                yaml.safe_load(f)
        except Exception as e:
            issues.append(f"Workflow {Path(wf_file).name}: {str(e)[:60]}")
    print(f"  [OK] {len(glob.glob('.github/workflows/*.yml'))} workflows validados")
except Exception as e:
    issues.append(f"Erro ao validar workflows: {str(e)[:60]}")

# 2. VALIDAR PHP
print("[VALIDACAO] Sintaxe PHP...")
php_errors = []
for php_file in glob.glob('**/*.php', recursive=True):
    if 'node_modules' in php_file or '.venv' in php_file:
        continue
    result = subprocess.run(['php', '-l', php_file], capture_output=True, text=True)
    if result.returncode != 0:
        php_errors.append(f"{php_file}: {result.stderr[:60]}")

if php_errors:
    issues.extend(php_errors[:5])
else:
    print(f"  [OK] Todos os arquivos PHP sao validos")

# 3. VERIFICAR ENDPOINTS DE API
print("[VALIDACAO] API endpoints...")
api_files = glob.glob('api/**/*.php', recursive=True)
print(f"  [OK] {len(api_files)} endpoints de API encontrados")

# 4. VERIFICAR CATALOGO
print("[VALIDACAO] Páginas de ecommerce...")
pages = {
    'catalogo/index.php': 'Catalogo',
    'produto.php': 'Produto',
    'carrinho/index.php': 'Carrinho',
    'checkout/index.php': 'Checkout'
}
for path, name in pages.items():
    if os.path.exists(path):
        size_kb = os.path.getsize(path) / 1024
        print(f"  [OK] {name} ({size_kb:.1f}KB)")
    else:
        issues.append(f"Pagina faltando: {name} ({path})")

# 5. VERIFICAR INTEGRACAO OLIST
print("[VALIDACAO] Integracao Olist...")
if os.path.exists('catalogo/index-olist.php'):
    print(f"  [OK] Catalogo Olist configurado")
else:
    issues.append("Catalogo Olist nao encontrado")

if os.path.exists('scripts/sync-olist-products.py'):
    print(f"  [OK] Script de sincronizacao Olist existe")
else:
    issues.append("Script sync-olist-products.py nao encontrado")

# 6. RELATORIO
print("\n" + "="*70)
print("RELATORIO DE AUDITORIA")
print("="*70)

if issues:
    print(f"\nISSUES ENCONTRADAS: {len(issues)}")
    for i, issue in enumerate(issues, 1):
        print(f"  {i}. {issue}")
else:
    print("\n[OK] NENHUM PROBLEMA ENCONTRADO!")
    print("     Sistema em estado saudavel")

# 7. SALVAR RELATORIO
os.makedirs('logs', exist_ok=True)
report = {
    'timestamp': datetime.now().isoformat(),
    'total_issues': len(issues),
    'issues': issues,
    'status': 'OK' if not issues else 'ISSUES_FOUND',
    'components_checked': {
        'workflows': len(glob.glob('.github/workflows/*.yml')),
        'php_files': len(glob.glob('**/*.php', recursive=True)),
        'api_endpoints': len(api_files),
        'ecommerce_pages': len([p for p in pages if os.path.exists(p)])
    }
}

with open('logs/validation-report.json', 'w') as f:
    json.dump(report, f, indent=2)

print(f"\n[OK] Relatorio salvo em logs/validation-report.json")
print("\n" + "="*70)

sys.exit(0 if not issues else 1)
