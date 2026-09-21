#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
workflow="$root/.github/workflows/shopvivaliz-remote-access.yml"

test -f "$workflow"

python3 - "$workflow" <<'PY'
from pathlib import Path
import sys

text = Path(sys.argv[1]).read_text(encoding="utf-8")

if "group: shopvivaliz-remote-access" in text:
    raise SystemExit("GitHub concurrency group must not be used for remote FIFO")
if "cancel-in-progress:" in text:
    raise SystemExit("GitHub concurrency cancellation semantics must not govern remote FIFO")
if "runs-on: [self-hosted, Linux, ARM64, shopvivaliz-a1-deploy]" not in text:
    raise SystemExit("dedicated ShopVivaliz deploy runner label missing")
if "startsWith(github.event.comment.body, '/remote ')" not in text:
    raise SystemExit("remote issue-comment filter missing")
if "storage_scan" not in text:
    raise SystemExit("latest main storage_scan action was not preserved")

print("REMOTE_ACCESS_FIFO_CONTRACT=PASS")
PY
