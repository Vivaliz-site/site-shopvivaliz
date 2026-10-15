#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
python3 - "$ROOT/scripts/setup-remote-control-browser-mcp.sh" <<'PY'
import pathlib,sys
source=pathlib.Path(sys.argv[1]).read_text()
must=[
    "browser_universal_source_missing_refusing_downgrade",
    "UNIVERSAL_SOURCE_DIR",
    "live-browser.mjs",
    "live-worker.mjs",
    "shopvivaliz-browser-universal-mcp.service",
    "npm ci",
]
for marker in must:
    assert marker in source, 'installer_missing:'+marker
assert source.index("browser_universal_source_missing_refusing_downgrade") < source.index('install -m 0755 "$SOURCE_SERVER"'), 'guard_must_run_before_install'
print('BROWSER_UNIVERSAL_INSTALLER_GUARD=PASS')
PY
