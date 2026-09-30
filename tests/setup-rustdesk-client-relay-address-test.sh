#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT="scripts/setup-rustdesk-remote.sh"
test -f "$SCRIPT"

python3 - "$SCRIPT" <<'PY'
from pathlib import Path
import sys

text = Path(sys.argv[1]).read_text(encoding="utf-8")

required = [
    "CLIENT_RELAY_SERVER=",
    "RUSTDESK_RELAY_SERVER",
    "SERVER_TAILSCALE_IP",
    "local relay_server=\"$4\"",
    "relay-server = '$relay_server'",
]
missing = [x for x in required if x not in text]
if missing:
    raise SystemExit("RUSTDESK_CLIENT_RELAY_ADDRESS_TEST=FAIL missing=" + ",".join(missing))

if "relay-server = '$server'" in text:
    raise SystemExit("RUSTDESK_CLIENT_RELAY_ADDRESS_TEST=FAIL relay_reuses_id_server")

print("RUSTDESK_CLIENT_RELAY_ADDRESS_TEST=PASS")
PY
