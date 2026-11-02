#!/usr/bin/env python3
"""No-secret owner grant preflight, independent from MCP daemon and tunnel."""
from __future__ import annotations

import os
import pwd
import runpy
import socket
import sys
from pathlib import Path

HOST = "marina-a1-4c24g"
ACCOUNT = "shopvivaliz-marina-mcp"


def main() -> int:
    if socket.gethostname().split(".", 1)[0] != HOST:
        print("MARINA_WRITE_GRANT=DENIED reason=wrong_host")
        return 3
    if os.geteuid() != pwd.getpwnam(ACCOUNT).pw_uid or os.geteuid() == 0:
        print("MARINA_WRITE_GRANT=DENIED reason=wrong_service_identity")
        return 3
    try:
        operator = runpy.run_path(str(Path(__file__).with_name("trusted_write_operator.py")))
        authorized = operator["_owner_grant_valid"](
            operator["GRANT_PR"], operator["GRANT_HEAD"], operator["GRANT_MERGE"],
            operator["CAPABILITY"], operator["OPERATION"], operator["REPOSITORY"])
    except Exception:
        authorized = False
    print("MARINA_WRITE_GRANT=" + ("AUTHORIZED" if authorized else "DENIED"))
    return 0 if authorized else 3


if __name__ == "__main__":
    sys.exit(main())
