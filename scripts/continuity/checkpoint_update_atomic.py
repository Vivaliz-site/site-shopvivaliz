"""Strict, atomic metadata-only recovery checkpoint writer for the ShopVivaliz MCP.

Never binds conversations, marks tasks completed, changes services, reads
historical route candidates or accepts filesystem paths from callers.
"""
from __future__ import annotations

import fcntl
import hashlib
import json
import os
import re
import shutil
import stat
import tempfile
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path("/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state")
FILENAME = "_retome-orquestrador-continuity-gpt6-20261010.json"
LOCK_NAME = "_agent-task-state.lock"
EXPECTED_TASK = "retome_orquestrador_shopvivaliz_continuity"
REQUIRED_RULES = frozenset({
    "AGENTS.md",
    "docs/knowledge/atendimento-chatgpt-bootstrap.md",
    "docs/knowledge/host-access.md",
    "docs/knowledge/README.md",
    "docs/knowledge/agent-rules.md",
    "docs/knowledge/task-continuity.md",
    "GEPETO-POLICY.md",
})
ALLOWED_RULES = REQUIRED_RULES | frozenset({
    "docs/knowledge/browser-sessions.md",
    "docs/knowledge/project.md",
    "docs/knowledge/dev-agent-briefing.md",
    "docs/knowledge/chatgpt-authenticator-recovery-20261010.md",
    "docs/AGENT-REMOTE-ACCESS.md",
    "docs/HOST-ACCESS.md",
})
SHA_RE = re.compile(r"[0-9a-f]{40}\Z")
REQUEST_RE = re.compile(r"[a-zA-Z0-9][a-zA-Z0-9_.:-]{7,159}\Z")
AUDIT_RE = re.compile(r"[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\Z")
SECRET_RE = re.compile(r"(?i)(sk-[a-z0-9_-]{10,}|bearer\s+\S{10,}|(?:password|token|cookie|seed|otp)\s*[:=]\s*\S+)")


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="microseconds").replace("+00:00", "Z")


def _validate(args: dict[str, Any]) -> tuple[str, str, dict[str, str], list[str], str, str]:
    allowed = {"request_id", "expected_last_checked_at", "rules_sha_by_path", "audit_ids", "next_action"}
    if not isinstance(args, dict) or set(args) != allowed:
        raise ValueError("checkpoint_update_invalid_fields")
    request_id = args["request_id"]
    if not isinstance(request_id, str) or not REQUEST_RE.fullmatch(request_id):
        raise ValueError("checkpoint_update_invalid_request_id")
    old_timestamp = args["expected_last_checked_at"]
    if not isinstance(old_timestamp, str) or not 10 <= len(old_timestamp) <= 60:
        raise ValueError("checkpoint_update_invalid_expected_time")
    rules = args["rules_sha_by_path"]
    if not isinstance(rules, dict) or not REQUIRED_RULES.issubset(rules) or not set(rules).issubset(ALLOWED_RULES):
        raise ValueError("checkpoint_update_invalid_rules")
    if any(not isinstance(v, str) or not SHA_RE.fullmatch(v) for v in rules.values()):
        raise ValueError("checkpoint_update_invalid_rules_sha")
    evidence_ids = args["audit_ids"]
    if not isinstance(evidence_ids, list) or len(evidence_ids) > 32 or any(
        not isinstance(v, str) or not AUDIT_RE.fullmatch(v) for v in evidence_ids
    ):
        raise ValueError("checkpoint_update_invalid_audit_ids")
    next_action = args["next_action"]
    if not isinstance(next_action, str) or not 10 <= len(next_action) <= 1800:
        raise ValueError("checkpoint_update_invalid_next_action")
    if SECRET_RE.search(next_action) or "\x00" in next_action:
        raise ValueError("checkpoint_update_sensitive_next_action")
    fingerprint = hashlib.sha256(json.dumps(args, sort_keys=True, ensure_ascii=False).encode()).hexdigest()
    return request_id, old_timestamp, rules, evidence_ids, next_action, fingerprint


def _observed(continuity: dict[str, Any]) -> dict[str, Any]:
    """Store only known non-secret readiness counters from the real controller."""
    if not isinstance(continuity, dict) or not isinstance(continuity.get("controller"), dict):
        raise ValueError("checkpoint_update_status_unavailable")
    controller = continuity["controller"]
    state = controller.get("state") or {}
    dispatcher = state.get("dispatcher") or {}
    monitor = continuity.get("chatgpt_monitor") or {}
    browser = continuity.get("chatgpt_browser") or {}
    routes = state.get("route_recovery") or {}
    if not isinstance(dispatcher, dict) or not isinstance(monitor, dict) or not isinstance(browser, dict):
        raise ValueError("checkpoint_update_status_invalid")
    reasons = continuity.get("degraded_reasons") or []
    if not isinstance(reasons, list):
        reasons = []
    return {
        "source": "controller_status/continuity_status",
        "controller_generated_at": str(continuity.get("generated_at") or "")[:48],
        "controller_service_active": controller.get("service_active") is True,
        "controller_active_sha": str(controller.get("active_sha") or "")[:40],
        "continuity_ready": continuity.get("continuity_ready") is True,
        "monitor_fresh": monitor.get("fresh") is True,
        "browser_authenticated": browser.get("authenticated") is True,
        "browser_session_state": str(browser.get("session_state") or "")[:32],
        "dispatcher_deferred_unbound": int(dispatcher.get("deferred_unbound") or 0),
        "dispatcher_recovered": int(dispatcher.get("recovered") or 0),
        "routes_bound": int(routes.get("bound") or 0),
        "routes_skipped_no_proof": int(routes.get("skipped_no_proof") or 0),
        "degraded_reasons": [
            value for value in reasons[:12]
            if isinstance(value, str) and re.fullmatch(r"[a-z0-9_:-]{1,80}", value)
        ],
    }


