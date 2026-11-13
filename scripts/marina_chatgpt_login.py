#!/usr/bin/env python3
"""Safe official login navigation for the dedicated Marina Firefox Dev profile.

No password, OTP, authentication token, or cookie is read or transferred.
"""
from __future__ import annotations
import argparse
import json
import os
import pwd
import subprocess
import sys
from pathlib import Path

ACTIONS = ("status", "open_login", "fill_email", "continue")
ACCOUNT = "dev"
EMAIL = "dev@shopvivaliz.com.br"
DISPLAY = ":101"
AUTHORITY = Path("/home/ubuntu/snap/firefox/common/shopvivaliz-chatgpt-gui/Xauthority")
PROFILE = Path("/home/ubuntu/snap/firefox/common/shopvivaliz-chatgpt-profile")
LOGIN_URL = "https://chatgpt.com/auth/login"

class LoginUnavailable(Exception):
    pass

def run_xdotool(*args: str) -> str:
    env = {
        "PATH": "/usr/bin:/bin",
        "HOME": "/home/ubuntu",
        "DISPLAY": DISPLAY,
        "XAUTHORITY": str(AUTHORITY),
    }
    try:
        p = subprocess.run(
            ["/usr/bin/xdotool", *args], env=env,
            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
            check=False, timeout=8,
        )
    except (OSError, subprocess.TimeoutExpired):
        raise LoginUnavailable("official_browser_unavailable") from None
    if p.returncode:
        raise LoginUnavailable("official_browser_action_failed")
    return p.stdout.decode("utf-8", "replace").strip()

def readiness() -> None:
    if os.geteuid() != pwd.getpwnam("ubuntu").pw_uid:
        raise LoginUnavailable("correct_user_required")
    if not AUTHORITY.is_file() or not PROFILE.is_dir():
        raise LoginUnavailable("dedicated_browser_profile_unavailable")
    st = AUTHORITY.stat()
    if st.st_uid != os.geteuid() or st.st_mode & 0o077:
        raise LoginUnavailable("display_authority_invalid")
    if run_xdotool("getdisplaygeometry") != "1600 900":
        raise LoginUnavailable("unexpected_display")

def browser_window() -> tuple[str, str]:
    windows = run_xdotool("search", "--onlyvisible", "--name", "Mozilla Firefox").splitlines()
    if len(windows) != 1 or not windows[0].isdigit():
        raise LoginUnavailable("browser_window_not_unique")
    wid = windows[0]
    return wid, run_xdotool("getwindowname", wid)

def stage_for_title(title: str) -> str:
    title = title.lower()
    if "just a moment" in title or "cloudflare" in title:
        return "challenge"
    if "enter your password" in title and "openai" in title:
        return "password"
    if ("verification" in title or "verify" in title) and "openai" in title:
        return "mfa"
    if "log in or sign up" in title and "openai" in title:
        return "login_or_home"
    if "chatgpt" in title and "firefox" in title:
        return "chatgpt_home"
    return "unknown"

def execute(session: str, action: str) -> dict[str, object]:
    if session != ACCOUNT or action not in ACTIONS:
        raise LoginUnavailable("invalid_account_or_action")
    readiness()
    wid, title = browser_window()
    stage = stage_for_title(title)
    if action == "status":
        return {"ok": True, "session": session, "action": action,
                "stage": stage, "identity_verified": False}
    if action == "open_login":
        run_xdotool("windowfocus", wid)
        run_xdotool("key", "--clearmodifiers", "ctrl+l")
        run_xdotool("type", "--clearmodifiers", LOGIN_URL)
        run_xdotool("key", "--clearmodifiers", "Return")
    elif action == "fill_email":
        if stage != "login_or_home":
            raise LoginUnavailable("login_email_stage_required")
        run_xdotool("windowfocus", wid)
        run_xdotool("mousemove", "--sync", "640", "546")
        run_xdotool("click", "1")
        run_xdotool("type", "--clearmodifiers", EMAIL)
    elif action == "continue":
        if stage != "login_or_home":
            raise LoginUnavailable("official_login_stage_required")
        run_xdotool("mousemove", "--sync", "640", "620")
        run_xdotool("click", "1")
    return {"ok": True, "session": session, "action": action,
            "stage_before": stage, "identity_verified": False}

def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--session", choices=(ACCOUNT,), required=True)
    parser.add_argument("--action", choices=ACTIONS, required=True)
    args = parser.parse_args()
    try:
        result = execute(args.session, args.action)
    except LoginUnavailable as e:
        result = {"ok": False, "session": args.session, "action": args.action,
                  "reason": str(e)}
    except Exception:
        result = {"ok": False, "session": args.session, "action": args.action,
                  "reason": "official_login_unavailable"}
    print(json.dumps(result, sort_keys=True, separators=(",", ":")))
    return 0 if result["ok"] else 1

if __name__ == "__main__":
    sys.exit(main())
