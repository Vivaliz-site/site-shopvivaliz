#!/bin/sh
# Wrapper legado protegido; implementação preservada em scripts/maintenance/legacy/.
set -eu
if [ "${ALLOW_LOCAL_SECRET_SYNC:-}" != "YES" ]; then
  echo "Execução bloqueada. Este utilitário escreve configuração local. Defina ALLOW_LOCAL_SECRET_SYNC=YES somente em ambiente local seguro e com arquivo ignorado pelo Git." >&2
  exit 2
fi
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
exec "$SCRIPT_DIR/maintenance/legacy/sincronizar_secrets_github.sh" "$@"
