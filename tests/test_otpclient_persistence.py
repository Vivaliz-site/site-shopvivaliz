#!/usr/bin/env python3
"""Fail-closed OTPClient boot persistence contract; never inspect MFA secrets."""
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[1]
UNIT = ROOT / "ops/systemd/shopvivaliz-otpclient.service"
SCRIPT = ROOT / "scripts/setup-shopvivaliz-otpclient.sh"

def test_otpclient_is_bound_to_isolated_gui_and_encrypted_vault():
    source = UNIT.read_text(encoding="utf-8")
    for required in (
        "User=fredrdp", "Group=fredrdp", "Environment=DISPLAY=:99",
        "Environment=XAUTHORITY=/home/fredrdp/.Xauthority",
        "ExecStartPre=/usr/bin/test -S /tmp/.X11-unix/X99",
        "ExecStartPre=/usr/bin/test -s /home/fredrdp/.local/share/otpclient.enc",
        "ExecStart=/usr/bin/otpclient", "NoNewPrivileges=true",
        "UMask=0077", "ProtectSystem=full", "PrivateTmp=false",
        "Restart=on-failure", "StartLimitIntervalSec=0",
        "WantedBy=multi-user.target",
    ):
        assert required in source, required
    assert "Password=" not in source
    assert "SECRET=" not in source
    assert "OTP=" not in source
    assert "ExecStartPost=" not in source

def test_bootstrap_validates_protected_source_without_reading_secrets():
    text = SCRIPT.read_text(encoding="utf-8")
    assert "flock -n 9" in text
    assert "id -u fredrdp" in text
    assert "systemctl enable" in text
    assert "systemctl is-active --quiet" in text
    assert "protected_vault_unavailable" in text
    assert "OTPCLIENT_VAULT_UNLOCK_STATUS=NOT_VERIFIED" in text
    assert "cat /home/fredrdp/.local/share/otpclient.enc" not in text
    result = subprocess.run(["bash", "-n", str(SCRIPT)], capture_output=True, text=True)
    assert result.returncode == 0, result.stderr

if __name__ == "__main__":
    test_otpclient_is_bound_to_isolated_gui_and_encrypted_vault()
    test_bootstrap_validates_protected_source_without_reading_secrets()
    print("OTPCLIENT_PERSISTENCE_CONTRACT=PASS")
