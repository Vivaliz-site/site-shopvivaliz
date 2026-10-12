#!/usr/bin/env python3
"""Bounded Gmail metadata-only watch during official corporate ChatGPT login.

No OTP value/body is fetched, transferred or logged. This module is disabled
unless the backend has its OWN independently authorized Gmail readonly grant.
"""

from __future__ import annotations

import email.utils
import fcntl
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from typing import Callable

WATCH_SECONDS = 600
POLL_SECONDS = 15
MAILBOX = "fredmourao@gmail.com"
READONLY_SCOPE = "https://www.googleapis.com/auth/gmail.readonly"
OAUTH_FILE = Path(os.environ.get(
    "SHOPVIVALIZ_GMAIL_READONLY_OAUTH_FILE",
    "/var/lib/shopvivaliz-remote-control/gmail-login-readonly-oauth.json",
))
STATE_DIR = Path("/var/lib/shopvivaliz-remote-control/email-login-watch")
ACCOUNTS = {
    "dev": "dev@shopvivaliz.com.br",
    "atendimento": "atendimento@shopvivaliz.com.br",
}
SENDERS = frozenset({"noreply@tm.openai.com", "otp@tm1.openai.com"})
SUBJECTS = frozenset({
    "your temporary chatgpt login code",
    "your chatgpt verification code",
})


def _read_private_grant(path: Path) -> tuple[dict[str, str] | None, str]:
    """Require dedicated, private, non-symlink OAuth material, never print it."""
    try:
        fd = os.open(str(path), os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
    except FileNotFoundError:
        return None, "oauth_not_provisioned"
    except OSError:
        return None, "credentials_unavailable"
    try:
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode)
                or info.st_uid != os.geteuid()
                or info.st_mode & 0o077):
            return None, "credentials_permissions_invalid"
        if info.st_size < 5 or info.st_size > 16_384:
            return None, "credentials_invalid"
        data = os.read(fd, 16_385)
        try:
            cfg = json.loads(data)
        except (ValueError, UnicodeError):
            return None, "credentials_invalid"
        if not isinstance(cfg, dict):
            return None, "credentials_invalid"
        if str(cfg.get("mailbox", "")).strip().lower() != MAILBOX:
            return None, "mailbox_mismatch"
        scopes = str(cfg.get("scope", "")).split()
        if READONLY_SCOPE not in scopes:
            return None, "readonly_scope_required"
        required = ("client_id", "client_secret", "refresh_token")
        if not all(isinstance(cfg.get(k), str) and cfg[k].strip() for k in required):
            return None, "credentials_incomplete"
        return cfg, "ready"
    finally:
        os.close(fd)


def credential_readiness(path: Path = OAUTH_FILE) -> dict[str, str]:
    _, status = _read_private_grant(path)
    return {"status": status}


def match_metadata(message: dict, session: str, start_epoch: int) -> str | None:
    """Match only freshly received official metadata for the exact account."""
    if session not in ACCOUNTS or not isinstance(message, dict):
        return None
    message_id = str(message.get("id", ""))
    if not re.fullmatch(r"[A-Za-z0-9_-]{3,256}", message_id):
        return None
    try:
        received = int(str(message.get("internalDate", ""))) // 1000
    except (ValueError, TypeError):
        return None
    if received < start_epoch or received >= start_epoch + WATCH_SECONDS + 30:
        return None
    payload = message.get("payload") or {}
    if not isinstance(payload, dict):
        return None
    headers: dict[str, str] = {}
    for item in payload.get("headers", []):
        if not isinstance(item, dict):
            continue
        name = str(item.get("name", "")).casefold().strip()
        if name in {"from", "to", "subject"}:
            headers[name] = str(item.get("value", ""))
    sender = email.utils.parseaddr(headers.get("from", ""))[1].casefold()
    recipients = {addr.casefold() for _, addr in email.utils.getaddresses([headers.get("to", "")])}
    subject = " ".join(headers.get("subject", "").casefold().split())
    if sender not in SENDERS or ACCOUNTS[session] not in recipients or subject not in SUBJECTS:
        return None
    return message_id


