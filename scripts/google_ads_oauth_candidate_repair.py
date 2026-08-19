#!/usr/bin/env python3
"""Safely test and repair a malformed Google OAuth client id on Fred-Win.

No credential value is printed. The script tests the currently configured client id
and a narrowly normalized candidate (.app.s.googleusercontent.com ->
.apps.googleusercontent.com) against Google's token endpoint.

When the normalized candidate authenticates (including invalid_grant, which proves
client+secret acceptance but a bad/revoked refresh token), --write-local updates only
GOOGLE_OAUTH_CLIENT_ID in the local .env. --sync-github additionally uses an already
authenticated GitHub CLI session to update the Production environment secret without
printing its value.
"""

from __future__ import annotations

import argparse
import json
import os
import shutil
import stat
import subprocess
import tempfile
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENV_PATH = ROOT / ".env"
TOKEN_URL = "https://oauth2.googleapis.com/token"


def load_env(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    if not path.is_file():
        return values
    for raw in path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip("\"'")
    return values


def validate_env_target(path: Path) -> None:
    if not path.is_file():
        raise FileNotFoundError(path)
    if path.is_symlink():
        raise ValueError("env_symlink_rejected")
    metadata = path.stat()
    if not stat.S_ISREG(metadata.st_mode):
        raise ValueError("env_not_regular_file")
    if stat.S_IMODE(metadata.st_mode) & 0o077:
        raise ValueError("env_permissions_too_open")


def normalize_client_id(value: str) -> str:
    value = value.strip()
    value = value.replace(".app.s.googleusercontent.com", ".apps.googleusercontent.com")
    return value


def classify_token_exchange(client_id: str, client_secret: str, refresh_token: str) -> str:
    body = urllib.parse.urlencode(
        {
            "client_id": client_id,
            "client_secret": client_secret,
            "refresh_token": refresh_token,
            "grant_type": "refresh_token",
        }
    ).encode("utf-8")
    req = urllib.request.Request(
        TOKEN_URL,
        data=body,
        headers={"Content-Type": "application/x-www-form-urlencoded"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=20) as response:
            payload = json.loads(response.read().decode("utf-8"))
        return "SUCCESS" if payload.get("access_token") else "UNEXPECTED_RESPONSE"
    except urllib.error.HTTPError as exc:
        try:
            payload = json.loads(exc.read().decode("utf-8", errors="ignore"))
        except Exception:
            payload = {}
        error = str(payload.get("error", "")).strip().lower()
        if error == "invalid_client":
            return "INVALID_CLIENT"
        if error == "invalid_grant":
            return "INVALID_GRANT"
        if error == "unauthorized_client":
            return "UNAUTHORIZED_CLIENT"
        return "HTTP_ERROR_" + str(exc.code)
    except Exception as exc:
        return "NETWORK_OR_RUNTIME_" + type(exc).__name__


def update_env_key(path: Path, key: str, value: str) -> None:
    validate_env_target(path)
    original = path.stat()
    lines = path.read_text(encoding="utf-8", errors="ignore").splitlines()
    out: list[str] = []
    replaced = False
    for line in lines:
        if line.strip().startswith(key + "="):
            out.append(f"{key}={value}")
            replaced = True
        else:
            out.append(line)
    if not replaced:
        out.append(f"{key}={value}")
    fd, temp_name = tempfile.mkstemp(prefix=".env.oauth.", dir=str(path.parent))
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as handle:
            handle.write("\n".join(out) + "\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temp_name, original.st_mode & 0o777)
        if os.name != "nt":
            os.chown(temp_name, original.st_uid, original.st_gid)
        os.replace(temp_name, path)
    finally:
        if os.path.exists(temp_name):
            os.unlink(temp_name)


def sync_github_secret(candidate: str) -> str:
    gh = shutil.which("gh")
    if not gh:
        return "GH_CLI_NOT_FOUND"
    auth = subprocess.run(
        [gh, "auth", "status", "--hostname", "github.com"],
        stdin=subprocess.DEVNULL,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        timeout=15,
    )
    if auth.returncode != 0:
        return "GH_AUTH_NOT_READY"
    result = subprocess.run(
        [
            gh,
            "secret",
            "set",
            "GOOGLE_OAUTH_CLIENT_ID",
            "--env",
            "Production",
            "--repo",
            "Vivaliz-site/site-shopvivaliz",
        ],
        input=candidate + "\n",
        text=True,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        timeout=30,
    )
    return "GH_SECRET_UPDATED" if result.returncode == 0 else "GH_SECRET_UPDATE_FAILED"


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--write-local", action="store_true")
    parser.add_argument("--sync-github", action="store_true")
    args = parser.parse_args()

    env = load_env(ENV_PATH)
    client_id = env.get("GOOGLE_OAUTH_CLIENT_ID", "")
    client_secret = env.get("GOOGLE_OAUTH_CLIENT_SECRET", "")
    refresh_token = env.get("GOOGLE_ADS_REFRESH_TOKEN", "")
    if not all((client_id, client_secret, refresh_token)):
        print("OAUTH_REPAIR_NOT_READY")
        print("reason=LOCAL_ENV_MISSING_REQUIRED_VALUES")
        return 2

    candidate = normalize_client_id(client_id)
    print("OAUTH_REPAIR_DIAGNOSTIC")
    print("current_format_valid=" + str(client_id.endswith(".apps.googleusercontent.com")))
    print("candidate_differs=" + str(candidate != client_id))

    current_status = classify_token_exchange(client_id, client_secret, refresh_token)
    print("current_exchange=" + current_status)

    if candidate == client_id:
        candidate_status = current_status
    else:
        candidate_status = classify_token_exchange(candidate, client_secret, refresh_token)
    print("candidate_exchange=" + candidate_status)

    client_secret_accepted = candidate_status in {"SUCCESS", "INVALID_GRANT"}
    print("candidate_client_secret_accepted=" + str(client_secret_accepted))
    if not client_secret_accepted:
        print("NO_CHANGES")
        return 1

    if args.write_local and candidate != client_id:
        try:
            update_env_key(ENV_PATH, "GOOGLE_OAUTH_CLIENT_ID", candidate)
        except (OSError, ValueError) as exc:
            print("LOCAL_CLIENT_ID_UPDATE_FAILED")
            print("reason=" + type(exc).__name__)
            return 2
        print("LOCAL_CLIENT_ID_UPDATED")

    if args.sync_github:
        print("github_sync=" + sync_github_secret(candidate))

    if candidate_status == "SUCCESS":
        print("REFRESH_TOKEN_VALID")
        return 0

    print("REFRESH_TOKEN_REISSUE_REQUIRED")
    return 3


if __name__ == "__main__":
    raise SystemExit(main())
