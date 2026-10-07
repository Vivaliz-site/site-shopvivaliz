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
import fcntl
import json
import os
import re
import urllib.error
import urllib.request
import urllib.parse
from contextlib import contextmanager
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

try:
    from .agent_task_state import (
        RUNTIME_DIR, TaskStateError, bind_conversation, claim_recovery_ownership,
        load_task, record_recovery_state, release_recovery_ownership, release_recovery_ownership,
    )
    from .task_continuation_watchdog import DEFAULT_LOOKBACK_DAYS, read_requests, _fingerprint as checkpoint_fingerprint
except ImportError:  # direct CLI execution from repository root
    from agent_task_state import (
        RUNTIME_DIR, TaskStateError, bind_conversation, claim_recovery_ownership,
        load_task, record_recovery_state,
    )
    from task_continuation_watchdog import DEFAULT_LOOKBACK_DAYS, read_requests, _fingerprint as checkpoint_fingerprint

LEDGER_FILE = "_chatgpt-continuity-nudges.jsonl"
LOCK_FILE = "_continuity-execution.lock"
DEFAULT_BRIDGE_URL = "http://127.0.0.1:18081/api/chatgpt-continuity/bridge.php"
DEFAULT_BRIDGE_HOST_HEADER = "shopvivaliz.com.br"
DEFAULT_TOKEN_FILE = Path("/home/ubuntu/.config/shopvivaliz-chatgpt-continuity/bridge.token")
LEGACY_TOKEN_FILE = Path("/home/ubuntu/shopvivaliz-deploy/shared/storage/private/chatgpt-continuity/bridge.token")
DEFAULT_BRIDGE_RETRY_SECONDS = 300
DEFAULT_MAX_WEB_ATTEMPTS = 2
WORKER_ATTEMPT_TERMINAL_STATUSES = frozenset({
    "PROGRESS_CONFIRMED",
    "SENT",
    "SENT_UNCONFIRMED",
    "STALLED_NOT_CONFIRMED",
    "CONVERSATION_NOT_FOUND",
    "ERROR",
})


def _durable_handoff_enabled() -> bool:
    return os.getenv('SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF', '0').strip().lower() in {'1','true','yes','on'}



def recovery_state_for_worker_status(worker_status: str, send_attempt_count: int, max_web_attempts: int) -> str:
    status = str(worker_status or '').strip().upper()
    sends = max(0, int(send_attempt_count or 0))
    budget = max(1, int(max_web_attempts or 1))
    if status in {'PENDING', 'CLAIMED'}:
        return 'RECOVERY_CLAIMED'
    if status == 'PROGRESS_CONFIRMED':
        return 'PROGRESS_CONFIRMED'
    if status in {'SENT', 'SENT_UNCONFIRMED'}:
        return 'RECOVERY_EXHAUSTED' if sends >= budget else 'WAITING_FOR_REAL_RESPONSE'
    if status in {'ERROR', 'CONVERSATION_NOT_FOUND'} and sends >= budget:
        return 'RECOVERY_EXHAUSTED'
    return 'RECOVERY_ACTIONED' if sends > 0 else 'RECOVERY_CLAIMED'

def resolve_bridge_token(explicit_token: str = "") -> str:
    direct = explicit_token.strip() or os.getenv("CHATGPT_CONTINUITY_BRIDGE_TOKEN", "").strip()
    if direct:
        return direct

    configured = os.getenv("CHATGPT_CONTINUITY_BRIDGE_TOKEN_FILE", "").strip()
    candidates = [Path(configured).expanduser()] if configured else []
    candidates.extend((DEFAULT_TOKEN_FILE, LEGACY_TOKEN_FILE))
    seen: set[Path] = set()
    for token_path in candidates:
        token_path = token_path.expanduser()
        if token_path in seen:
            continue
        seen.add(token_path)
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


