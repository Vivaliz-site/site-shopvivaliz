#!/usr/bin/env python3
"""Close abandoned tabs in the ShopVivaliz agent browsers.

Policy (owner): an agent browser keeps only the tab it needs to stay open and logged in.
Login persists in the browser profile, not in tabs, and a browser needs one tab to stay
alive. This reaper therefore closes every tab that is not in use, and it is a dry run
unless --apply is given. A tab is closed only when ALL of the following hold:

* the tab is a normal page (not chrome://, devtools, an extension, or a login page);
* its conversation is not bound to a non-terminal task checkpoint (work in progress), and,
  for a tab outside ChatGPT, no non-terminal task updated within --task-fresh-hours
  (default 6) is bound to that browser session (a task may be typing in a form or SPA,
  which leaves URL and title unchanged; an abandoned checkpoint left RUNNING must not
  disable the reaper for that browser forever);
* its (url, title) fingerprint has not changed for --idle-hours (default 1), measured
  from the reaper's own first observation. CDP exposes no last-activity time, so a tab
  seen for the first time always starts a fresh clock; this only protects a tab an agent
  just opened and has not bound to a task yet;
* the browser keeps at least --keep-min tabs afterwards (default 1, the most recently
  changed one), so the browser stays open.

Fail closed: if the task-state directory cannot be read, no ChatGPT tab is closed and
the exit status is 3. Only loopback CDP HTTP endpoints are used. The report contains
host names and counts only, never URLs, titles, cookies or conversation identifiers.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import sys
import tempfile
import time
from pathlib import Path
from urllib.error import URLError
from urllib.parse import urlparse
from urllib.request import urlopen

DEFAULT_BROWSERS = {"dev": 9559, "atendimento": 9556, "dev-aux": 9557}
# Task checkpoints bind to a browser session name (agent_task_state.py: dev|atendimento).
BROWSER_SESSION = {"dev": "dev", "dev-aux": "dev", "atendimento": "atendimento"}
CANONICAL_STATE_DIR = Path("/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state")
NON_TERMINAL_STATES = frozenset({"RUNNING", "READY_TO_COMPLETE"})
AUTH_HOSTS = frozenset({
    "auth.openai.com",
    "accounts.google.com",
    "login.microsoftonline.com",
    "appleid.apple.com",
})
PROTECTED_SCHEMES = frozenset({"chrome", "chrome-extension", "chrome-untrusted", "devtools", "view-source"})
CHATGPT_HOSTS = frozenset({"chatgpt.com", "www.chatgpt.com", "chat.openai.com"})
CONVERSATION_RE = re.compile(r"/c/([A-Za-z0-9_-]{8,160})")
EXIT_OK, EXIT_USAGE, EXIT_STATE_UNREADABLE = 0, 2, 3


def checkpoint_is_fresh(path: Path, fresh_seconds: float, ref_time: float) -> bool:
    """True when the checkpoint file was updated within fresh_seconds (unknown age: fresh)."""
    try:
        return ref_time - path.stat().st_mtime <= fresh_seconds
    except OSError:
        return True


def load_active_state(state_dir: Path, fresh_seconds: float | None = None,
                      ref_time: float | None = None) -> tuple[set[str], set[str]] | None:
    """(conversation ids, browser sessions) of non-terminal tasks, or None when unreadable.

    A conversation binding protects exactly one tab, so it holds for as long as the task is
    non-terminal. A browser session binding protects every non-ChatGPT tab in that browser,
    so with fresh_seconds set it only counts when the checkpoint was updated recently: an
    abandoned checkpoint left RUNNING must not disable the reaper for a browser forever.
    """
    if not state_dir.is_dir():
        return None
    try:
        files = sorted(state_dir.glob("*.json"))
    except OSError:
        return None
    conversations: set[str] = set()
    sessions: set[str] = set()
    for path in files:
        if path.name.startswith("_"):
            continue
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            return None
        if not isinstance(payload, dict) or str(payload.get("status", "")) not in NON_TERMINAL_STATES:
            continue
        conversation = str(payload.get("conversation_id", "")).strip()
        session = str(payload.get("browser_session", "")).strip()
        if conversation:
            conversations.add(conversation)
        if session and (fresh_seconds is None
                        or checkpoint_is_fresh(path, fresh_seconds, time.time() if ref_time is None else ref_time)):
            sessions.add(session)
    return conversations, sessions


def load_active_conversations(state_dir: Path) -> set[str] | None:
    """Conversation ids bound to non-terminal tasks, or None when state is unreadable."""
    state = load_active_state(state_dir)
    return None if state is None else state[0]


def browser_busy(name: str, sessions: set[str]) -> bool:
    """True when a non-terminal task is bound to this browser (unknown names: any task)."""
    mapped = BROWSER_SESSION.get(name)
    return mapped in sessions if mapped else bool(sessions)


def parse_tab(tab: dict) -> tuple[str, str, str | None]:
    url = str(tab.get("url") or "")
    parsed = urlparse(url)
    host = (parsed.hostname or "").lower()
    match = CONVERSATION_RE.search(parsed.path or "")
    return parsed.scheme.lower(), host, match.group(1) if match else None


def fingerprint(tab: dict) -> str:
    raw = f"{tab.get('url', '')}|{tab.get('title', '')}".encode("utf-8", "replace")
    return hashlib.sha256(raw).hexdigest()


def protection_reason(tab: dict, active: set[str] | None, busy: bool = False) -> str | None:
    scheme, host, conversation = parse_tab(tab)
    if scheme in PROTECTED_SCHEMES:
        return "internal_page"
    if host in AUTH_HOSTS:
        return "login_page"
    if active is None:
        return "task_state_unreadable"  # cannot tell what is in use: close nothing
    if conversation and conversation in active:
        return "bound_to_active_task"
    # A task bound to this browser may be typing in a form or SPA on any site, which leaves
    # URL and title unchanged, so a non-ChatGPT tab cannot be shown idle. Leave it alone.
    if busy and host not in CHATGPT_HOSTS:
        return "browser_in_use_by_active_task"
    return None


def fetch_json(port: int, path: str, timeout: float):
    with urlopen(f"http://127.0.0.1:{int(port)}{path}", timeout=timeout) as response:  # noqa: S310 (loopback only)
        return json.loads(response.read().decode("utf-8", "replace") or "null")


def close_target(port: int, target_id: str, timeout: float) -> bool:
    if not re.fullmatch(r"[A-Za-z0-9_-]{8,128}", target_id):
        return False
    try:
        with urlopen(f"http://127.0.0.1:{int(port)}/json/close/{target_id}", timeout=timeout):  # noqa: S310
            return True
    except (URLError, OSError, ValueError):
        return False


def load_state(path: Path) -> dict:
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return {"targets": {}}
    return data if isinstance(data, dict) and isinstance(data.get("targets"), dict) else {"targets": {}}


def save_state(path: Path, state: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    fd, tmp = tempfile.mkstemp(prefix=".reaper-", dir=str(path.parent))
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(state, handle, separators=(",", ":"))
        os.chmod(tmp, 0o600)
        os.replace(tmp, path)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)


def reap_browser(name: str, port: int, *, state: dict, active: set[str] | None, busy: bool,
                 now: float, idle_seconds: float, keep_min: int, max_closes: int, apply: bool,
                 timeout: float) -> dict:
    report: dict = {"browser": name, "port": port, "pages": 0, "protected": {}, "candidates": {},
                    "closed": 0, "would_close": 0, "failed": 0, "status": "OK"}
    try:
        tabs = fetch_json(port, "/json/list", timeout)
    except (URLError, OSError, ValueError):
        report["status"] = "UNREACHABLE"
        return report
    pages = [t for t in tabs if isinstance(t, dict) and t.get("type") == "page" and t.get("id")]
    report["pages"] = len(pages)
    records = state["targets"].setdefault(name, {})
    seen: set[str] = set()
    eligible: list[tuple[float, dict]] = []
    for tab in pages:
        target_id = str(tab["id"])
        seen.add(target_id)
        fp = fingerprint(tab)
        record = records.get(target_id)
        if not record or record.get("fp") != fp:
            record = {"fp": fp, "first_seen": now, "last_change": now}
            records[target_id] = record
        reason = protection_reason(tab, active, busy)
        if reason:
            report["protected"][reason] = report["protected"].get(reason, 0) + 1
            continue
        if now - float(record["last_change"]) >= idle_seconds:
            eligible.append((float(record["last_change"]), tab))
    for stale in set(records) - seen:
        del records[stale]
    eligible.sort(key=lambda item: item[0])
    allowed = max(0, min(max_closes, len(pages) - keep_min))
    for _, tab in eligible[:allowed]:
        _, host, _ = parse_tab(tab)
        host = host or "(blank)"
        report["candidates"][host] = report["candidates"].get(host, 0) + 1
        if not apply:
            report["would_close"] += 1
            continue
        if close_target(port, str(tab["id"]), timeout):
            report["closed"] += 1
            records.pop(str(tab["id"]), None)
        else:
            report["failed"] += 1
    return report


def parse_browsers(values: list[str]) -> dict[str, int]:
    if not values:
        return dict(DEFAULT_BROWSERS)
    result: dict[str, int] = {}
    for value in values:
        name, _, port = value.partition("=")
        if not re.fullmatch(r"[a-z0-9-]{1,32}", name) or not port.isdigit() or not 1024 <= int(port) <= 65535:
            raise ValueError(f"invalid --browser {value!r}; expected name=port")
        result[name] = int(port)
    return result


def main(argv: list[str] | None = None, *, now: float | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--browser", action="append", default=[], metavar="NAME=PORT")
    parser.add_argument("--state-dir", default=os.getenv("SHOPVIVALIZ_AGENT_TASK_STATE_DIR", str(CANONICAL_STATE_DIR)))
    parser.add_argument("--state-file", default=os.getenv(
        "SHOPVIVALIZ_TAB_REAPER_STATE", str(Path.home() / ".local/state/shopvivaliz/browser-tab-reaper.json")))
    parser.add_argument("--idle-hours", type=float, default=1.0)
    parser.add_argument("--keep-min", type=int, default=1)
    parser.add_argument("--max-closes", type=int, default=10, help="per browser per run")
    parser.add_argument("--task-fresh-hours", type=float, default=6.0,
                        help="a task checkpoint older than this no longer marks its browser busy")
    parser.add_argument("--timeout", type=float, default=4.0)
    parser.add_argument("--apply", action="store_true", help="actually close tabs (default: dry run)")
    args = parser.parse_args(argv)
    # The service unit stays a dry run until an operator sets this to exactly "1".
    args.apply = args.apply or os.getenv("SHOPVIVALIZ_TAB_REAPER_APPLY", "") == "1"
    try:
        browsers = parse_browsers(args.browser)
    except ValueError as error:
        print(f"BROWSER_TAB_REAPER=USAGE error={error}", file=sys.stderr)
        return EXIT_USAGE
    if args.idle_hours < 0.25 or args.keep_min < 1 or args.max_closes < 0 or args.task_fresh_hours < 0.25:
        print("BROWSER_TAB_REAPER=USAGE error=idle-hours>=0.25, keep-min>=1, max-closes>=0, "
              "task-fresh-hours>=0.25", file=sys.stderr)
        return EXIT_USAGE
    clock = time.time() if now is None else now
    active_state = load_active_state(Path(args.state_dir), args.task_fresh_hours * 3600, time.time())
    active = None if active_state is None else active_state[0]
    sessions = set() if active_state is None else active_state[1]
    state_path = Path(args.state_file)
    state = load_state(state_path)
    reports = [
        reap_browser(name, port, state=state, active=active, busy=browser_busy(name, sessions),
                     now=clock, idle_seconds=args.idle_hours * 3600, keep_min=args.keep_min,
                     max_closes=args.max_closes, apply=args.apply, timeout=args.timeout)
        for name, port in browsers.items()
    ]
    save_state(state_path, state)
    summary = {"mode": "apply" if args.apply else "dry-run",
               "task_state": "OK" if active is not None else "UNREADABLE", "browsers": reports}
    print(json.dumps(summary, sort_keys=True))
    print("BROWSER_TAB_REAPER=" + ("DEGRADED_TASK_STATE_UNREADABLE" if active is None else "PASS"))
    return EXIT_STATE_UNREADABLE if active is None else EXIT_OK


if __name__ == "__main__":
    sys.exit(main())
