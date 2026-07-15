#!/bin/bash
# Hook pré-commit: BLOQUEIA wildcards CSS perigosos que quebram o layout

set -e

WILDCARD_PATTERNS=(
    '\[class\*='           # [class*=
    '\[id\*='              # [id*=
    '\[style\*='           # [style*=
    '\[data-[a-z]*\*='     # [data-*=
)

ERROR=0

for pattern in "${WILDCARD_PATTERNS[@]}"; do
    if git diff --cached --name-only -- '*.css' | xargs grep -l "$pattern" 2>/dev/null; then
        echo "❌ ERRO: Wildcard CSS detectado que quebra o layout!"
        echo "   Padrão: $pattern"
        echo "   Motivo: Wildcards como [class*='hero'] casam com QUALQUER classe contendo 'hero'"
        echo "           e aplicam estilos em cascata, quebrando subcomponentes."
        echo ""
        echo "   LIÇÃO (CHANGELOG.md - 2026-07-09):"
        echo "   'NUNCA usar [class*=\"...\"] em CSS deste projeto'"
        echo ""
        echo "   Solução: Use seletores EXATOS:"
        echo "   ❌ [class*='hero']  →  ✅ .hero"
        echo "   ❌ [class*='card']  →  ✅ .card"
        echo ""
        ERROR=1
    fi
done

if [ $ERROR -eq 1 ]; then
    exit 1
fi

exit 0
