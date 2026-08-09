#!/usr/bin/env bash
# Compatibility wrapper for the safe runtime secret materializer.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v python3 >/dev/null 2>&1; then
  echo "ERRO: python3 e obrigatorio para materializar e validar secrets com seguranca." >&2
  exit 1
fi

exec python3 "$SCRIPT_DIR/sincronizar_secrets_github.py" "$@"