def _xattrs(path: Path) -> dict[str, bytes]:
    return {name: os.getxattr(path, name) for name in os.listxattr(path)}


def update_checkpoint(args: dict[str, Any], continuity: dict[str, Any]) -> dict[str, Any]:
    request_id, expected_time, shas, audit_ids, next_action, fingerprint = _validate(args)
    observed = _observed(continuity)
    root = ROOT
    target = root / FILENAME
    lockfile = root / LOCK_NAME
    if root.is_symlink() or not root.is_dir():
        raise RuntimeError("checkpoint_root_invalid")
    lock_fd = os.open(lockfile, os.O_RDWR | getattr(os, "O_NOFOLLOW", 0))
    tmp: Path | None = None
    try:
        for attempt in range(20):
            try:
                fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                if attempt == 19:
                    raise RuntimeError("checkpoint_state_lock_busy")
                time.sleep(0.1)
        fd = os.open(target, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
        try:
            original_stat = os.fstat(fd)
            if not stat.S_ISREG(original_stat.st_mode) or original_stat.st_size > 131072:
                raise ValueError("checkpoint_invalid_file")
            raw = os.read(fd, 131073)
        finally:
            os.close(fd)
        try:
            obj = json.loads(raw)
        except (ValueError, UnicodeError):
            raise ValueError("checkpoint_invalid_json") from None
        if not isinstance(obj, dict) or obj.get("task") != EXPECTED_TASK or obj.get("schema_version") != 1:
            raise ValueError("checkpoint_identity_mismatch")
        if obj.get("status") != "RUNNING":
            raise ValueError("checkpoint_status_not_running")
        history = obj.get("checkpoint_update_requests") or []
        if not isinstance(history, list):
            raise ValueError("checkpoint_update_history_invalid")
        for item in history:
            if isinstance(item, dict) and item.get("request_id") == request_id:
                if item.get("fingerprint") != fingerprint:
                    raise ValueError("checkpoint_request_id_conflict")
                return {
                    "ok": True, "saved": True, "verified": True, "deduplicated": True,
                    "status": "RUNNING", "last_checked_at": obj["last_checked_at"],
                    "request_id": request_id,
                }
        if obj.get("last_checked_at") != expected_time:
            raise ValueError("checkpoint_optimistic_lock_conflict")

        timestamp = utc_now()
        obj["last_checked_at"] = timestamp
        obj["rules_loaded"] = {
            "agent": "GPT-6", "ref": "main", "sha_by_path": shas, "verified_at": timestamp,
        }
        obj["observed_state"] = observed
        obj["audit_ids"] = list(dict.fromkeys((obj.get("audit_ids") or []) + audit_ids))[-64:]
        obj["next_action"] = next_action
        obj["checkpoint_update_requests"] = (
            history + [{"request_id": request_id, "fingerprint": fingerprint, "checked_at": timestamp}]
        )[-64:]
        obj["checkpoint_write"] = {"method": "atomic_replace_fsync_global_lock", "verified_at": timestamp}

        original_xattrs = _xattrs(target)
        fd, tmpname = tempfile.mkstemp(prefix="._orquestrador-checkpoint-", suffix=".tmp", dir=root)
        tmp = Path(tmpname)
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(obj, handle, sort_keys=True, ensure_ascii=False, indent=2)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.chown(tmp, original_stat.st_uid, original_stat.st_gid)
        shutil.copystat(target, tmp, follow_symlinks=False)
        tempstat = os.stat(tmp)
        if ((tempstat.st_uid, tempstat.st_gid, stat.S_IMODE(tempstat.st_mode))
                != (original_stat.st_uid, original_stat.st_gid, stat.S_IMODE(original_stat.st_mode))
                or _xattrs(tmp) != original_xattrs):
            raise RuntimeError("checkpoint_metadata_preservation_failed")
        current = os.lstat(target)
        if (current.st_ino, current.st_size, current.st_mtime_ns) != (
            original_stat.st_ino, original_stat.st_size, original_stat.st_mtime_ns
        ):
            raise RuntimeError("checkpoint_changed_during_update")
        os.replace(tmp, target)
        tmp = None
        directory_fd = os.open(root, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
        try:
            os.fsync(directory_fd)
        finally:
            os.close(directory_fd)
        reread = json.loads(target.read_text(encoding="utf-8"))
        updated_stat = os.stat(target)
        verified = (
            reread.get("last_checked_at") == timestamp
            and reread.get("rules_loaded", {}).get("sha_by_path") == shas
            and reread.get("status") == "RUNNING"
            and reread.get("task") == EXPECTED_TASK
            and _xattrs(target) == original_xattrs
            and (updated_stat.st_uid, updated_stat.st_gid, stat.S_IMODE(updated_stat.st_mode))
            == (original_stat.st_uid, original_stat.st_gid, stat.S_IMODE(original_stat.st_mode))
        )
        if not verified:
            raise RuntimeError("checkpoint_postwrite_verification_failed")
        return {
            "ok": True, "saved": True, "verified": True, "deduplicated": False,
            "status": "RUNNING", "last_checked_at": timestamp,
            "audit_ids_count": len(obj["audit_ids"]), "rules_loaded_count": len(shas),
            "owner_mode_acl_preserved": True, "request_id": request_id,
        }
    finally:
        if tmp is not None:
            tmp.unlink(missing_ok=True)
        os.close(lock_fd)
