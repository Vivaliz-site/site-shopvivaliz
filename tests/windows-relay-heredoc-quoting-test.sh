#!/usr/bin/env bash
set -Eeuo pipefail

WF=".github/workflows/shopvivaliz-remote-access.yml"
test -f "$WF"

python3 - "$WF" <<'PY'
from pathlib import Path
import sys

text = Path(sys.argv[1]).read_text(encoding="utf-8")

bad = '''python3 - <<'PY'\nimport base64, json, os, urllib.request'''
good = '''python3 -" <<'PY'\nimport base64, json, os, urllib.request'''

if bad in text:
    raise SystemExit("WINDOWS_RELAY_HEREDOC_TEST=FAIL python_heredoc_inside_ssh_quotes")

if good not in text:
    raise SystemExit("WINDOWS_RELAY_HEREDOC_TEST=FAIL safe_stdin_heredoc_missing")

print("WINDOWS_RELAY_HEREDOC_TEST=PASS")
PY
