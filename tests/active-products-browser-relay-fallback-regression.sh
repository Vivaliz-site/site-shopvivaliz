#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SELECTOR="$ROOT/scripts/select-active-products-browser-relay.sh"
WORKFLOW="$ROOT/.github/workflows/active-products-browser-smoke.yml"
QUALITY="$ROOT/.github/workflows/quality-gate.yml"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

cat > "$TMP/ssh" <<'SH'
#!/usr/bin/env bash
set -Eeuo pipefail
mode="${FAKE_RELAY_MODE:?}"
cmd="${*: -1}"
if [[ "$cmd" == *":5557/health"* ]]; then
  case "$mode" in
    primary) printf '%s\n' '{"status":"ok","environment":"fred-win","mcp_version":"1.0.0"}'; exit 0 ;;
    fallback) exit 7 ;;
    wrong_env) printf '%s\n' '{"status":"ok","environment":"unexpected","mcp_version":"1.0.0"}'; exit 0 ;;
  esac
fi
if [[ "$cmd" == *":5558/health"* ]]; then
  case "$mode" in
    fallback) printf '%s\n' '{"status":"ok","environment":"desktop-kocepsv","mcp_version":"1.0.0"}'; exit 0 ;;
    wrong_env) printf '%s\n' '{"status":"ok","environment":"unexpected","mcp_version":"1.0.0"}'; exit 0 ;;
  esac
fi
exit 9
SH
chmod +x "$TMP/ssh"

run_selector() {
  local mode="$1"
  PATH="$TMP:$PATH" FAKE_RELAY_MODE="$mode" SV_RELAY_SSH_BIN=ssh \
    SV_RELAY_IDENTITY_FILE=/tmp/fake-key SV_RELAY_KNOWN_HOSTS_FILE=/tmp/fake-hosts \
    bash "$SELECTOR"
}

primary="$(run_selector primary)"
[[ "$primary" == "5557" ]] || { echo "expected primary 5557, got $primary" >&2; exit 1; }

fallback="$(run_selector fallback)"
[[ "$fallback" == "5558" ]] || { echo "expected fallback 5558, got $fallback" >&2; exit 1; }

set +e
run_selector wrong_env >/tmp/relay-selector-wrong.out 2>/tmp/relay-selector-wrong.err
wrong_rc=$?
set -e
[[ "$wrong_rc" -ne 0 ]] || { echo 'unexpected environment must fail selection' >&2; exit 1; }

grep -Fq 'scripts/select-active-products-browser-relay.sh' "$WORKFLOW"
grep -Fq 'SV_BROWSER_RELAY_PORT' "$WORKFLOW"
grep -Fq "http://127.0.0.1:{port}/mcp/tool/execute_command" "$WORKFLOW"
grep -Fq "bash tests/active-products-browser-relay-fallback-regression.sh" "$QUALITY"

echo ACTIVE_PRODUCTS_BROWSER_RELAY_FALLBACK_OK
