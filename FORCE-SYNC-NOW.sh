#!/usr/bin/env bash
set -euo pipefail

cat >&2 <<'EOF'
FORCE-SYNC-NOW.sh foi aposentado.

Este arquivo usava reset destrutivo e não deve ser executado em produção.
Use exclusivamente o pipeline "Master Production Pipeline 24/7" ou o
sincronizador canônico documentado em docs/AGENT-VM-PROMPTS.md.
EOF

exit 2
