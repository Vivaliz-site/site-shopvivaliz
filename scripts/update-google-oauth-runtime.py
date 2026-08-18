#!/usr/bin/env python3
"""Update Google OAuth/Ads values in the shared production .env from stdin.

Input is NUL-delimited values in this order:
client_id, client_secret, optional refresh_token.
No credential value is ever printed.
"""
from __future__ import annotations

import os
import sys
import tempfile
from pathlib import Path


def main() -> int:
    if len(sys.argv) != 2:
        print("usage_error")
        return 2
    path = Path(sys.argv[1])
    if not path.is_file():
        print("env_not_found")
        return 2

    parts = sys.stdin.buffer.read().split(b"\0")
    if parts and parts[-1] == b"":
        parts.pop()
    if len(parts) not in {2, 3}:
        print("invalid_input_count")
        return 2
    values = [part.decode("utf-8") for part in parts]
    if not all(value.strip() for value in values):
        print("empty_value_rejected")
        return 2

    updates = {
        "GOOGLE_OAUTH_CLIENT_ID": values[0].strip(),
        "GOOGLE_OAUTH_CLIENT_SECRET": values[1].strip(),
    }
    if len(values) == 3:
        refresh_token = values[2].strip()
        updates["GOOGLE_OAUTH_REFRESH_TOKEN"] = refresh_token
        updates["GOOGLE_ADS_REFRESH_TOKEN"] = refresh_token

    stat = path.stat()
    lines = path.read_text(encoding="utf-8", errors="ignore").splitlines()
    pending = dict(updates)
    output: list[str] = []
    for line in lines:
        stripped = line.strip()
        key = stripped.split("=", 1)[0].strip() if stripped and not stripped.startswith("#") and "=" in stripped else ""
        if key in pending:
            output.append(f"{key}={pending.pop(key)}")
        else:
            output.append(line)
    for key, value in pending.items():
        output.append(f"{key}={value}")

    fd, temp_name = tempfile.mkstemp(prefix=".env.google-oauth.", dir=str(path.parent))
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as handle:
            handle.write("\n".join(output) + "\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temp_name, stat.st_mode & 0o777)
        if os.name != "nt":
            os.chown(temp_name, stat.st_uid, stat.st_gid)
        os.replace(temp_name, path)
    finally:
        if os.path.exists(temp_name):
            os.unlink(temp_name)

    print("GOOGLE_OAUTH_RUNTIME_UPDATED")
    print("updated_keys=" + ",".join(updates.keys()))
    print("NO_SECRET_VALUES_PRINTED")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
