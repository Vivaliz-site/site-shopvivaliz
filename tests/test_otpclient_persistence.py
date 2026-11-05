#!/usr/bin/env python3
"""Fail-closed OTPClient boot persistence contract; never inspect MFA secrets."""
from pathlib import Path
import os
import re
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


def _script_lines():
    return SCRIPT.read_text(encoding="utf-8").splitlines()


def test_non_root_run_is_refused_before_touching_anything():
    """Behavioral: without root the script must stop at the first guard (exit 2)."""
    if os.geteuid() == 0:
        return  # running as root would execute the installer; skip by design
    result = subprocess.run(["bash", str(SCRIPT)], capture_output=True, text=True, timeout=20)
    assert result.returncode == 2, (result.returncode, result.stderr)
    assert result.stderr.strip() == "ERROR=root_required"
    assert result.stdout == ""


def test_error_tokens_and_exit_codes_are_distinct_and_secret_free():
    text = SCRIPT.read_text(encoding="utf-8")
    pairs = re.findall(r'echo "ERROR=([a-z_]+)" >&2; exit (\d+)', text)
    assert len(pairs) >= 10, pairs
    tokens = [t for t, _ in pairs]
    codes = [c for _, c in pairs]
    assert len(set(tokens)) == len(tokens), "duplicate error tokens"
    assert len(set(codes)) == len(codes), "duplicate exit codes"
    for token in tokens:
        assert not re.search(r"(secret|otp_code|password|totp_seed)", token), token


def test_vault_content_is_never_read_or_printed():
    for line in _script_lines():
        stripped = line.strip()
        if stripped.startswith("#") or "otpclient.enc" not in stripped:
            continue
        # The only allowed vault use is an existence/size check.
        assert re.match(r'test -s /home/fredrdp/\.local/share/otpclient\.enc \|\|', stripped), stripped
    text = SCRIPT.read_text(encoding="utf-8")
    for reader in ("cat ", "xxd", "base64", "openssl", "strings ", "cp ", "hexdump"):
        assert not re.search(r"\b" + re.escape(reader) + r"[^\n]*otpclient\.enc", text), reader


def test_all_guards_run_before_any_system_mutation():
    lines = _script_lines()
    def first(pred):
        for i, l in enumerate(lines):
            if pred(l.strip()):
                return i
        raise AssertionError("expected guard or mutation line not found in script")
    vault_guard = first(lambda l: "protected_vault_unavailable" in l)
    mutations = [
        first(lambda l: l.startswith("install -m")),
        first(lambda l: l.startswith("mv -f")),
        first(lambda l: l.startswith("systemctl daemon-reload")),
        first(lambda l: l.startswith("systemctl enable")),
    ]
    assert all(vault_guard < m for m in mutations), (vault_guard, mutations)
    root_guard = first(lambda l: "root_required" in l)
    assert root_guard < vault_guard


def test_success_output_contains_only_status_tokens():
    allowed = re.compile(
        r'^echo "(ERROR=[a-z_]+|SHOPVIVALIZ_OTPCLIENT_PERSISTENCE=PASS|OTPCLIENT_VAULT_UNLOCK_STATUS=NOT_VERIFIED)"( >&2)?;?'
    )
    echoes = [l.strip() for l in _script_lines() if l.strip().startswith("echo ") or "echo \"ERROR=" in l]
    for line in echoes:
        for chunk in re.findall(r'echo "[^"]*"(?: >&2)?', line):
            assert allowed.match(chunk), chunk

if __name__ == "__main__":
    test_otpclient_is_bound_to_isolated_gui_and_encrypted_vault()
    test_bootstrap_validates_protected_source_without_reading_secrets()
    test_non_root_run_is_refused_before_touching_anything()
    test_error_tokens_and_exit_codes_are_distinct_and_secret_free()
    test_vault_content_is_never_read_or_printed()
    test_all_guards_run_before_any_system_mutation()
    test_success_output_contains_only_status_tokens()
    print("OTPCLIENT_PERSISTENCE_CONTRACT=PASS")
