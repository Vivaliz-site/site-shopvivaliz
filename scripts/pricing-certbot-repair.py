#!/usr/bin/env python3
"""Persist a scoped DNS-01 token securely without ever logging or exporting it.

Reads the token once from stdin in install mode (not from argv or file).
Updates only the dns_cloudflare_credentials reference in the expected renewal
configuration, with an atomic backup. Does not contact Cloudflare or change DNS.
"""
from __future__ import annotations

import argparse
import os
from pathlib import Path
import re
import stat
import sys
import tempfile

DOMAIN = "pricing.shopvivaliz.com.br"
CREDS_REL = Path("etc/letsencrypt/cloudflare/shopvivaliz-pricing.ini")
RENEWAL_REL = Path("etc/letsencrypt/renewal") / f"{DOMAIN}.conf"
LINE_RE = re.compile(r"(?m)^(\s*dns_cloudflare_credentials\s*=\s*)([^\r\n]*)$")


class GateFailure(RuntimeError):
    pass


def _assert_target_paths(root: Path) -> tuple[Path, Path]:
    credential = root / CREDS_REL
    renewal = root / RENEWAL_REL
    if credential.is_symlink() or credential.parent.is_symlink() or renewal.is_symlink():
        raise GateFailure("path_symlink_refused")
    return credential, renewal


def _atomic_write(path: Path, data: bytes, mode: int) -> None:
    fd, name = tempfile.mkstemp(prefix=f".{path.name}.", dir=str(path.parent))
    try:
        os.fchmod(fd, mode)
        with os.fdopen(fd, "wb") as h:
            h.write(data)
            h.flush()
            os.fsync(h.fileno())
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def _configured_credentials_reference(renewal: Path) -> tuple[str, str]:
    config = renewal.read_text(encoding="utf-8")
    lines = list(LINE_RE.finditer(config))
    if len(lines) != 1:
        raise GateFailure("renewal_credential_reference_not_unique")
    return config, lines[0].group(2).strip()


def verify(root: Path, allow_new: bool = False) -> str:
    cred, renewal = _assert_target_paths(root)
    if not cred.is_file():
        raise GateFailure("persistent_credential_file_missing")
    if stat.S_IMODE(cred.stat().st_mode) != 0o600:
        raise GateFailure("credential_mode_not_0600")
    if stat.S_IMODE(cred.parent.stat().st_mode) != 0o700:
        raise GateFailure("credential_directory_mode_not_0700")
    if not renewal.is_file():
        if allow_new:
            return "FIRST_ISSUANCE_PENDING"
        raise GateFailure("renewal_configuration_missing")
    _, configured = _configured_credentials_reference(renewal)
    if configured != str(cred):
        raise GateFailure("renewal_points_to_other_credential_file")
    return "RENEWAL_PATH_READY"


def repair(root: Path, token: bytes, allow_new: bool = False) -> str:
    cred, renewal = _assert_target_paths(root)
    if not token or len(token) > 4096 or any(ch < 33 or ch > 126 for ch in token):
        raise GateFailure("token_missing_or_invalid_format")
    original = None
    if renewal.is_file():
        original, _ = _configured_credentials_reference(renewal)
    elif not allow_new:
        raise GateFailure("renewal_configuration_missing")
    cred.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    os.chmod(cred.parent, 0o700)
    _atomic_write(cred, b"dns_cloudflare_api_token = " + token + b"\n", 0o600)
    if original is not None:
        replacement = LINE_RE.sub(lambda m: m.group(1) + str(cred), original)
        if replacement != original:
            backup = renewal.with_name(renewal.name + ".before-persistent-dns-credentials")
            if not backup.exists():
                fd = os.open(backup, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
                with os.fdopen(fd, "wb") as h:
                    h.write(original.encode("utf-8"))
                    h.flush()
                    os.fsync(h.fileno())
            mode = stat.S_IMODE(renewal.stat().st_mode)
            _atomic_write(renewal, replacement.encode("utf-8"), mode)
    return verify(root, allow_new=allow_new)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--verify-only", action="store_true")
    parser.add_argument("--allow-new", action="store_true")
    ns = parser.parse_args()
    try:
        if os.geteuid() != 0:
            raise GateFailure("root_required")
        if ns.verify_only:
            status = verify(Path("/"), allow_new=ns.allow_new)
        else:
            token = sys.stdin.buffer.read(4097)
            status = repair(Path("/"), token, allow_new=ns.allow_new)
        print(f"PRICING_CERTBOT_CREDENTIAL_STATUS={status}")
        return 0
    except (GateFailure, OSError, UnicodeError) as exc:
        code = str(exc) if isinstance(exc, GateFailure) else "filesystem_operation_failed"
        print(f"PRICING_CERTBOT_REPAIR_FAILED={code}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
