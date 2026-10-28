#!/usr/bin/env bash
# Regression: browser MCPs wanted by multi-user.target cannot be ordered after
# graphical.target, which itself is ordered after multi-user.target.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
for name in shopvivaliz-browser-atendimento-mcp shopvivaliz-browser-dev-mcp shopvivaliz-remote-control-browser-mcp; do
  unit="$ROOT/deploy/systemd/$name.service"
  test -f "$unit"
  grep -Fxq 'WantedBy=multi-user.target' "$unit"
  after="$(grep '^After=' "$unit")"
  [[ "$after" == *'network-online.target'* ]]
  [[ "$after" == *'shopvivaliz-remote-control-mcp.service'* ]]
  if [[ " $after " == *' graphical.target '* ]]; then
    echo "FAIL: $name creates a graphical.target / multi-user.target ordering cycle" >&2
    exit 1
  fi
  echo "PASS: $name has no graphical.target boot cycle"
done
