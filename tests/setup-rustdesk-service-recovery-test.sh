#!/usr/bin/env bash
set -Eeuo pipefail

bash -n scripts/setup-rustdesk-remote.sh
python3 - <<'PY'
from pathlib import Path
import re

source = Path("scripts/setup-rustdesk-remote.sh").read_text(encoding="utf-8")

def block(name: str) -> str:
    match = re.search(r"(?ms)^" + re.escape(name) + r"\(\) \{\n(.*?)^\}", source)
    assert match, f"missing function: {name}"
    return match.group(1)

recovery = block("ensure_client_recovery_policy")
assert "/etc/systemd/system/rustdesk.service.d/60-recovery.conf" in recovery
assert "Restart=on-failure" in recovery
assert "RestartSec=10s" in recovery
assert "systemctl daemon-reload" in recovery
assert "systemctl restart" not in recovery and "systemctl stop" not in recovery, "must not interrupt active RustDesk"
assert "require_root" in recovery and "assert_host" in recovery

installer = block("install_client")
assert installer.index("ensure_client_recovery_policy") < installer.index("systemctl enable --now rustdesk.service")

assert "client_recovery_ensure) ensure_client_recovery_policy" in source, "need nondisruptive standalone repair action"
assert "RUSTDESK_CLIENT_RESTART_POLICY=" in block("status"), "status must expose actual recovery policy"

print("RUSTDESK_SERVICE_RECOVERY_CONTRACT=PASS")
PY