def _ledger_bound_conversation_id(ledger: dict[str, dict[str, Any]], task_id: str) -> str:
    candidates = []
    for row in ledger.values():
        if str(row.get("task_id", "")).strip() != str(task_id).strip():
            continue
        if str(row.get("worker_status", "")).strip().upper() != "PROGRESS_CONFIRMED":
            continue
        value = str(row.get("conversation_id", "")).strip()
        if not re.fullmatch(r"[A-Za-z0-9_-]{8,160}", value):
            continue
        observed = _parse_time(row.get("worker_status_observed_at") or row.get("dispatched_at"))
        candidates.append((observed or datetime.min.replace(tzinfo=timezone.utc), value))
    if not candidates:
        return ""
    candidates.sort(key=lambda item: item[0], reverse=True)
    return candidates[0][1]


def _bound_conversation_id(runtime_dir: Path, task_id: str) -> str:
    if not task_id or "/" in task_id or "\\" in task_id:
        return ""
    path = runtime_dir / f"{task_id}.json"
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return ""
    value = str(payload.get("conversation_id", "")).strip() if isinstance(payload, dict) else ""
    return value if re.fullmatch(r"[A-Za-z0-9_-]{8,160}", value) else ""


def _checkpoint_updated_at(runtime_dir: Path, task_id: str) -> datetime | None:
    if not task_id or "/" in task_id or "\\" in task_id:
        return None
    path = runtime_dir / f"{task_id}.json"
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None
    if not isinstance(payload, dict):
        return None
    return _parse_time(payload.get("updated_at"))


def _conversation_dispatch_owners(
    runtime_dir: Path,
    requests: list[dict[str, Any]],
    ledger: dict[str, dict[str, Any]],
) -> dict[str, str]:
    """Return the single current request allowed to drive each conversation.

    An already accepted/in-flight browser attempt keeps ownership until the
    bridge reports a terminal result. Otherwise the newest RUNNING checkpoint
    owns the conversation. This prevents two independent stale tasks bound to
    the same ChatGPT thread from sending or probing it concurrently.
    """
    owners: dict[str, tuple[tuple[Any, ...], str]] = {}
    floor = datetime.min.replace(tzinfo=timezone.utc)
    for request in requests:
        if str(request.get("preferred_executor", "")).strip() != "chatgpt_common":
            continue
        if str(request.get("status", "")).strip() != "queued":
            continue
        if not _request_matches_current_checkpoint(runtime_dir, request):
            continue
        fingerprint = str(request.get("fingerprint", "")).strip()
        task_id = str(request.get("task_id", "")).strip()
        if not fingerprint or not task_id:
            continue
        conversation_id = (
            _bound_conversation_id(runtime_dir, task_id)
            or _ledger_bound_conversation_id(ledger, task_id)
        )
        if not conversation_id:
            continue
        previous = ledger.get(fingerprint) or {}
        worker_status = str(previous.get("worker_status", "")).strip().upper()
        active_attempt = previous.get("bridge_ok") is True and worker_status in {"", "PENDING", "CLAIMED"}
        rank = (
            1 if active_attempt else 0,
            _checkpoint_updated_at(runtime_dir, task_id) or floor,
            _parse_time(request.get("created_at")) or floor,
            task_id,
            fingerprint,
        )
        current = owners.get(conversation_id)
        if current is None or rank > current[0]:
            owners[conversation_id] = (rank, fingerprint)
    return {conversation_id: fingerprint for conversation_id, (_, fingerprint) in owners.items()}


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
    created_at = _parse_time(payload.get("created_at"))
    if created_at is None:
        return False
    if (datetime.now(timezone.utc) - created_at).total_seconds() > DEFAULT_LOOKBACK_DAYS * 86400:
        return False
    if not str(payload.get("next_action", "")).strip():
        return False
    request_repo = str(request.get("repository", "")).strip()
    current_repo = str(payload.get("repository", "")).strip()
    if request_repo != current_repo:
        return False
    return str(request.get("fingerprint", "")).strip() == checkpoint_fingerprint(payload)

