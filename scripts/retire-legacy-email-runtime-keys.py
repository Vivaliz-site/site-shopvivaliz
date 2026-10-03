#!/usr/bin/env python3
from __future__ import annotations

import argparse
import os
import shutil
import tempfile
import time
from pathlib import Path

LEGACY_KEYS = {
    "EMAIL_FROM",
    "EMAIL_PASSWORD",
    "EMAIL_SMTP_HOST",
    "EMAIL_SMTP_PORT",
    "EMAIL_USER",
    "SMTP_HOST",
    "SMTP_PORT",
    "SMTP_USER",
    "SMTP_PASS",
    "MAIL_HOST",
    "MAIL_PORT",
    "MAIL_USER",
    "MAIL_PASS",
    "EMAIL_AGENTES_SECRET",
}

def retire(path: Path, *, apply: bool) -> list[str]:
    if not path.is_file():
        raise FileNotFoundError(path)

    original = path.stat()
    lines = path.read_text(encoding="utf-8", errors="strict").splitlines()
    removed: list[str] = []
    kept: list[str] = []

    for line in lines:
        stripped = line.strip()
        if not stripped or stripped.startswith("#") or "=" not in line:
            kept.append(line)
            continue
        key = line.split("=", 1)[0].strip()
        if key in LEGACY_KEYS:
            removed.append(key)
            continue
        kept.append(line)

    removed = sorted(set(removed))
    if not apply or not removed:
        return removed

    backup = path.with_name(f"{path.name}.pre-email-retire.{time.time_ns()}")
    shutil.copy2(path, backup)
    os.chmod(backup, 0o600)

    fd, tmp_name = tempfile.mkstemp(prefix=".email-retire.", dir=path.parent)
    tmp = Path(tmp_name)
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as handle:
            handle.write("\n".join(kept).rstrip("\n") + "\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(tmp, original.st_mode & 0o777)
        if hasattr(os, "chown"):
            os.chown(tmp, original.st_uid, original.st_gid)
        os.replace(tmp, path)
        if os.name != "nt":
            dir_fd = os.open(path.parent, os.O_RDONLY)
            try:
                os.fsync(dir_fd)
            finally:
                os.close(dir_fd)
    finally:
        tmp.unlink(missing_ok=True)

    return removed

def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("env_file", type=Path)
    parser.add_argument("--apply", action="store_true")
    args = parser.parse_args()

    removed = retire(args.env_file, apply=args.apply)
    print("apply=" + str(args.apply).lower())
    print("removed_count=" + str(len(removed)))
    print("removed_keys=" + ",".join(removed))
    print("values_exposed=false")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
