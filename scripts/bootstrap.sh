#!/usr/bin/env bash
# Bootstrap seguro do ShopVivaliz.
# Valida a configuracao antes de iniciar qualquer rotina de sincronizacao.

set -euo pipefail
umask 077

START_AUTO_SYNC=0

usage() {
  cat <<'EOF'
Uso: bash scripts/bootstrap.sh [--start-auto-sync]

Por padrao, o bootstrap apenas materializa valores ja presentes no ambiente e
valida os secrets. O auto-sync so e iniciado quando solicitado explicitamente.
EOF
}

while (( $# > 0 )); do
  case "$1" in
    --start-auto-sync)
      START_AUTO_SYNC=1
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "ERRO: argumento desconhecido: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
  shift
done

cd "$(dirname "$0")/.."
REPO_DIR="$(pwd)"
mkdir -p logs

if ! command -v python3 >/dev/null 2>&1; then
  echo "ERRO: python3 e obrigatorio para materializar e validar secrets." >&2
  exit 1
fi

echo "ShopVivaliz Bootstrap Seguro"
echo "============================"

echo "1. Materializando valores presentes no ambiente..."
python3 scripts/sincronizar_secrets_github.py

echo "2. Validando secrets obrigatorios..."
python3 scripts/validar_secrets.py --quick

echo "3. Auto-sync..."
if (( START_AUTO_SYNC == 0 )); then
  echo "   Nao iniciado. Use --start-auto-sync somente em ambiente autorizado."
else
  if [[ ! -f scripts/auto-sincronizar.sh ]]; then
    echo "ERRO: scripts/auto-sincronizar.sh nao encontrado." >&2
    exit 1
  fi

  LOG_FILE="$REPO_DIR/logs/bootstrap.log"
  PID_FILE="$REPO_DIR/logs/bootstrap-auto-sync.pid"
  nohup bash scripts/auto-sincronizar.sh >"$LOG_FILE" 2>&1 &
  auto_sync_pid=$!
  printf '%s\n' "$auto_sync_pid" >"$PID_FILE"

  sleep 1
  if ! kill -0 "$auto_sync_pid" 2>/dev/null; then
    wait "$auto_sync_pid" || exit_code=$?
    rm -f "$PID_FILE"
    echo "ERRO: auto-sync encerrou durante a inicializacao (codigo ${exit_code:-1})." >&2
    exit "${exit_code:-1}"
  fi

  echo "   Processo iniciado e ativo (PID: $auto_sync_pid)."
  echo "   Log: $LOG_FILE"
  echo "   PID: $PID_FILE"
fi

echo "Bootstrap validado com sucesso."