@contextmanager
def _dispatcher_lock(runtime_dir: Path):
    runtime_dir.mkdir(parents=True, exist_ok=True)
    path = runtime_dir / LOCK_FILE
    with path.open("a+", encoding="utf-8") as handle:
        try:
            fcntl.flock(handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            yield False
            return
        try:
            yield True
        finally:
            fcntl.flock(handle.fileno(), fcntl.LOCK_UN)


def _fsync_dir(path: Path) -> None:
    try:
        fd = os.open(path, os.O_RDONLY)
    except OSError:
        return
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def _append_ledger(runtime_dir: Path, row: dict[str, Any]) -> None:
    runtime_dir.mkdir(parents=True, exist_ok=True)
    path = runtime_dir / LEDGER_FILE
    with path.open("a", encoding="utf-8") as handle:
        try:
            os.chmod(path, 0o600)
        except OSError:
            pass
        handle.write(json.dumps(row, ensure_ascii=False, sort_keys=True) + "\n")
        handle.flush()
        os.fsync(handle.fileno())
    _fsync_dir(runtime_dir)


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
    conversation_id: str = "",
    timeout_seconds: int = 15,
    bridge_host_header: str = "",
) -> dict[str, Any]:
    """Isolated so tests can monkeypatch it without a real network call."""
    payload = {"operation": "enqueue", "task_id": task_id, "repository": repository}
    if conversation_id:
        payload["conversation_id"] = conversation_id
    body = json.dumps(payload).encode("utf-8")
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


def query_nudge_status_via_bridge(
    *,
    bridge_url: str,
    token: str,
    task_id: str,
    timeout_seconds: int = 15,
    bridge_host_header: str = "",
) -> dict[str, Any]:
    """Read the worker outcome without exposing credentials or browser state."""
    body = json.dumps({"operation": "status", "task_id": task_id}).encode("utf-8")
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


