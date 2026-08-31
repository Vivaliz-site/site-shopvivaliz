#!/bin/bash
set -euo pipefail

# Diagnostic for a deliberately configured MCP endpoint.
# Do not hard-code VM addresses or credentials here.
: "${SHOPVIVALIZ_MCP_HOST:?Set SHOPVIVALIZ_MCP_HOST to the current MCP host/alias}"
PORT="${SHOPVIVALIZ_MCP_PORT:-5556}"
BASE_URL="${SHOPVIVALIZ_MCP_SCHEME:-http}://${SHOPVIVALIZ_MCP_HOST}:${PORT}"

CLAUDE_KEY="${SHOPVIVALIZ_CLAUDE_MCP_KEY:-}"
OPENAI_KEY="${SHOPVIVALIZ_OPENAI_MCP_KEY:-}"
GEMINI_KEY="${SHOPVIVALIZ_GEMINI_MCP_KEY:-}"

echo "Testing AI Agent Access to configured MCP endpoint"
echo "Host: ${SHOPVIVALIZ_MCP_HOST}:${PORT}"

request_get() {
  local path="$1"
  curl -fsS --max-time 10 "$BASE_URL$path" -w "\nHTTP Status: %{http_code}\n"
}

request_exec() {
  local provider="$1" key="$2"
  if [[ -z "$key" ]]; then
    echo "SKIP: no credential configured for $provider"
    return 0
  fi
  echo "Provider: $provider"
  curl -fsS --max-time 15 -X POST "$BASE_URL/exec" \
    -H "X-API-Key: $key" \
    -H "Content-Type: application/json" \
    -d '{"cmd":"whoami","timeout":10}' \
    -w "\nHTTP Status: %{http_code}\n"
}

echo "=== Connectivity ==="
request_get /status
request_get /health
request_get /tools || true

echo "=== Authenticated execution (only when keys are supplied) ==="
request_exec Claude "$CLAUDE_KEY"
request_exec OpenAI "$OPENAI_KEY"
request_exec Gemini "$GEMINI_KEY"

echo "Agent access diagnostics complete."
