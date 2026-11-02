#!/usr/bin/env python3
"""Create Marina's independent LOCAL MCP bearer pair without exposing either secret.

This does not create or import an OpenAI Platform runtime key, activate a service,
register a connector, change Unix users or grant administrative capabilities.
"""
from __future__ import annotations

import os
import pwd
import secrets
import socket
import stat
import sys
from pathlib import Path

HOST = "marina-a1-4c24g"
SERVICE_ACCOUNT = "shopvivaliz-marina-mcp"
HOME = Path("/var/lib/shopvivaliz-marina-mcp")
PRIVATE = HOME / ".config" / SERVICE_ACCOUNT
FILES = ("auth-token", "auth-header")


def private_directory_ok(path: Path, uid: int) -> bool:
    try:
        info = path.lstat()
    except OSError:
        return False
    return (stat.S_ISDIR(info.st_mode) and not stat.S_ISLNK(info.st_mode)
            and info.st_uid == uid and stat.S_IMODE(info.st_mode) == 0o700)


def bootstrap(*, home: Path = HOME, host: str | None = None, uid: int | None = None) -> str:
    host = host if host is not None else socket.gethostname().split(".", 1)[0]
    uid = uid if uid is not None else os.geteuid()
    if host != HOST:
        return "REFUSED_WRONG_HOST"
    try:
        expected_uid = pwd.getpwnam(SERVICE_ACCOUNT).pw_uid
    except KeyError:
        return "REFUSED_SERVICE_USER_MISSING"
    if uid != expected_uid or uid == 0:
        return "REFUSED_WRONG_IDENTITY"
    private = home / ".config" / SERVICE_ACCOUNT
    if not private_directory_ok(home, uid) or not private_directory_ok(home / ".config", uid) or not private_directory_ok(private, uid):
        return "REFUSED_PRIVATE_DIRECTORY_UNSAFE"
    if any(os.path.lexists(private / name) for name in FILES):
        return "REFUSED_EXISTING_OR_PARTIAL_SECRET_PAIR"
    token = secrets.token_hex(32).encode("ascii")
    values = (("auth-token", token), ("auth-header", b"Bearer " + token))
    created: list[Path] = []
    try:
        for filename, content in values:
            target = private / filename
            fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
            created.append(target)
            with os.fdopen(fd, "wb") as stream:
                stream.write(content)
                stream.flush()
                os.fsync(stream.fileno())
            info = target.lstat()
            if not stat.S_ISREG(info.st_mode) or info.st_uid != uid or stat.S_IMODE(info.st_mode) != 0o600:
                raise OSError("unexpected_file_metadata")
    except OSError:
        for target in reversed(created):
            try:
                target.unlink()
            except OSError:
                pass
        return "REFUSED_WRITE_FAILED_NO_SECRET_EXPORTED"
    return "LOCAL_AUTH_PAIR_CREATED_NO_TUNNEL_KEY"


def main() -> int:
    result = bootstrap()
    print("MARINA_MCP_LOCAL_AUTH=" + result)
    return 0 if result == "LOCAL_AUTH_PAIR_CREATED_NO_TUNNEL_KEY" else 3


if __name__ == "__main__":
    sys.exit(main())