def _run_once_locked(
    *,
    runtime_dir: Path | None = None,
    bridge_url: str = "",
    token: str = "",
    enqueue: Any = enqueue_nudge_via_bridge,
    query_status: Any = query_nudge_status_via_bridge,
) -> dict[str, Any]:
    root = Path(runtime_dir or RUNTIME_DIR)
    resolved_bridge_url = bridge_url or os.getenv("CHATGPT_CONTINUITY_BRIDGE_URL", "") or DEFAULT_BRIDGE_URL
    resolved_token = resolve_bridge_token(token)

    scanned = 0
    eligible = 0
    dispatched = 0
    skipped_no_token = 0
    skipped_stale_checkpoint = 0
    skipped_conversation_coalesced = 0
    skipped_unbound = 0
    retry_attempted = 0
    progress_followup_attempted = 0
    skipped_session_unavailable = 0
    skipped_attempt_limit = 0
    skipped_foreground_active = 0
    skipped_ownership_busy = 0

    ledger = _read_ledger(root)
    requests = read_requests(root)
    conversation_owners = _conversation_dispatch_owners(root, requests, ledger)
    retry_seconds = max(1, int(os.getenv("CHATGPT_CONTINUITY_BRIDGE_RETRY_SECONDS", DEFAULT_BRIDGE_RETRY_SECONDS)))
    max_web_attempts = max(1, int(os.getenv("CHATGPT_CONTINUITY_MAX_WEB_ATTEMPTS", DEFAULT_MAX_WEB_ATTEMPTS)))
    current = datetime.now(timezone.utc)

    for request in requests:
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

        resolved_conversation_id = (
            _bound_conversation_id(root, task_id)
            or _ledger_bound_conversation_id(ledger, task_id)
        )
        if not resolved_conversation_id:
            skipped_unbound += 1
            continue
        if (
            resolved_conversation_id
            and conversation_owners.get(resolved_conversation_id) != fingerprint
        ):
            skipped_conversation_coalesced += 1
            continue

        previous = ledger.get(fingerprint)
        if previous:
            attempted_at = _parse_time(previous.get("dispatched_at"))
            worker_status = str(previous.get("worker_status", "")).strip().upper()
            detail_code = str(previous.get("detail_code", "")).strip().upper()

            # Empty/PENDING/CLAIMED are observations of an active attempt,
            # not terminal outcomes. Re-poll them every dispatcher cycle so a
            # later worker result cannot be cached as "in flight" forever.
            if (
                previous.get("bridge_ok") is True
                and (
                    worker_status in {"", "PENDING", "CLAIMED"}
                    or (worker_status == "ERROR" and not detail_code)
                )
                and resolved_token
            ):
                status_result = query_status(
                    bridge_url=resolved_bridge_url,
                    token=resolved_token,
                    task_id=task_id,
                    bridge_host_header=_bridge_host_header(resolved_bridge_url),
                )
                if status_result.get("ok"):
                    status_body = status_result.get("body") if isinstance(status_result.get("body"), dict) else {}
                    nudge = status_body.get("nudge") if isinstance(status_body.get("nudge"), dict) else {}
                    observed_status = str(nudge.get("status", "")).strip().upper()
                    if observed_status:
                        observed_detail_code = str(nudge.get("detail_code", "")).strip().upper()
                        if observed_status != worker_status or (
                            observed_detail_code and observed_detail_code != detail_code
                        ):
                            observed = dict(previous)
                            observed["worker_status"] = observed_status
                            if observed_detail_code:
                                observed["detail_code"] = observed_detail_code
                            observed["worker_status_observed_at"] = utc_now()
                            confirmed_conversation_id = str(nudge.get("conversation_id", "")).strip()
                            if (
                                observed_status == "PROGRESS_CONFIRMED"
                                and re.fullmatch(r"[A-Za-z0-9_-]{8,160}", confirmed_conversation_id)
                            ):
                                observed["conversation_id"] = confirmed_conversation_id
                                if (
                                    not _bound_conversation_id(root, task_id)
                                    and Path(root).resolve() == Path(RUNTIME_DIR).resolve()
                                ):
                                    bind_conversation(task_id, conversation_id=confirmed_conversation_id)
                            has_send_counter = "send_attempt_count" in previous
                            prior_send_attempts = int(previous.get("send_attempt_count") or 0)
                            if not has_send_counter and worker_status in {"SENT", "SENT_UNCONFIRMED"}:
                                prior_send_attempts = int(previous.get("attempt_count") or 1)
                            if observed_status in {"SENT", "SENT_UNCONFIRMED", "PROGRESS_CONFIRMED"}:
                                if has_send_counter:
                                    observed["send_attempt_count"] = prior_send_attempts + 1
                                else:
                                    # Legacy ledger rows predate the dedicated
                                    # send counter. Their attempt_count included
                                    # the currently observed worker attempt, so
                                    # use it directly rather than double-counting.
                                    observed["send_attempt_count"] = int(previous.get("attempt_count") or 1)
                            else:
                                observed["send_attempt_count"] = prior_send_attempts
                            observed["recovery_state"] = recovery_state_for_worker_status(
                                observed_status, int(observed.get("send_attempt_count") or 0), max_web_attempts
                            )
                            _append_ledger(root, observed)
                            ledger[fingerprint] = observed
                            previous = observed
                            if _durable_handoff_enabled():
                                try:
                                    current_task = load_task(task_id)
                                    current_conversation = str(current_task.get("conversation_id", "")).strip()
                                    recovery_version = int(current_task.get("recovery_checkpoint_version") or current_task.get("checkpoint_version") or 0)
                                    if current_conversation and recovery_version:
                                        record_recovery_state(
                                            task_id,
                                            state=observed["recovery_state"],
                                            expected_conversation_id=current_conversation,
                                            expected_checkpoint_version=recovery_version,
                                            real_response_observed=(observed["recovery_state"] == "PROGRESS_CONFIRMED"),
                                        )
                                except TaskStateError:
                                    # Ownership/version changed while observing the bridge result;
                                    # the stale worker outcome must not mutate current task state.
                                    pass
                                if observed_status in WORKER_ATTEMPT_TERMINAL_STATUSES:
                                    try:
                                        release_recovery_ownership(
                                            task_id,
                                            owner_id=f"dispatcher:{fingerprint}",
                                            reason=f"worker_{observed_status.lower()}",
                                        )
                                    except TaskStateError:
                                        # A newer owner/checkpoint may already have taken over.
                                        # Never release ownership that no longer belongs to this
                                        # exact dispatcher fingerprint.
                                        pass
                        worker_status = observed_status
                        detail_code = observed_detail_code or detail_code

            if worker_status == "ERROR" and detail_code == "BOUND_SESSION_IDENTITY_MISMATCH":
                # This is a missing prerequisite, not a transient generation
                # failure. Re-enqueueing the immutable checkpoint cannot
                # authenticate the bound browser profile and only creates a
                # retry storm. Keep the failure visible until the session or
                # checkpoint changes.
                skipped_session_unavailable += 1
                continue

            if worker_status == "PROGRESS_CONFIRMED":
                # A real assistant response proves only that this continuation
                # round advanced. The task contract remains authoritative: if
                # the same checkpoint is still RUNNING after the normal retry
                # cooldown, follow the same conversation again until the task
                # itself reaches CONCLUIDO or BLOCKED_EXTERNAL.
                confirmed_at = _parse_time(
                    previous.get("worker_status_observed_at")
                    or previous.get("dispatched_at")
                )
                if (
                    confirmed_at is None
                    or (current - confirmed_at).total_seconds() < retry_seconds
                ):
                    continue
                progress_followup_attempted += 1

            # An active queue claim is already being handled by the browser
            # worker. Re-enqueueing would only duplicate the same continuation.
            if worker_status in {"PENDING", "CLAIMED"}:
                continue

            # SENT is a legacy ambiguous result and SENT_UNCONFIRMED explicitly
            # means the click did not produce observable assistant progress.
            # Both remain retryable after the bounded cooldown, but never
            # indefinitely for the same unchanged checkpoint.
            if attempted_at is not None and (current - attempted_at).total_seconds() < retry_seconds:
                continue
            previous_attempts = int(previous.get("attempt_count") or 1)
            previous_send_attempts = int(previous.get("send_attempt_count") or 0)
            if (
                "send_attempt_count" not in previous
                and worker_status in {"SENT", "SENT_UNCONFIRMED"}
            ):
                previous_send_attempts = previous_attempts

            if worker_status in {"SENT", "SENT_UNCONFIRMED"}:
                # The bounded Web budget is a send budget, not a generic
                # transport/observation-attempt budget. Only statuses proving
                # that a continuation message was actually submitted may
                # exhaust it. ERROR, CONVERSATION_NOT_FOUND and
                # STALLED_NOT_CONFIRMED remain retryable after the cooldown.
                if previous_send_attempts >= max_web_attempts:
                    skipped_attempt_limit += 1
                    continue
            retry_attempted += 1

        eligible += 1

        if not resolved_token:
            skipped_no_token += 1
            continue

        claimed_state = None
        if _durable_handoff_enabled():
            try:
                claimed_state = claim_recovery_ownership(
                    task_id,
                    owner_id=f"dispatcher:{fingerprint}",
                    allowed_actions=["browser_reload", "browser_click", "browser_stop", "continuation_send"],
                    ttl_seconds=max(90, retry_seconds),
                )
            except TaskStateError:
                skipped_ownership_busy += 1
                continue
            if str(claimed_state.get("recovery_state", "")).strip() == "FOREGROUND_ACTIVE":
                skipped_foreground_active += 1
                continue

        result = enqueue(
            bridge_url=resolved_bridge_url,
            token=resolved_token,
            task_id=task_id,
            repository=repository,
            conversation_id=resolved_conversation_id,
            bridge_host_header=_bridge_host_header(resolved_bridge_url),
        )
        previous_row = ledger.get(fingerprint) or {}
        previous_attempt_count = int(previous_row.get("attempt_count") or 0)
        previous_send_attempt_count = int(previous_row.get("send_attempt_count") or 0)
        if (
            "send_attempt_count" not in previous_row
            and str(previous_row.get("worker_status", "")).strip().upper() in {"SENT", "SENT_UNCONFIRMED"}
        ):
            previous_send_attempt_count = previous_attempt_count
        ledger_row = {
            "fingerprint": fingerprint,
            "task_id": task_id,
            "repository": repository,
            "dispatched_at": utc_now(),
            "bridge_ok": bool(result.get("ok")),
            "http_status": result.get("http_status"),
            "enqueued": bool((result.get("body") or {}).get("enqueued")) if isinstance(result.get("body"), dict) else None,
            "worker_status": "",
            "recovery_state": str((claimed_state or {}).get("recovery_state") or "RECOVERY_CLAIMED"),
            "attempt_count": previous_attempt_count + 1,
            "send_attempt_count": previous_send_attempt_count,
        }
        if resolved_conversation_id:
            ledger_row["conversation_id"] = resolved_conversation_id
        _append_ledger(root, ledger_row)
        ledger[fingerprint] = ledger_row
        if result.get("ok"):
            dispatched += 1

    # Keep failed outcomes visible throughout cooldown. Only the current
    # queued fingerprint may affect readiness; completed/superseded work must
    # not poison health. This is observation only, never a retry/automation gate.
    failed = 0
    for request in requests:
        if (request.get("preferred_executor") != "chatgpt_common"
                or request.get("status") != "queued"
                or not _request_matches_current_checkpoint(root, request)):
            continue
        task_id = str(request.get("task_id", "")).strip()
        fingerprint = str(request.get("fingerprint", "")).strip()
        conversation_id = (
            _bound_conversation_id(root, task_id)
            or _ledger_bound_conversation_id(ledger, task_id)
        )
        if conversation_id and conversation_owners.get(conversation_id) != fingerprint:
            continue
        outcome = ledger.get(fingerprint)
        if not outcome:
            continue
        worker_status = str(outcome.get("worker_status", "")).strip().upper()
        if outcome.get("bridge_ok") is not True or worker_status not in {
            "", "PENDING", "CLAIMED", "PROGRESS_CONFIRMED"
        }:
            failed += 1

    return {
        "ok": True,
        "failed": failed,
        "runtime_dir": str(root),
        "scanned": scanned,
        "eligible": eligible,
        "dispatched": dispatched,
        "skipped_no_token": skipped_no_token,
        "skipped_stale_checkpoint": skipped_stale_checkpoint,
        "skipped_conversation_coalesced": skipped_conversation_coalesced,
        "skipped_unbound": skipped_unbound,
        "retry_attempted": retry_attempted,
        "progress_followup_attempted": progress_followup_attempted,
        "skipped_session_unavailable": skipped_session_unavailable,
        "skipped_attempt_limit": skipped_attempt_limit,
        "skipped_foreground_active": skipped_foreground_active,
        "skipped_ownership_busy": skipped_ownership_busy,
        "generated_at": utc_now(),
    }


def run_once(
    *,
    runtime_dir: Path | None = None,
    bridge_url: str = "",
    token: str = "",
    enqueue: Any = enqueue_nudge_via_bridge,
    query_status: Any = query_nudge_status_via_bridge,
) -> dict[str, Any]:
    root = Path(runtime_dir or RUNTIME_DIR)
    with _dispatcher_lock(root) as acquired:
        if not acquired:
            return {
                "ok": True,
                "runtime_dir": str(root),
                "scanned": 0,
                "eligible": 0,
                "dispatched": 0,
                "skipped_no_token": 0,
                "skipped_stale_checkpoint": 0,
                "skipped_conversation_coalesced": 0,
                "skipped_unbound": 0,
                "retry_attempted": 0,
                "progress_followup_attempted": 0,
                "skipped_session_unavailable": 0,
                "skipped_attempt_limit": 0,
                "locked": True,
                "generated_at": utc_now(),
            }
        return _run_once_locked(
            runtime_dir=root,
            bridge_url=bridge_url,
            token=token,
            enqueue=enqueue,
            query_status=query_status,
        )


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
