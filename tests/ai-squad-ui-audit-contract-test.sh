#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
workflow="$root/.github/workflows/shopvivaliz-remote-access.yml"
creator="$root/scripts/create-admin-test-user.php"
ui_audit="$root/scripts/ai-squad-ui-audit.mjs"

test -f "$workflow"
test -f "$creator"
test -f "$ui_audit"

grep -q 'ai_squad_ui_audit' "$workflow"
grep -q 'AI_SQUAD_UI_AUDIT=PASS' "$ui_audit"
grep -q 'shopvivaliz-deploy/repo/node_modules/playwright/index.js' "$ui_audit"
grep -Fq 'mod.default?.chromium' "$ui_audit"
grep -Fq 'chromium.executablePath()' "$ui_audit"
grep -Fq 'null, { timeout: 1100000 }' "$ui_audit"
grep -q 'AI_SQUAD_BROWSER_HEADLESS' "$ui_audit"
! grep -Fq 'headless: true' "$ui_audit"
! grep -Fq '!process.env.DISPLAY' "$ui_audit"
grep -q "page.on('console'" "$ui_audit"
grep -q "page.on('pageerror'" "$ui_audit"
grep -q "page.on('requestfailed'" "$ui_audit"
grep -q 'response.status() >= 500' "$ui_audit"
grep -q 'page.reload' "$ui_audit"
grep -Fq "LIBGL_ALWAYS_SOFTWARE: '1'" "$ui_audit"
grep -Fq "'--disable-gpu'" "$ui_audit"
grep -Fq "'--disable-vulkan'" "$ui_audit"
grep -Fq "fs.mkdtempSync" "$ui_audit"
grep -Fq "fs.rmSync(profileDir" "$ui_audit"
grep -q 'shopvivaliz-admin-test.credentials.json' "$ui_audit"
grep -Fq 'https://shopvivaliz.com.br/admin/buscador.php' "$ui_audit"
grep -q "deep_research" "$ui_audit"
grep -Fq "(?:ai-squad|buscador)" "$ui_audit"
grep -q "gemini-3.5-flash" "$ui_audit"
grep -Fq "['Concluído','Incompleto']" "$ui_audit"
grep -q "research" "$ui_audit"
grep -q "Pesquisa independente" "$ui_audit"
grep -q "Contraditório" "$ui_audit"
grep -q "Convergência" "$ui_audit"
grep -q "Síntese de consenso" "$ui_audit"
if grep -q "gemini-2.5-flash" "$ui_audit"; then
  echo "FAIL: UI auditor still accepts the stale Gemini 2.5 model contract" >&2
  exit 1
fi
grep -Fq "(?:ai-squad|buscador)" "$ui_audit"
grep -q "Incompleto" "$ui_audit"
grep -q "msg.error" "$ui_audit"
grep -q "msg.manual" "$ui_audit"
grep -q "sources a" "$ui_audit"
grep -q "credential-file" "$creator"
grep -q "chmod" "$creator"
if grep -q 'Senha:  {$plainPassword}' "$creator"; then
  echo "FAIL: create-admin-test-user still prints generated password" >&2
  exit 1
fi

python3 - "$workflow" <<'PY'
from pathlib import Path
import sys
text = Path(sys.argv[1]).read_text(encoding="utf-8")
required = [
    "- ai_squad_ui_audit",
    '"ai_squad_ui_audit"',
    "AI_SQUAD_UI_AUDIT=PASS",
    "shopvivaliz-admin-test.credentials.json",
]
for item in required:
    if item not in text:
        raise SystemExit(f"FAIL: missing workflow contract: {item}")
if "target != 'shopvivaliz-free-a1'" not in text and 'target != "shopvivaliz-free-a1"' not in text:
    raise SystemExit("FAIL: AI Squad UI audit must be restricted to production target")
print("AI_SQUAD_UI_AUDIT_CONTRACT=PASS")
PY
