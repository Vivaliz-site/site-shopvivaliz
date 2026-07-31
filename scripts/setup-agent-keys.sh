#!/bin/bash
set -euo pipefail

# Configure the runtime agent key without committing or printing it.
# Usage: SHOPVIVALIZ_AGENT_KEY='...' bash scripts/setup-agent-keys.sh

ENV_FILE="${ENV_FILE:-.env}"
AGENT_KEY="${SHOPVIVALIZ_AGENT_KEY:-${1:-}}"

if [ ! -f "$ENV_FILE" ]; then
    echo "Arquivo .env nao encontrado" >&2
    exit 1
fi

if [ -z "$AGENT_KEY" ] || [ "${#AGENT_KEY}" -lt 32 ]; then
    echo "Forneca SHOPVIVALIZ_AGENT_KEY com pelo menos 32 caracteres" >&2
    exit 1
fi

umask 077
tmp_file="$(mktemp)"
trap 'rm -f "$tmp_file"' EXIT

grep -vE '^(SHOPVIVALIZ_AGENT_KEY|RUNTIME_AGENT_KEY|WATCHDOG_AGENT_KEY|AUTONOMOUS_AGENT_KEY)=' "$ENV_FILE" > "$tmp_file" || true
{
    printf '\nSHOPVIVALIZ_AGENT_KEY=%s\n' "$AGENT_KEY"
    printf 'RUNTIME_AGENT_KEY=%s\n' "$AGENT_KEY"
    printf 'WATCHDOG_AGENT_KEY=%s\n' "$AGENT_KEY"
    printf 'AUTONOMOUS_AGENT_KEY=%s\n' "$AGENT_KEY"
} >> "$tmp_file"

mv "$tmp_file" "$ENV_FILE"
chmod 600 "$ENV_FILE"
trap - EXIT

echo "Chaves de agente atualizadas com seguranca"
