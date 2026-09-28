#!/usr/bin/env bash
set -Eeuo pipefail

WORKFLOW=".github/workflows/oci-bastion-private-access-bootstrap.yml"
test -f "$WORKFLOW"

python3 - "$WORKFLOW" <<'PY'
from pathlib import Path
import sys

text = Path(sys.argv[1]).read_text(encoding="utf-8")

probe = """if "${SSH_BACKEND[@]}" "timeout 5 bash -c '</dev/tcp/127.0.0.1/2222'"; then"""
forward = """-L "$FRED_TUNNEL_PORT:127.0.0.1:2222" ubuntu@127.0.0.1"""
fallback = """FRED_IP="$("${SSH_BACKEND[@]}" 'tailscale status --json'"""

missing = [name for name, needle in [
    ("reverse_2222_probe", probe),
    ("reverse_2222_forward", forward),
    ("tailscale_fallback", fallback),
] if needle not in text]

if missing:
    raise SystemExit("FREDWIN_RUSTDESK_REVERSE_SSH_TEST=FAIL missing=" + ",".join(missing))

if not (text.index(probe) < text.index(forward) < text.index(fallback)):
    raise SystemExit("FREDWIN_RUSTDESK_REVERSE_SSH_TEST=FAIL reverse_path_not_primary")

print("FREDWIN_RUSTDESK_REVERSE_SSH_TEST=PASS")
PY
