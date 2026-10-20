#!/usr/bin/env python3
"""Fail-closed, read-only provisioning inspection for Marina's independent MCP.

No credentials are opened/read, no services are mutated, no tunnel is started.
This cannot authorize installation or certify ChatGPT connectivity.
"""
from __future__ import annotations

import argparse
import json
import os
import pwd
import socket
import stat
import subprocess
from pathlib import Path

EXPECTED_HOST = "marina-a1-4c24g"
SERVICE_USER = "shopvivaliz-marina-mcp"
UNITS = (
    "shopvivaliz-marina-mcp.service",
    "shopvivaliz-marina-mcp-tunnel.service",
)
SECRET_SIZES = {
    "mcp_token": ("auth-token", 32, 256),
    "mcp_local_auth_header": ("auth-header", 39, 270),
    "openai_runtime_key": ("runtime-key", 20, 4096),
    "tunnel_profile": ("tunnel-profile.yaml", 40, 8192),
}
BINARY_PATH = Path("/opt/shopvivaliz-marina-mcp/bin/tunnel-client")


def protected_file_status(path: Path, owner_uid: int, lower: int, upper: int) -> str:
    """Inspect only metadata. Never open, follow or read a credential."""
    try:
        meta = path.lstat()
    except FileNotFoundError:
        return "not_provisioned"
    except OSError:
        return "metadata_unavailable"
    if not stat.S_ISREG(meta.st_mode) or stat.S_ISLNK(meta.st_mode):
        return "unsafe_file_type"
    if meta.st_uid != owner_uid or stat.S_IMODE(meta.st_mode) != 0o600:
        return "ownership_or_mode_unsafe"
    if not lower <= meta.st_size <= upper:
        return "unexpected_size"
    return "metadata_ok_not_auth_tested"


def parse_listener_status(ss_output: str, port: int = 5580) -> str:
    """Identify wildcard/IPv6 leakage without querying the application."""
    addresses = []
    for line in ss_output.splitlines():
        columns = line.split()
        if len(columns) < 4:
            continue
        address = columns[3]
        if address.endswith(":" + str(port)):
            addresses.append(address)
    if not addresses:
        return "not_listening"
    if all(addr == f"127.0.0.1:{port}" for addr in addresses):
        return "loopback_only"
    return "unsafe_non_loopback_or_ipv6"


def unit_snapshot(unit: str) -> dict[str, str]:
    if unit not in UNITS:
        raise ValueError("unit_not_allowlisted")
    try:
        proc = subprocess.run(
            ["/usr/bin/systemctl", "show", unit, "--no-pager",
             "--property=LoadState,ActiveState,UnitFileState,NoNewPrivileges,User"],
            capture_output=True, text=True, timeout=4, check=False,
        )
    except (OSError, subprocess.TimeoutExpired):
        return {"state": "probe_unavailable"}
    if proc.returncode != 0:
        return {"state": "probe_unavailable"}
    allowed = {"LoadState", "ActiveState", "UnitFileState", "NoNewPrivileges", "User"}
    data = {}
    for line in proc.stdout[:2048].splitlines():
        key, delim, value = line.partition("=")
        if delim and key in allowed:
            data[key] = value[:80]
    return data


def binary_status(path: Path, current_uid: int) -> str:
    try:
        info = path.lstat()
    except FileNotFoundError:
        return "not_installed"
    except OSError:
        return "metadata_unavailable"
    if (not stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode)
            or info.st_uid not in (0, current_uid)
            or stat.S_IMODE(info.st_mode) & 0o022
            or not stat.S_IMODE(info.st_mode) & 0o111):
        return "unsafe_binary_metadata"
    return "metadata_ok_version_unverified"


def collect(home: Path | None = None) -> dict:
    current_uid = os.geteuid()
    host = socket.gethostname().split(".")[0]
    account = pwd.getpwuid(current_uid)
    home = home if home is not None else Path(account.pw_dir)
    secrets_root = home / ".config" / "shopvivaliz-marina-mcp"
    try:
        root_meta = secrets_root.lstat()
        root_safe = (
            stat.S_ISDIR(root_meta.st_mode) and not stat.S_ISLNK(root_meta.st_mode)
            and root_meta.st_uid == current_uid
            and stat.S_IMODE(root_meta.st_mode) == 0o700
        )
    except OSError:
        root_safe = False
    secrets = {
        label: protected_file_status(secrets_root / filename, current_uid, lower, upper)
        for label, (filename, lower, upper) in SECRET_SIZES.items()
    }
    try:
        listen = subprocess.run(
            ["/usr/bin/ss", "-ltnH"], capture_output=True, text=True, timeout=4,
            check=False,
        )
        binding = parse_listener_status(listen.stdout) if listen.returncode == 0 else "probe_unavailable"
    except (OSError, subprocess.TimeoutExpired):
        binding = "probe_unavailable"
    if not root_safe:
        secrets = {key: "secret_directory_unsafe_or_missing" for key in secrets}
    units = {unit: unit_snapshot(unit) for unit in UNITS}
    source = Path(__file__).with_name("server.py")
    source_staged = source.is_file()
    unit_ready = all(
        value.get("LoadState") == "loaded"
        and value.get("ActiveState") == "active"
        and value.get("UnitFileState") == "enabled"
        and value.get("NoNewPrivileges") == "yes"
        and value.get("User") == SERVICE_USER
        for value in units.values()
    )
    candidate = (
        host == EXPECTED_HOST and current_uid != 0
        and account.pw_name == SERVICE_USER
        and root_safe and source_staged
        and all(v == "metadata_ok_not_auth_tested" for v in secrets.values())
        and binary_status(BINARY_PATH, current_uid) == "metadata_ok_version_unverified"
        and binding == "loopback_only" and unit_ready
    )
    return {
        "host_identity": "marina_verified" if host == EXPECTED_HOST else "host_mismatch",
        "privilege": "dedicated_unprivileged_user" if current_uid != 0 and account.pw_name == SERVICE_USER else "wrong_service_identity",
        "source_staged": source_staged,
        "secrets_metadata": secrets,
        "client_binary": binary_status(BINARY_PATH, current_uid),
        "port_5580": binding,
        "systemd": units,
        "local_preflight_candidate_only": candidate,
        "tunnel_authenticated": "not_tested",
        "chatgpt_mcp_connected": "not_tested",
        "migration_status": "NO_CUT_NAO_APTO",
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--require-local-ready", action="store_true",
                        help="Return nonzero unless every LOCAL metadata/runtime check passed")
    args = parser.parse_args()
    data = collect()
    print(json.dumps(data, sort_keys=True, separators=(",", ":")))
    if args.require_local_ready and not data["local_preflight_candidate_only"]:
        return 3
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
