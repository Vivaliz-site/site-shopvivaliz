#!/usr/bin/env python3
"""Atomically install a one-time Google Ads refresh token into shared .env.

The token never leaves the production VM and is never printed.
"""
from __future__ import annotations

import os
import stat
import sys
import tempfile
from pathlib import Path


def validate_pending_token_path(token_path: Path) -> None:
    if token_path.is_symlink():
        print("pending_token_symlink_rejected")
        raise SystemExit(2)
    st = token_path.stat()
    if not stat.S_ISREG(st.st_mode):
        print("pending_token_not_regular_file")
        raise SystemExit(2)
    if os.name != "nt" and (st.st_mode & 0o077):
        print("pending_token_permissions_too_open")
        raise SystemExit(2)


def main() -> int:
    if len(sys.argv) != 3:
        print("usage_error")
        return 2
    env_path = Path(sys.argv[1])
    token_path = Path(sys.argv[2])
    if not env_path.is_file() or not token_path.is_file():
        print("required_file_missing")
        return 2

    validate_pending_token_path(token_path)

    token = token_path.read_text(encoding="utf-8", errors="strict").strip()
    if len(token) < 20 or any(ch.isspace() for ch in token):
        print("pending_token_invalid")
        return 2

    stat_result = env_path.stat()
    lines = env_path.read_text(encoding="utf-8", errors="ignore").splitlines()
    output: list[str] = []
    replaced = False
    for line in lines:
        if line.strip().startswith("GOOGLE_ADS_REFRESH_TOKEN="):
            output.append("GOOGLE_ADS_REFRESH_TOKEN=" + token)
            replaced = True
        else:
            output.append(line)
    if not replaced:
        output.append("GOOGLE_ADS_REFRESH_TOKEN=" + token)

    fd, temp_name = tempfile.mkstemp(prefix=".env.google-ads.", dir=str(env_path.parent))
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as handle:
            handle.write("\n".join(output) + "\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temp_name, stat_result.st_mode & 0o777)
        if os.name != "nt":
            os.chown(temp_name, stat_result.st_uid, stat_result.st_gid)
        os.replace(temp_name, env_path)
    finally:
        if os.path.exists(temp_name):
            os.unlink(temp_name)

    token_path.unlink(missing_ok=True)
    print("GOOGLE_ADS_REFRESH_TOKEN_INSTALLED_SERVER_SIDE")
    print("NO_SECRET_VALUES_PRINTED")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
