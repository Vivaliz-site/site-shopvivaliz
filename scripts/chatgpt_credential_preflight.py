#!/usr/bin/env python3
"""Read-only ChatGPT credential preflight; never reads secret/password values.

This probe is evidence about Chromium *metadata*, not a login or a decryption
mechanism. It is deliberately fail-closed: absent/unreadable metadata must
never be translated into permission to ask the operator for a password.
"""
from __future__ import annotations

import argparse
import json
import sqlite3
from pathlib import Path
from urllib.parse import quote

ACCOUNT_PROFILES = {
    "atendimento": (
        "atendimento@shopvivaliz.com.br",
        Path("/home/fredrdp/.config/shopvivaliz-atendimento-chromium"),
    ),
    "dev": (
        "dev@shopvivaliz.com.br",
        Path("/home/fredrdp/.config/shopvivaliz-dev-chromium"),
    ),
}

LOGIN_DATA_PATHS = (
    Path("Profile 1/Login Data"),
    Path("Default/Login Data"),
    Path("Login Data"),
)
# Query only non-secret existence metadata. Do NOT select password_value,
# cookies, encrypted payloads, or auth tokens.
SAVED_LOGIN_EXISTS_SQL = """
SELECT 1 FROM logins
WHERE username_value = ?
  AND (
    origin_url = 'https://auth.openai.com'
    OR origin_url LIKE 'https://auth.openai.com/%'
    OR signon_realm = 'https://auth.openai.com'
    OR signon_realm LIKE 'https://auth.openai.com/%'
  )
LIMIT 1
"""


def _sqlite_evidence(database: Path, account: str) -> str:
    if not database.is_file():
        return "missing"

    # An active Chromium WAL can briefly block a read-only connection. A
    # second immutable snapshot may prove PRESENCE but cannot prove ABSENCE
    # because uncheckpointed WAL entries may not be reflected in the file.
    for suffix, stale_negative in (("?mode=ro", False), ("?immutable=1", True)):
        connection = None
        try:
            uri = "file:" + quote(str(database)) + suffix
            connection = sqlite3.connect(uri, uri=True, timeout=0.5)
            found = connection.execute(SAVED_LOGIN_EXISTS_SQL, (account,)).fetchone()
            if found is not None:
                return "present"
            return "uncertain" if stale_negative else "not_matched"
        except (sqlite3.Error, OSError):
            continue
        finally:
            if connection is not None:
                connection.close()
    return "unavailable"


def inspect(session: str, *, profile_root: Path | None = None,
            keyring_socket: Path | None = None) -> dict[str, object]:
    if session not in ACCOUNT_PROFILES:
        raise ValueError("unsupported corporate ChatGPT session")
    account, canonical_profile = ACCOUNT_PROFILES[session]
    base = canonical_profile if profile_root is None else Path(profile_root)
    observations = [_sqlite_evidence(base / relative, account)
                    for relative in LOGIN_DATA_PATHS]
    present = "present" in observations
    keyring_bus = (keyring_socket if keyring_socket is not None
                   else Path("/run/user/1002/bus"))
    # The socket says only that the Keyring transport might be available.
    # It does NOT prove the store is unlocked or that a password is valid.
    return {
        "session": session,
        "account": account,
        "saved_entry": "PRESENT" if present else "NOT_VERIFIED",
        "checked_profile_locations": len(LOGIN_DATA_PATHS),
        "keyring_bus_socket": "PRESENT" if keyring_bus.is_socket() else "UNVERIFIED",
        "required_next_step": (
            "REUSE_APPROVED_BROWSER_CREDENTIAL_FLOW" if present
            else "CHECK_REMAINING_AUTHORIZED_SOURCES"
        ),
        "password_request_gate": (
            "DENY_EXISTING_CREDENTIAL" if present
            else "DENY_ABSENCE_NOT_PROVEN"
        ),
        "may_request_password": False,
        "session_authenticated": False,
        "password_or_token_accessed": False,
        "no_secret_exposure": True,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--session", choices=sorted(ACCOUNT_PROFILES), required=True)
    args = parser.parse_args()
    report = inspect(args.session)
    print(json.dumps(report, sort_keys=True, ensure_ascii=True))
    # Unknown is a hard gate, not a claim that a credential is absent.
    return 0 if report["saved_entry"] == "PRESENT" else 3


if __name__ == "__main__":
    raise SystemExit(main())
