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
    from .task_continuation_watchdog import read_requests, _fingerprint as checkpoint_fingerprint
except ImportError:  # direct CLI execution from repository root
    from agent_task_state import RUNTIME_DIR
    from task_continuation_watchdog import read_requests, _fingerprint as checkpoint_fingerprint

LEDGER_FILE = "_chatgpt-continuity-nudges.jsonl"
DEFAULT_BRIDGE_URL = "http://127.0.0.1:8080/api/chatgpt-continuity/bridge.php"
DEFAULT_BRIDGE_HOST_HEADER = "shopvivaliz.com.br"
DEFAULT_TOKEN_FILE = Path("/home/ubuntu/shopvivaliz-deploy/shared/storage/private/chatgpt-continuity/bridge.token")
DEFAULT_BRIDGE_RETRY_SECONDS = 300

# Fail closed while OpenAI Support is still investigating the account/workspace
# restriction/risk-state hypothesis. Re-enable only by a dedicated reviewed
# code change; there is intentionally no environment override.
CHATGPT_WEB_TURN_AUTOMATION_BLOCKED = True
CHATGPT_WEB_TURN_AUTOMATION_POLICY = "CHATGPT_WEB_AUTOMATION_RISK_GUARD_V2"


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


def _parse_time(value: Any) -> datetime | None:
    raw = str(value or "").strip()
    if not raw:
        return None
    try:
        parsed = datetime.fromisoformat(raw.replace("Z", "+00:00"))
    except ValueError:
        return None
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed.astimezone(timezone.utc)


def _read_ledger(runtime_dir: Path) -> dict[str, dict[str, Any]]:
    path = runtime_dir / LEDGER_FILE
    if not path.is_file():
        return {}
    latest: dict[str, dict[str, Any]] = {}
    for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
        if not line.strip():
            continue
        try:
            row = json.loads(line)
        except json.JSONDecodeError:
            continue
        if not isinstance(row, dict):
            continue
        fingerprint = str(row.get("fingerprint", "")).strip()
        if fingerprint:
            latest[fingerprint] = row
    return latest


def _request_matches_current_checkpoint(runtime_dir: Path, request: dict[str, Any]) -> bool:
    task_id = str(request.get("task_id", "")).strip()
    if not task_id or "/" in task_id or "\\" in task_id:
        return False
    path = runtime_dir / f"{task_id}.json"
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return False
    if not isinstance(payload, dict) or str(payload.get("status", "")).strip() != "RUNNING":
        return False
    if not str(payload.get("next_action", "")).strip():
        return False
    request_repo = str(request.get("repository", "")).strip()
    current_repo = str(payload.get("repository", "")).strip()
    if request_repo != current_repo:
        return False
    return str(request.get("fingerprint", "")).strip() == checkpoint_fingerprint(payload)

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
    if CHATGPT_WEB_TURN_AUTOMATION_BLOCKED:
        return {
            "ok": True,
            "blocked_policy": True,
            "policy": CHATGPT_WEB_TURN_AUTOMATION_POLICY,
            "runtime_dir": str(root),
            "scanned": 0,
            "eligible": 0,
            "dispatched": 0,
            "skipped_no_token": 0,
            "skipped_stale_checkpoint": 0,
            "retry_attempted": 0,
            "generated_at": utc_now(),
        }

    resolved_bridge_url = bridge_url or os.getenv("CHATGPT_CONTINUITY_BRIDGE_URL", "") or DEFAULT_BRIDGE_URL
    resolved_token = resolve_bridge_token(token)

    scanned = 0
    eligible = 0
    dispatched = 0
    skipped_no_token = 0
    skipped_stale_checkpoint = 0
    retry_attempted = 0

    ledger = _read_ledger(root)
    retry_seconds = max(1, int(os.getenv("CHATGPT_CONTINUITY_BRIDGE_RETRY_SECONDS", DEFAULT_BRIDGE_RETRY_SECONDS)))
    current = datetime.now(timezone.utc)

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
        if not _request_matches_current_checkpoint(root, request):
            skipped_stale_checkpoint += 1
            continue

        previous = ledger.get(fingerprint)
        if previous:
            if previous.get("bridge_ok") is True:
                continue
            attempted_at = _parse_time(previous.get("dispatched_at"))
            if attempted_at is not None and (current - attempted_at).total_seconds() < retry_seconds:
                continue
            retry_attempted += 1

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
        ledger_row = {
            "fingerprint": fingerprint,
            "task_id": task_id,
            "repository": repository,
            "dispatched_at": utc_now(),
            "bridge_ok": bool(result.get("ok")),
            "http_status": result.get("http_status"),
        }
        _append_ledger(root, ledger_row)
        ledger[fingerprint] = ledger_row
        if result.get("ok"):
            dispatched += 1

    return {
        "ok": True,
        "runtime_dir": str(root),
        "scanned": scanned,
        "eligible": eligible,
        "dispatched": dispatched,
        "skipped_no_token": skipped_no_token,
        "skipped_stale_checkpoint": skipped_stale_checkpoint,
        "retry_attempted": retry_attempted,
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
