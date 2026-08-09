#!/usr/bin/env python3
"""Import an existing local Google OAuth client JSON into GitHub Production secrets.

Runs only on Fred-Win. It never prints credential values.
"""
import json
import subprocess
from pathlib import Path
from urllib.parse import urlsplit

repo = "Vivaliz-site/site-shopvivaliz"
roots = [Path.home() / "Downloads", Path.home() / "Desktop"]
candidates = []
for root in roots:
    if root.is_dir():
        candidates.extend(sorted(root.glob("client_secret*.json"), key=lambda p: p.stat().st_mtime, reverse=True))

chosen = None
section = None
kind = "unknown"
for path in candidates:
    try:
        obj = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        continue
    if isinstance(obj.get("installed"), dict):
        s = obj["installed"]
        candidate_kind = "installed"
    elif isinstance(obj.get("web"), dict):
        s = obj["web"]
        candidate_kind = "web"
    else:
        continue
    cid = str(s.get("client_id", "")).strip()
    secret = str(s.get("client_secret", "")).strip()
    if cid.endswith(".apps.googleusercontent.com") and secret:
        chosen, section, kind = path, s, candidate_kind
        break

if chosen is None or section is None:
    print("NO_VALID_LOCAL_OAUTH_CLIENT_JSON")
    raise SystemExit(2)

subprocess.run(["gh", "auth", "status", "--hostname", "github.com"], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
for name, value in (("GOOGLE_OAUTH_CLIENT_ID", section["client_id"]), ("GOOGLE_OAUTH_CLIENT_SECRET", section["client_secret"])):
    subprocess.run(
        ["gh", "secret", "set", name, "--env", "Production", "--repo", repo],
        input=str(value) + "\n",
        text=True,
        check=True,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )

redirects = [str(v).strip() for v in (section.get("redirect_uris") or []) if str(v).strip()]
loopback = False
https_site = False
hosts = set()
for uri in redirects:
    try:
        parsed = urlsplit(uri)
    except Exception:
        continue
    host = (parsed.hostname or "").lower()
    if host:
        hosts.add(host)
    if host in {"localhost", "127.0.0.1", "::1"}:
        loopback = True
    if parsed.scheme == "https" and host not in {"localhost", "127.0.0.1", "::1"}:
        https_site = True

print("PRODUCTION_OAUTH_CLIENT_IMPORTED")
print("oauth_client_type=" + kind)
print("redirect_uri_count=" + str(len(redirects)))
print("has_loopback_redirect=" + str(loopback))
print("has_https_site_redirect=" + str(https_site))
print("redirect_host_count=" + str(len(hosts)))
print("NO_SECRET_VALUES_PRINTED")
