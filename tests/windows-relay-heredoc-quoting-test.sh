#!/usr/bin/env bash
set -Eeuo pipefail

WF=".github/workflows/shopvivaliz-remote-access.yml"
test -f "$WF"

python3 - "$WF" <<'PY'
from pathlib import Path
import re, sys

text = Path(sys.argv[1]).read_text(encoding="utf-8")

bad = re.compile(r'''python3 - <<'PY'\s*\n\s*import base64, json, os, urllib\.request''')
good = re.compile(r'''python3 -" <<'PY'\s*\n\s*import base64, json, os, urllib\.request''')

if bad.search(text):
    raise SystemExit("WINDOWS_RELAY_HEREDOC_TEST=FAIL python_heredoc_inside_ssh_quotes")

if not good.search(text):
    raise SystemExit("WINDOWS_RELAY_HEREDOC_TEST=FAIL safe_stdin_heredoc_missing")

print("WINDOWS_RELAY_HEREDOC_TEST=PASS")
PY
