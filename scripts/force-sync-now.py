#!/usr/bin/env python3
"""
Force sync - Ignora validações e força git reset
Para usar quando o daemon normal falha silenciosamente
"""
import subprocess
import sys
import os
from pathlib import Path

REPO_DIR = Path(__file__).parent.parent
os.chdir(REPO_DIR)

def run(cmd, desc=""):
    print(f"[RUN] {desc}: {' '.join(cmd)}")
    result = subprocess.run(cmd, capture_output=True, text=True, timeout=120)
    if result.returncode != 0:
        print(f"[ERR] {result.stderr}")
        return False
    print(f"[OK] {desc}")
    return True

print("="*70)
print("FORCE SYNC - IGNORA VALIDACOES")
print("="*70)
print()

# 1. Fetch
if not run(['git', 'fetch', 'origin', 'main'], "Fetch origin/main"):
    sys.exit(1)

# 2. Status ANTES
result = subprocess.run(['git', 'status', '--porcelain'], capture_output=True, text=True)
print(f"[BEFORE] Dirty files: {len(result.stdout.splitlines())}")

# 3. Hard reset SEM VALIDACOES
if not run(['git', 'reset', '--hard', 'origin/main'], "Hard reset origin/main"):
    sys.exit(1)

# 4. Status DEPOIS
result = subprocess.run(['git', 'rev-parse', 'HEAD'], capture_output=True, text=True)
print(f"[AFTER] HEAD: {result.stdout.strip()[:8]}")

# 5. Verificar arquivo crítico
checkout_file = REPO_DIR / "checkout" / "index.php"
content = checkout_file.read_text()

print()
print("Verificação final:")
print(f"  - checkout/index.php: {len(content)} bytes")
print(f"  - mercado_pago: {'SIM' if 'mercado_pago' in content else 'NAO'}")
print(f"  - pagarme: {'SIM' if 'pagarme' in content else 'NAO'}")

if 'pagarme' in content and 'mercado_pago' in content:
    print()
    print("="*70)
    print("[SUCCESS] Ambos os gateways presentes!")
    print("="*70)
    sys.exit(0)
else:
    print()
    print("[FAIL] Gateways não encontrados após sync")
    sys.exit(1)
