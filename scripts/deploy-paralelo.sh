#!/bin/sh
# Wrapper legado protegido; implementação preservada em scripts/production/legacy/.
set -eu
if [ "${ALLOW_LEGACY_PRODUCTION_SCRIPT:-}" != "YES" ]; then
  echo "Execução bloqueada. Revise o script legado e defina ALLOW_LEGACY_PRODUCTION_SCRIPT=YES somente após backup, plano de rollback e validação do destino." >&2
  exit 2
fi
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
exec "$SCRIPT_DIR/production/legacy/deploy-paralelo.sh" "$@"
