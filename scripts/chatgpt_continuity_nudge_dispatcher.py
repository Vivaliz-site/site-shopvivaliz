#!/usr/bin/env python3
"""Bridge stale chatgpt_common resume requests to the real ChatGPT browser.

`task_continuation_watchdog.py` only ever produces requests with
`preferred_executor == "chatgpt_common"` -- that label has never been backed
by real execution: nothing previously acted on it beyond an inert queue ACK
(see docs/knowledge/task-continuity.md, DETACHED_CONTINUATION_EXECUTOR_V6:
"ACK de fila... nao e execucao"). This script closes that gap for the
chatgpt_common tier specifically: it POSTs an `enqueue` to
api/chatgpt-continuity/bridge.php, which the canonical backend browser worker
(scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs) later
`pull`s to type a "continue" message into the user's own, already-logged-in
ChatGPT conversation via CDP.

Like task_resume_dispatcher.py, this script is allowed to make network
calls (unlike task_continuation_watchdog.py itself, which must stay a pure
local state transformation). It never invokes an AI provider and never
prints the bridge token.
"""
from __future__ import annotations

import argparse
import json
import os
import urllib.error
import urllib.request
import urllib.parse
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

try:
    from .agent_task_state import RUNTIME_DIR
    from .task_continuation_watchdog import read_requests
except ImportError:  # direct CLI execution from repository root
    from agent_task_state import RUNTIME_DIR
    from task_continuation_watchdog import read_requests

LEDGER_FILE = "_chatgpt-continuity-nudges.jsonl"
DEFAULT_BRIDGE_URL = "http://127.0.0.1:8080/api/chatgpt-continuity/bridge.php"
DEFAULT_BRIDGE_HOST_HEADER = "shopvivaliz.com.br"
DEFAULT_TOKEN_FILE = Path("/home/ubuntu/shopvivaliz-deploy/shared/storage/private/chatgpt-continuity/bridge.token")


def resolve_bridge_token(explicit_token: str = "") -> str:
    direct = explicit_token.strip() or os.getenv("CHATGPT_CONTINUITY_BRIDGE_TOKEN", "").strip()
    if direct:
        return direct

    configured = os.getenv("CHATGPT_CONTINUITY_BRIDGE_TOKEN_FILE", "").strip()
    candidates = [Path(configured).expanduser()] if configured else []
    candidates.append(DEFAULT_TOKEN_FILE)
    for token_path in candidates:
        try:
            if not token_path.is_file():
                continue
            value = token_path.read_text(encoding="utf-8").strip()
        except (OSError, UnicodeError):
            continue
        if value:
            return value
    return ""


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def _read_ledger(runtime_dir: Path) -> set[str]:
    path = runtime_dir / LEDGER_FILE
    if not path.is_file():
        return set()
    seen: set[str] = set()
    for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
        if not line.strip():
            continue
        try:
            row = json.loads(line)
        except json.JSONDecodeError:
            continue
        fingerprint = str(row.get("fingerprint", "")).strip()
        if fingerprint:
            seen.add(fingerprint)
    return seen


def _append_ledger(runtime_dir: Path, row: dict[str, Any]) -> None:
    runtime_dir.mkdir(parents=True, exist_ok=True)
    path = runtime_dir / LEDGER_FILE
    with path.open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(row, ensure_ascii=False, sort_keys=True) + "\n")


def _bridge_host_header(bridge_url: str) -> str:
    configured = os.getenv("CHATGPT_CONTINUITY_BRIDGE_HOST_HEADER", "").strip()
    if configured:
        return configured
    host = (urllib.parse.urlparse(bridge_url).hostname or "").lower()
    return DEFAULT_BRIDGE_HOST_HEADER if host in {"127.0.0.1", "localhost", "10.0.1.112"} else ""


def enqueue_nudge_via_bridge(
    *,
    bridge_url: str,
    token: str,
    task_id: str,
    repository: str,
    timeout_seconds: int = 15,
    bridge_host_header: str = "",
) -> dict[str, Any]:
    """Isolated so tests can monkeypatch it without a real network call."""
    body = json.dumps({"operation": "enqueue", "task_id": task_id, "repository": repository}).encode("utf-8")
    headers = {
        "Authorization": f"Bearer {token}",
        "Content-Type": "application/json",
        "Accept": "application/json",
        "User-Agent": "shopvivaliz-chatgpt-continuity-dispatcher",
    }
    host_header = bridge_host_header.strip() or _bridge_host_header(bridge_url)
    if host_header:
        headers["Host"] = host_header
    request = urllib.request.Request(
        bridge_url,
        data=body,
        method="POST",
        headers=headers,
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout_seconds) as response:
            payload = json.loads(response.read().decode("utf-8"))
            return {"ok": True, "http_status": response.status, "body": payload}
    except urllib.error.HTTPError as exc:
        return {"ok": False, "http_status": exc.code, "error": "http_error"}
    except (urllib.error.URLError, TimeoutError, OSError):
        return {"ok": False, "http_status": 0, "error": "transport_error"}


def run_once(
    *,
    runtime_dir: Path | None = None,
    bridge_url: str = "",
    token: str = "",
    enqueue: Any = enqueue_nudge_via_bridge,
) -> dict[str, Any]:
    root = Path(runtime_dir or RUNTIME_DIR)
    resolved_bridge_url = bridge_url or os.getenv("CHATGPT_CONTINUITY_BRIDGE_URL", "") or DEFAULT_BRIDGE_URL
    resolved_token = resolve_bridge_token(token)

    scanned = 0
    eligible = 0
    dispatched = 0
    skipped_no_token = 0

    already = _read_ledger(root) or set()

    for request in read_requests(root):
        scanned += 1
        if str(request.get("preferred_executor", "")).strip() != "chatgpt_common":
            continue
        if str(request.get("status", "")).strip() != "queued":
            continue
        fingerprint = str(request.get("fingerprint", "")).strip()
        task_id = str(request.get("task_id", "")).strip()
        repository = str(request.get("repository", "")).strip()
        if not fingerprint or not task_id or not repository:
            continue
        if fingerprint in already:
            continue

        eligible += 1

        if not resolved_token:
            skipped_no_token += 1
            continue

        result = enqueue(
            bridge_url=resolved_bridge_url,
            token=resolved_token,
            task_id=task_id,
            repository=repository,
            bridge_host_header=_bridge_host_header(resolved_bridge_url),
        )
        _append_ledger(
            root,
            {
                "fingerprint": fingerprint,
                "task_id": task_id,
                "repository": repository,
                "dispatched_at": utc_now(),
                "bridge_ok": bool(result.get("ok")),
                "http_status": result.get("http_status"),
            },
        )
        already.add(fingerprint)
        if result.get("ok"):
            dispatched += 1

    return {
        "ok": True,
        "runtime_dir": str(root),
        "scanned": scanned,
        "eligible": eligible,
        "dispatched": dispatched,
        "skipped_no_token": skipped_no_token,
        "generated_at": utc_now(),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Bridge stale chatgpt_common resume requests to the real browser.")
    parser.add_argument("--runtime-dir", default="")
    parser.add_argument("--bridge-url", default="")
    args = parser.parse_args()

    runtime_dir = Path(args.runtime_dir).expanduser() if args.runtime_dir else None
    result = run_once(runtime_dir=runtime_dir, bridge_url=args.bridge_url)
    print(json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
