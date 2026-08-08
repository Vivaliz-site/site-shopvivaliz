#!/usr/bin/env python3
"""Import an existing local Google OAuth client JSON into GitHub Production secrets.

Runs only on Fred-Win. It never prints credential values.
"""
import json
import subprocess
from pathlib import Path

repo = "Vivaliz-site/site-shopvivaliz"
roots = [Path.home() / "Downloads", Path.home() / "Desktop"]
candidates = []
for root in roots:
    if root.is_dir():
        candidates.extend(sorted(root.glob("client_secret*.json"), key=lambda p: p.stat().st_mtime, reverse=True))

chosen = None
section = None
for path in candidates:
    try:
        obj = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        continue
    s = obj.get("installed") or obj.get("web")
    if not isinstance(s, dict):
        continue
    cid = str(s.get("client_id", "")).strip()
    secret = str(s.get("client_secret", "")).strip()
    if cid.endswith(".apps.googleusercontent.com") and secret:
        chosen, section = path, s
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

kind = "installed" if "installed" in json.loads(chosen.read_text(encoding="utf-8")) else "web"
print("PRODUCTION_OAUTH_CLIENT_IMPORTED")
print("oauth_client_type=" + kind)
print("redirect_uri_count=" + str(len(section.get("redirect_uris") or [])))
print("NO_SECRET_VALUES_PRINTED")
