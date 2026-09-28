#!/usr/bin/env bash
set -Eeuo pipefail

CLAUDE_BIN="${HOME}/.local/bin/claude"
if [ ! -x "$CLAUDE_BIN" ]; then
  CLAUDE_BIN="$(command -v claude 2>/dev/null || true)"
fi

present=false
auth=false
remote=false
env_ok=true

if [ -n "$CLAUDE_BIN" ] && [ -x "$CLAUDE_BIN" ]; then
  present=true
  auth_tmp="$(mktemp)"
  err_tmp="$(mktemp)"
  cleanup() { rm -f "$auth_tmp" "$err_tmp"; }
  trap cleanup EXIT

  if timeout 15s "$CLAUDE_BIN" auth status --json >"$auth_tmp" 2>"$err_tmp"; then
    if python3 - "$auth_tmp" <<'PY'
import json, sys
with open(sys.argv[1], encoding="utf-8") as handle:
    data=json.load(handle)
raise SystemExit(0 if data.get("loggedIn") is True else 1)
PY
    then
      auth=true
    fi
  fi

  if timeout 15s "$CLAUDE_BIN" remote-control --help >/dev/null 2>&1; then
    remote=true
  fi
fi

for name in ANTHROPIC_BASE_URL CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC DISABLE_GROWTHBOOK; do
  if [ -n "${!name:-}" ]; then
    env_ok=false
  fi
done

echo "CLAUDE_PRESENT=$present"
echo "CLAUDE_AUTH_LOGGED_IN=$auth"
echo "CLAUDE_REMOTE_CONTROL_COMMAND_AVAILABLE=$remote"
echo "CLAUDE_REMOTE_CONTROL_ENV_COMPATIBLE=$env_ok"
echo "CLAUDE_REMOTE_CONTROL_PROBE=PASS"
