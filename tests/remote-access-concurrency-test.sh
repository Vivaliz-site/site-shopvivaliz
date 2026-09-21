#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
workflow="$root/.github/workflows/shopvivaliz-remote-access.yml"

test -f "$workflow"

python3 - "$workflow" <<'PY'
from pathlib import Path
import sys

text = Path(sys.argv[1]).read_text(encoding="utf-8")
jobs_pos = text.index("jobs:\n")
execute_pos = text.index("  execute:\n", jobs_pos)
concurrency_pos = text.index("    concurrency:\n", execute_pos)
runs_on_pos = text.index("    runs-on:", execute_pos)

if concurrency_pos > runs_on_pos:
    raise SystemExit("job concurrency must be declared before runs-on")
if "concurrency:\n  group: shopvivaliz-remote-access" in text[:jobs_pos]:
    raise SystemExit("workflow-level remote concurrency still present")
if "      group: shopvivaliz-remote-access" not in text[concurrency_pos:runs_on_pos]:
    raise SystemExit("remote job concurrency group missing")
if "      cancel-in-progress: false" not in text[concurrency_pos:runs_on_pos]:
    raise SystemExit("remote job concurrency policy missing")
if "startsWith(github.event.comment.body, '/remote ')" not in text:
    raise SystemExit("remote issue-comment filter missing")

print("REMOTE_ACCESS_JOB_CONCURRENCY_CONTRACT=PASS")
PY
