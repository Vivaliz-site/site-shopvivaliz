#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT="scripts/setup-rustdesk-remote.sh"
test -f "$SCRIPT"

python3 - "$SCRIPT" <<'PY'
from pathlib import Path
import sys

text = Path(sys.argv[1]).read_text(encoding="utf-8")

if "depends_on:\n      - hbbr" in text:
    raise SystemExit("RUSTDESK_RELAY_KEY_ORDER_TEST=FAIL hbbs_depends_on_hbbr")

hbbs = 'docker compose -f "$SERVER_ROOT/compose.yml" up -d hbbs'
key = '[ -s "$SERVER_ROOT/data/id_ed25519.pub" ] || die server_key_not_generated 41'
hbbr = 'docker compose -f "$SERVER_ROOT/compose.yml" up -d hbbr'

missing = [needle for needle in (hbbs, key, hbbr) if needle not in text]
if missing:
    raise SystemExit("RUSTDESK_RELAY_KEY_ORDER_TEST=FAIL missing=" + ",".join(missing))

if not (text.index(hbbs) < text.index(key) < text.index(hbbr)):
    raise SystemExit("RUSTDESK_RELAY_KEY_ORDER_TEST=FAIL invalid_startup_order")

print("RUSTDESK_RELAY_KEY_ORDER_TEST=PASS")
PY