def watch_window(
    session: str,
    *,
    start_epoch: int,
    fetch: Callable[[str, int], list[dict]],
    monotonic: Callable[[], float] = time.monotonic,
    sleep: Callable[[float], None] = time.sleep,
    report: Callable[[dict], None] | None = None,
) -> dict:
    """Check each 15s, terminate after first match or at 600s, no retry loop."""
    if session not in ACCOUNTS:
        raise ValueError("account_scope_invalid")
    deadline = monotonic() + WATCH_SECONDS
    while monotonic() < deadline:
        try:
            messages = fetch(session, start_epoch)
        except Exception:
            result = {"status": "gmail_read_failed", "session": session}
            if report is not None:
                report(result)
            return result
        for data in messages:
            found = match_metadata(data, session, start_epoch)
            if found:
                result = {"status": "matched", "session": session,
                          "message_id": found, "received_at": int(data["internalDate"]) // 1000}
                if report is not None:
                    report(result)
                return result
        remaining = deadline - monotonic()
        if remaining > 0:
            sleep(min(POLL_SECONDS, remaining))
    result = {"status": "timeout", "session": session}
    if report is not None:
        report(result)
    return result


def _request_json(url: str, headers: dict[str, str], body: bytes | None = None) -> dict:
    """Fixed Google endpoints only. Never expose HTTP error bodies or headers."""
    parts = urllib.parse.urlsplit(url)
    if parts.scheme != "https" or parts.hostname not in {
        "gmail.googleapis.com", "oauth2.googleapis.com"
    }:
        raise ValueError("gmail_endpoint_not_allowed")
    req = urllib.request.Request(url, data=body, headers=headers,
                                 method="POST" if body is not None else "GET")
    try:
        with urllib.request.urlopen(req, timeout=5) as response:
            raw = response.read(65_536)
            return json.loads(raw)
    except (OSError, ValueError, urllib.error.HTTPError):
        raise RuntimeError("gmail_api_unavailable") from None


class GmailMetadataReader:
    """Only list ids and fetch header metadata; never Gmail format=full."""
    def __init__(self, access_token: str, request_json: Callable = _request_json):
        self._auth = {"Authorization": "Bearer " + access_token,
                      "Accept": "application/json"}
        self._request_json = request_json
        self._seen: set[str] = set()

    def verify_mailbox(self) -> str:
        url = "https://gmail.googleapis.com/gmail/v1/users/me/profile?fields=emailAddress"
        data = self._request_json(url, self._auth)
        email = str(data.get("emailAddress", "")).casefold()
        if email != MAILBOX:
            raise ValueError("gmail_mailbox_mismatch")
        return email

    def fetch(self, session: str, start_epoch: int) -> list[dict]:
        if session not in ACCOUNTS:
            raise ValueError("account_scope_invalid")
        query = (f'after:{start_epoch} to:{ACCOUNTS[session]} '
                 'from:(noreply@tm.openai.com OR otp@tm1.openai.com) '
                 '-in:trash')
        params = urllib.parse.urlencode({"q": query, "maxResults": 20,
                                        "fields": "messages/id"})
        url = "https://gmail.googleapis.com/gmail/v1/users/me/messages?" + params
        listings = self._request_json(url, self._auth)
        matches = []
        for item in (listings.get("messages") or [])[:20]:
            message_id = str(item.get("id", ""))
            if (not re.fullmatch(r"[A-Za-z0-9_-]{3,256}", message_id)
                    or message_id in self._seen):
                continue
            self._seen.add(message_id)
            headers = urllib.parse.urlencode([
                ("format", "metadata"),
                ("metadataHeaders", "From"),
                ("metadataHeaders", "To"),
                ("metadataHeaders", "Subject"),
                ("fields", "id,internalDate,payload/headers"),
            ])
            endpoint = ("https://gmail.googleapis.com/gmail/v1/users/me/messages/"
                        + message_id + "?" + headers)
            # Only keys permitted by fields projection; no body/snippet retained.
            raw = self._request_json(endpoint, self._auth)
            matches.append({"id": raw.get("id"),
                            "internalDate": raw.get("internalDate"),
                            "payload": {"headers": (raw.get("payload") or {}).get("headers", [])}})
        return matches


def _exchange_token(grant: dict[str, str]) -> str:
    values = urllib.parse.urlencode({
        "client_id": grant["client_id"],
        "client_secret": grant["client_secret"],
        "refresh_token": grant["refresh_token"],
        "grant_type": "refresh_token",
    }).encode("ascii")
    endpoint = "https://oauth2.googleapis.com/token"
    result = _request_json(endpoint, {"Content-Type": "application/x-www-form-urlencoded"}, values)
    token = str(result.get("access_token") or "")
    if not token:
        raise RuntimeError("gmail_oauth_unavailable")
    return token


def _write_private_event(event: dict, *, directory: Path = STATE_DIR) -> None:
    """Persist only allowlisted non-secret watch metadata, atomically."""
    directory.mkdir(parents=True, exist_ok=True, mode=0o700)
    os.chmod(directory, 0o700)
    session = str(event.get("session", ""))
    if session not in ACCOUNTS:
        raise ValueError("account_scope_invalid")
    allowed = ("status", "session", "started_at", "deadline_at", "received_at", "message_id")
    safe = {key: event[key] for key in allowed if key in event}
    fd, name = tempfile.mkstemp(prefix=".email-watch-", dir=directory)
    try:
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as fp:
            json.dump(safe, fp, sort_keys=True)
            fp.write("\n")
            fp.flush()
            os.fsync(fp.fileno())
        os.replace(name, directory / (session + ".json"))
    finally:
        if os.path.exists(name):
            os.unlink(name)


def launch_watch(session: str) -> dict[str, str | int]:
    """Opt-in only: no background process without the separate readonly grant."""
    if session not in ACCOUNTS:
        raise ValueError("account_scope_invalid")
    ready = credential_readiness(OAUTH_FILE)
    if ready["status"] != "ready":
        return ready
    env = {
        "PATH": "/usr/sbin:/usr/bin:/sbin:/bin",
        "SHOPVIVALIZ_GMAIL_READONLY_OAUTH_FILE": str(OAUTH_FILE),
    }
    try:
        subprocess.Popen([sys.executable, str(Path(__file__).resolve()), "--watch", session],
                         stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                         stderr=subprocess.DEVNULL, start_new_session=True,
                         close_fds=True, env=env)
    except OSError:
        return {"status": "watch_spawn_failed"}
    return {"status": "started", "duration_seconds": WATCH_SECONDS,
            "interval_seconds": POLL_SECONDS}


def _worker(session: str) -> int:
    if session not in ACCOUNTS:
        return 2
    STATE_DIR.mkdir(parents=True, exist_ok=True, mode=0o700)
    os.chmod(STATE_DIR, 0o700)
    lock_path = STATE_DIR / (session + ".lock")
    lock_fd = os.open(str(lock_path), os.O_CREAT | os.O_RDWR, 0o600)
    os.fchmod(lock_fd, 0o600)
    try:
        try:
            fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return 0  # A watcher for this account is already active.
        started = int(time.time())
        def report(event: dict) -> None:
            _write_private_event({**event, "started_at": started,
                                  "deadline_at": started + WATCH_SECONDS})
        grant, status = _read_private_grant(OAUTH_FILE)
        if status != "ready" or grant is None:
            report({"status": status, "session": session})
            return 3
        report({"status": "watching", "session": session})
        try:
            reader = GmailMetadataReader(_exchange_token(grant))
            reader.verify_mailbox()  # Exact mailbox proof, not mere token presence.
        except Exception:
            report({"status": "gmail_auth_not_verified", "session": session})
            return 4
        result = watch_window(session, start_epoch=started, fetch=reader.fetch,
                              report=report)
        return 0 if result["status"] in ("matched", "timeout") else 5
    finally:
        os.close(lock_fd)


if __name__ == "__main__":
    if len(sys.argv) != 3 or sys.argv[1] != "--watch":
        raise SystemExit(2)
    raise SystemExit(_worker(sys.argv[2]))
