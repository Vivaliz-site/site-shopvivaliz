#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT="scripts/setup-rustdesk-remote.sh"
test -f "$SCRIPT"

python3 - "$SCRIPT" <<'PY'
from pathlib import Path
import sys

text = Path(sys.argv[1]).read_text(encoding="utf-8")
needle = "command: hbbr -k _"
if needle not in text:
    raise SystemExit("RUSTDESK_HBBR_KEY_MODE_TEST=FAIL missing_hbbr_key_mode")
print("RUSTDESK_HBBR_KEY_MODE_TEST=PASS")
PY
