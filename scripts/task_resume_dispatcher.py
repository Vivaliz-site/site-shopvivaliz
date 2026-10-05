#!/usr/bin/env python3
"""Execute one bounded detached recovery attempt for stale durable task checkpoints.

The watchdog only detects stale work. This dispatcher is the missing execution
boundary: it validates a queued resume request against the current checkpoint,
runs one finite authenticated executor attempt in an isolated clone, and only
counts success when durable task state actually advances.

It intentionally does not loop paid providers. One checkpoint fingerprint is
attempted at most once; a new attempt requires a genuinely advanced checkpoint.
"""
from __future__ import annotations

import argparse
import fcntl
import hashlib
import json
import os
import re
import shutil
import subprocess
import tempfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Sequence

try:
    from .agent_task_state import DEFAULT_REPOSITORY, RUNTIME_DIR
    from .task_continuation_watchdog import DEFAULT_LOOKBACK_DAYS, read_requests
except ImportError:
    from agent_task_state import DEFAULT_REPOSITORY, RUNTIME_DIR
    from task_continuation_watchdog import DEFAULT_LOOKBACK_DAYS, read_requests

ROOT = Path(__file__).resolve().parents[1]
EXECUTIONS_FILE = "_resume-executions.jsonl"
EXECUTION_RECORD_PREFIX = "_resume-execution-"
LOCK_FILE = "_continuity-execution.lock"
TERMINAL_STATES = frozenset({"CONCLUIDO", "BLOCKED_EXTERNAL"})
DEFAULT_TIMEOUT_SECONDS = 900
DEFAULT_MAX_REQUESTS = 1
DEFAULT_RETRY_AFTER_SECONDS = 900
DEFAULT_CHATGPT_NUDGE_GRACE_SECONDS = 900
CHATGPT_NUDGE_LEDGER_FILE = "_chatgpt-continuity-nudges.jsonl"
ALLOWED_REPOSITORIES = frozenset({
    "Vivaliz-site/site-shopvivaliz",
    "Vivaliz-site/-shopvivaliz-pipeline",
    "Vivaliz-site/amazon-returns-safet",
    "Vivaliz-site/ml-pricing-api",
    "Vivaliz-site/mercadolivre-returns-recovery",
    "Vivaliz-site/shopvivaliz-m365",
    "Vivaliz-site/buscador",
    "fredmourao-ai/mei-mg-email",
    "fredmourao-ai/solange-rolla-consultorio",
})
REPOSITORY_RE = re.compile(r"^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$")


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def _parse_utc(value: str) -> datetime | None:
    raw = str(value or "").strip()
    if not raw:
        return None
    try:
        return datetime.fromisoformat(raw.replace("Z", "+00:00")).astimezone(timezone.utc)
    except ValueError:
        return None


def _safe_task_id(value: str) -> str:
    safe = re.sub(r"[^A-Za-z0-9._-]+", "-", str(value).strip()).strip(".-")
    return safe[:160]


def _safe_repository(value: str) -> str:
    repository = str(value or DEFAULT_REPOSITORY).strip()
    if not REPOSITORY_RE.fullmatch(repository):
        raise ValueError("invalid repository identity")
    if repository not in ALLOWED_REPOSITORIES:
        raise ValueError("repository is not governed by global continuity")
    return repository


def _state_path(runtime_dir: Path, task_id: str) -> Path:
    safe = _safe_task_id(task_id)
    return runtime_dir / f"{safe}.json"


def _load_json(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}
    return value if isinstance(value, dict) else {}


def _atomic_json(path: Path, payload: dict[str, Any]) -> None:
    data = (json.dumps(payload, ensure_ascii=False, indent=2, sort_keys=True) + "\n").encode("utf-8")
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp_name = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".tmp", dir=path.parent)
    tmp_path = Path(tmp_name)
    try:
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, "wb", closefd=True) as handle:
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(tmp_path, path)
        dir_fd = os.open(path.parent, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
        try:
            os.fsync(dir_fd)
        finally:
            os.close(dir_fd)
    finally:
        tmp_path.unlink(missing_ok=True)


def _execution_record_path(runtime_dir: Path, fingerprint: str) -> Path:
    digest = hashlib.sha256(str(fingerprint).encode("utf-8")).hexdigest()[:32]
    return runtime_dir / f"{EXECUTION_RECORD_PREFIX}{digest}.json"


def enqueue_execution(runtime_dir: Path, project_dir: Path, request: dict[str, Any], state: dict[str, Any], timeout_seconds: int) -> dict[str, Any]:
    fingerprint = str(request.get("fingerprint", "")).strip()
    if not fingerprint:
        raise ValueError("execution fingerprint required")
    record_path = _execution_record_path(runtime_dir, fingerprint)
    existing = _load_json(record_path)
    if str(existing.get("status", "")).strip() in {"queued", "running"}:
        existing["_created"] = False
        return existing
    now = utc_now()
    record = {
        "schema_version": 1, "request_id": str(request.get("id", "")).strip(),
        "task_id": str(state.get("task_id", "")).strip(),
        "repository": str(state.get("repository", DEFAULT_REPOSITORY)).strip() or DEFAULT_REPOSITORY,
        "fingerprint": fingerprint, "checkpoint_before": str(state.get("updated_at", "")).strip(),
        "project_dir": str(project_dir), "timeout_seconds": max(1, int(timeout_seconds)),
        "status": "queued", "created_at": now, "updated_at": now,
        "worker_pid": None, "worker_start_time": None, "result": None,
        "executor_exit_code": None, "diagnostic": {},
    }
    _atomic_json(record_path, record)
    record["_created"] = True
    return record


def reconcile_executions(runtime_dir: Path, project_dir: Path) -> dict[str, int]:
    counts = {
        "in_flight": 0,
        "reconciled": 0,
        "recovered": 0,
        "progressed": 0,
        "terminal": 0,
        "no_progress": 0,
        "failed": 0,
    }
    ledger_path = runtime_dir / EXECUTIONS_FILE
    for path in runtime_dir.glob(f"{EXECUTION_RECORD_PREFIX}*.json"):
        record = _load_json(path)
        status = str(record.get("status", "")).strip()
        if status in {"queued", "running"}:
            counts["in_flight"] += 1
            continue
        if status != "completed":
            continue

        result = str(record.get("result", "")).strip()
        diagnostic = record.get("diagnostic") if isinstance(record.get("diagnostic"), dict) else {}
        evidence = record.get("evidence") if isinstance(record.get("evidence"), dict) else {
            "checkpoint_before": str(record.get("checkpoint_before", "")).strip(),
            "checkpoint_after": str(record.get("checkpoint_after", "")).strip(),
            "diagnostic": diagnostic,
        }
        row = {
            "request_id": str(record.get("request_id", "")).strip(),
            "task_id": str(record.get("task_id", "")).strip(),
            "repository": str(record.get("repository", DEFAULT_REPOSITORY)).strip() or DEFAULT_REPOSITORY,
            "fingerprint": str(record.get("fingerprint", "")).strip(),
            "result": result,
            "executor_exit_code": record.get("executor_exit_code"),
            "checkpoint_before": str(record.get("checkpoint_before", "")).strip(),
            "checkpoint_after": str(record.get("checkpoint_after", "")).strip(),
            "diagnostic": diagnostic,
            "evidence": evidence,
            "created_at": utc_now(),
        }
        _append_jsonl(ledger_path, row)
        now = utc_now()
        record["status"] = "reconciled"
        record["evidence"] = evidence
        record["reconciled_at"] = now
        record["updated_at"] = now
        _atomic_json(path, record)
        counts["reconciled"] += 1
        if result == "progress":
            counts["progressed"] += 1
        elif result == "terminal":
            counts["terminal"] += 1
        elif result == "no_progress":
            counts["no_progress"] += 1
        elif result not in {"superseded", ""}:
            counts["failed"] += 1
    return counts


def _read_jsonl(path: Path) -> list[dict[str, Any]]:
    if not path.is_file():
        return []
    rows: list[dict[str, Any]] = []
    for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
        if not line.strip():
            continue
        try:
            row = json.loads(line)
        except json.JSONDecodeError:
            continue
        if isinstance(row, dict):
            rows.append(row)
    return rows


def _append_jsonl(path: Path, row: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("a", encoding="utf-8") as handle:
        try:
            os.chmod(path, 0o600)
        except OSError:
            pass
        handle.write(json.dumps(row, ensure_ascii=False, sort_keys=True) + "\n")
        handle.flush()
        os.fsync(handle.fileno())


def _recent_successful_chatgpt_nudge(
    runtime_dir: Path,
    request: dict[str, Any],
    *,
    grace_seconds: int = DEFAULT_CHATGPT_NUDGE_GRACE_SECONDS,
) -> bool:
    """Give chatgpt_common its bounded first chance before detached fallback."""
    if str(request.get("preferred_executor", "")).strip() != "chatgpt_common":
        return False

    fingerprint = str(request.get("fingerprint", "")).strip()
    task_id = str(request.get("task_id", "")).strip()
    repository = str(request.get("repository", DEFAULT_REPOSITORY)).strip() or DEFAULT_REPOSITORY
    if not fingerprint or not task_id:
        return False

    now = datetime.now(timezone.utc)
    grace = max(0, int(grace_seconds))
    for row in reversed(_read_jsonl(runtime_dir / CHATGPT_NUDGE_LEDGER_FILE)):
        if str(row.get("fingerprint", "")).strip() != fingerprint:
            continue
        if str(row.get("task_id", "")).strip() != task_id:
            continue
        row_repository = str(row.get("repository", DEFAULT_REPOSITORY)).strip() or DEFAULT_REPOSITORY
        if row_repository != repository:
            continue
        if row.get("bridge_ok") is not True:
            continue
        dispatched_at = _parse_utc(str(row.get("dispatched_at", "")))
        if dispatched_at is None:
            continue
        age_seconds = (now - dispatched_at).total_seconds()
        if age_seconds < 0 or age_seconds > grace:
            return False

        worker_status = str(row.get("worker_status", "")).strip().upper()
        if worker_status in {
            "SENT",
            "SENT_UNCONFIRMED",
            "STALLED_NOT_CONFIRMED",
            "CONVERSATION_NOT_FOUND",
            "ERROR",
        }:
            # A bridge enqueue/click without observed assistant progress is
            # not enough to block the detached recovery tier.
            return False

        # While the worker is still pending/claimed we preserve ChatGPT's
        # bounded first chance. Once progress is explicitly confirmed, the
        # same grace window continues to avoid parallel executors.
        return worker_status in {"", "PENDING", "CLAIMED", "PROGRESS_CONFIRMED"}
    return False


def _failed_chatgpt_nudge_after(
    runtime_dir: Path,
    request: dict[str, Any],
    previous_at: datetime,
) -> bool:
    """Return true when ChatGPT definitively failed after the last detached attempt."""
    fingerprint = str(request.get("fingerprint", "")).strip()
    task_id = str(request.get("task_id", "")).strip()
    repository = str(request.get("repository", DEFAULT_REPOSITORY)).strip() or DEFAULT_REPOSITORY
    if not fingerprint or not task_id:
        return False

    failed_statuses = {
        "SENT",
        "SENT_UNCONFIRMED",
        "STALLED_NOT_CONFIRMED",
        "CONVERSATION_NOT_FOUND",
        "ERROR",
    }
    for row in reversed(_read_jsonl(runtime_dir / CHATGPT_NUDGE_LEDGER_FILE)):
        if str(row.get("fingerprint", "")).strip() != fingerprint:
            continue
        if str(row.get("task_id", "")).strip() != task_id:
            continue
        row_repository = str(row.get("repository", DEFAULT_REPOSITORY)).strip() or DEFAULT_REPOSITORY
        if row_repository != repository:
            continue
        if str(row.get("worker_status", "")).strip().upper() not in failed_statuses:
            continue
        observed_at = _parse_utc(str(row.get("worker_status_observed_at", "")))
        if observed_at is None:
            observed_at = _parse_utc(str(row.get("dispatched_at", "")))
        if observed_at is None:
            continue
        return observed_at > previous_at
    return False


def _browser_only_e2e_probe(request: dict[str, Any], state: dict[str, Any]) -> bool:
    """Recognize the strict browser E2E sentinel contract.

    These synthetic checkpoints exist specifically to prove that the bound
    ChatGPT Web conversation executed the two allowlisted state transitions.
    Letting Gemini/CLI run the same commands would manufacture a terminal
    checkpoint without proving browser continuity.
    """
    task_id = str(state.get("task_id", "")).strip()
    if not task_id.startswith("continuity-e2e-"):
        return False
    if str(request.get("preferred_executor", "")).strip() != "chatgpt_common":
        return False
    if str(state.get("agent_id", "")).strip() != "chatgpt-common":
        return False
    if not str(state.get("conversation_id", "")).strip():
        return False
    next_action = str(state.get("next_action", ""))
    commands = [
        line.split(") ", 1)[1].strip()
        for line in next_action.splitlines()
        if line.startswith(("1) ", "2) ")) and ") " in line
    ]
    return commands == [
        f"python3 scripts/agent_task_state.py ready --task {task_id} --verification continuity_e2e_pass",
        f"python3 scripts/agent_task_state.py complete --task {task_id}",
    ]


def _state_signature(payload: dict[str, Any]) -> str:
    evidence = payload.get("evidence")
    evidence_rows = evidence if isinstance(evidence, list) else []
    last_evidence = str(evidence_rows[-1]) if evidence_rows else ""
    basis = {
        "status": str(payload.get("status", "")).strip(),
        "next_action": str(payload.get("next_action", "")).strip(),
        "verification": str(payload.get("verification") or "").strip(),
        "evidence_count": len(evidence_rows),
        "last_evidence": last_evidence,
        "blocker": payload.get("blocker"),
    }
    raw = json.dumps(basis, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(raw.encode("utf-8")).hexdigest()


def _material_progress(before: dict[str, Any], after: dict[str, Any]) -> bool:
    """Accept only objective durable advancement, not timestamp/evidence churn."""
    before_status = str(before.get("status", "")).strip()
    after_status = str(after.get("status", "")).strip()
    if after_status in TERMINAL_STATES and after_status != before_status:
        return True

    before_next = str(before.get("next_action", "")).strip()
    after_next = str(after.get("next_action", "")).strip()
    if after_next and after_next != before_next:
        return True

    return False


def _restore_executor_owned_no_progress(
    state_path: Path,
    before: dict[str, Any],
    after: dict[str, Any],
    request_id: str,
) -> bool:
    """Undo executor-owned checkpoint churn without clobbering concurrent progress."""
    history = after.get("history")
    rows = history if isinstance(history, list) else []
    latest = rows[-1] if rows and isinstance(rows[-1], dict) else {}
    if str(latest.get("resume_request_id", "")).strip() != str(request_id).strip():
        return False

    payload = (json.dumps(before, ensure_ascii=False, indent=2, sort_keys=True) + "\n").encode("utf-8")
    state_path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp_name = tempfile.mkstemp(prefix=f".{state_path.name}.", suffix=".tmp", dir=state_path.parent)
    tmp_path = Path(tmp_name)
    try:
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, "wb", closefd=True) as handle:
            handle.write(payload)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(tmp_path, state_path)
        dir_fd = os.open(state_path.parent, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
        try:
            os.fsync(dir_fd)
        finally:
            os.close(dir_fd)
        return True
    finally:
        tmp_path.unlink(missing_ok=True)


def _request_matches_state(request: dict[str, Any], state: dict[str, Any]) -> bool:
    if str(state.get("status", "")).strip() != "RUNNING":
        return False
    created_at = _parse_utc(str(state.get("created_at", "")))
    if created_at is None:
        return False
    if (datetime.now(timezone.utc) - created_at).total_seconds() > DEFAULT_LOOKBACK_DAYS * 86400:
        return False
    if str(request.get("task_id", "")).strip() != str(state.get("task_id", "")).strip():
        return False
    request_repository = str(request.get("repository", DEFAULT_REPOSITORY)).strip() or DEFAULT_REPOSITORY
    state_repository = str(state.get("repository", DEFAULT_REPOSITORY)).strip() or DEFAULT_REPOSITORY
    if request_repository != state_repository:
        return False
    if str(request.get("checkpoint_updated_at", "")).strip() != str(state.get("updated_at", "")).strip():
        return False
    if str(request.get("next_action", "")).strip() != str(state.get("next_action", "")).strip():
        return False
    return bool(str(request.get("fingerprint", "")).strip())


def _build_prompt(request: dict[str, Any], state: dict[str, Any]) -> str:
    task_id = str(state.get("task_id", "")).strip()
    repository = _safe_repository(state.get("repository", DEFAULT_REPOSITORY))
    goal = str(state.get("goal", "")).strip()
    next_action = str(state.get("next_action", "")).strip()
    state_json = json.dumps(state, ensure_ascii=False, indent=2, sort_keys=True)
    return f"""DETACHED_CONTINUATION_EXECUTOR_V6

You are executing one finite recovery turn for a ShopVivaliz task whose
interactive ChatGPT stream stopped before terminal completion.

Task id: {task_id}
Repository: {repository}
Original goal: {goal}
Required next action: {next_action}

Durable checkpoint:
{state_json}

Rules:
- This is a real execution turn, not an acknowledgement or a plan-only reply.
- Read AGENTS.md and the task-continuity/project rules before mutation.
- Work only in this isolated repository clone. Never edit a production current/
  symlink or an active immutable release directly.
- Continue the original task autonomously through safe reversible actions.
- Use the authenticated repository tooling already available on the host when
  GitHub mutations are needed; do not expose credentials.
- If commits must be published, run only:
    python3 scripts/safe_git_push.py
  Never run git push directly. The wrapper publishes only the current
  non-protected branch and rejects arguments, deletion, force, and protected
  branch pushes.
- Before this process exits, durable state MUST reflect real progress:
  * if finished and freshly verified, run:
      python3 scripts/agent_task_state.py ready --task "$SHOPVIVALIZ_TASK_ID" --evidence "<objective evidence>" --verification "<fresh verification>"
      python3 scripts/agent_task_state.py complete --task "$SHOPVIVALIZ_TASK_ID"
  * if more work remains, run:
      python3 scripts/agent_task_state.py progress --task "$SHOPVIVALIZ_TASK_ID" --next-action "<concrete next action>" --evidence "<objective evidence>"
  * use block only for a genuine external blocker after distinct safe
    alternatives were actually exhausted.
- Do not update state merely to claim progress. Persist only work actually done.
- Do not wait for user confirmation for reversible actions already inside the
  original authorized scope.
"""


def _default_work_root() -> Path:
    configured = str(os.getenv("SHOPVIVALIZ_RESUME_WORK_ROOT", "")).strip()
    if configured:
        return Path(configured).expanduser()
    return Path.home() / ".cache" / "shopvivaliz-task-resume"


def _prepare_workspace(task_id: str, repository: str) -> Path:
    work_root = _default_work_root()
    work_root.mkdir(parents=True, exist_ok=True)
    workspace = Path(tempfile.mkdtemp(prefix=f"{_safe_task_id(task_id)}-", dir=work_root))
    repository_name = _safe_repository(repository)
    gh = shutil.which("gh")
    if not gh:
        raise OSError("authenticated GitHub CLI is required for governed repository recovery")
    subprocess.run(
        [
            gh,
            "repo",
            "clone",
            repository_name,
            str(workspace),
            "--",
            "--quiet",
            "--filter=blob:none",
            "--no-tags",
            "--single-branch",
        ],
        check=True,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        timeout=120,
    )
    return workspace


_SANITIZED_OUTPUT_MARKERS = (
    "background_paid_fallback_forbidden",
    "background_claude_fallback_authorized",
    "background_codex_fallback_authorized",
    "background_gemini_error",
    "background_gemini_exit_code",
    "background_gemini_reason",
    "background_gemini_model",
)


def _summarize_executor_artifacts(workspace: Path) -> dict[str, Any]:
    """Build a sanitized, allowlisted diagnostic from an ephemeral executor workspace.

    Only structured fields survive; raw prompt/stdout/stderr/secret material is
    never read into the returned mapping.
    """
    diagnostic: dict[str, Any] = {"background_paid_fallback_forbidden": False}
    logs_dir = Path(workspace) / "logs"

    output_path = logs_dir / "autonomous-provider-output.txt"
    if output_path.is_file():
        raw = output_path.read_bytes()
        diagnostic["provider_output_bytes"] = len(raw)
        diagnostic["provider_output_sha256"] = hashlib.sha256(raw).hexdigest()
        text = raw.decode("utf-8", errors="replace")
        for line in text.splitlines():
            entry = line.strip()
            if entry == "background_paid_fallback_forbidden=true":
                diagnostic["background_paid_fallback_forbidden"] = True
                continue
            if entry == "background_codex_fallback_authorized=true":
                diagnostic["background_codex_fallback_authorized"] = True
                continue
            if entry.startswith("background_gemini_error="):
                diagnostic["background_gemini_error"] = entry.split("=", 1)[1].strip()
                continue
            if entry.startswith("background_gemini_exit_code="):
                value = entry.split("=", 1)[1].strip()
                if value.lstrip("-").isdigit():
                    diagnostic["background_gemini_exit_code"] = int(value)
                continue
            if entry.startswith("background_gemini_reason="):
                diagnostic["background_gemini_reason"] = entry.split("=", 1)[1].strip()
            if entry.startswith("background_gemini_model="):
                diagnostic["background_gemini_model"] = entry.split("=", 1)[1].strip()

    attempts_path = logs_dir / "autonomous-provider-attempts.jsonl"
    attempts = _read_jsonl(attempts_path)
    if attempts:
        last = attempts[-1]
        diagnostic["provider"] = str(last.get("provider", ""))
        diagnostic["provider_status"] = str(last.get("status", ""))
        exit_code = last.get("exit_code")
        if isinstance(exit_code, bool):
            pass
        elif isinstance(exit_code, int):
            diagnostic["provider_attempt_exit_code"] = exit_code
        elif isinstance(exit_code, str) and exit_code.lstrip("-").isdigit():
            diagnostic["provider_attempt_exit_code"] = int(exit_code)

    return diagnostic


def _execute(
    *,
    runtime_dir: Path,
    project_dir: Path,
    request: dict[str, Any],
    state: dict[str, Any],
    executor: Sequence[str] | None,
    timeout_seconds: int,
) -> tuple[str, int | None, dict[str, Any], dict[str, Any]]:
    task_id = str(state.get("task_id", "")).strip()
    repository = _safe_repository(state.get("repository", DEFAULT_REPOSITORY))
    before = _state_signature(state)
    prompt_path: Path | None = None
    workspace: Path | None = None
    exit_code: int | None = None
    result = "executor_error"

    try:
        runtime_dir.mkdir(parents=True, exist_ok=True)
        fd, prompt_name = tempfile.mkstemp(prefix=".resume-", suffix=".txt", dir=runtime_dir)
        prompt_path = Path(prompt_name)
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            handle.write(_build_prompt(request, state))
            handle.flush()
            os.fsync(handle.fileno())

        if executor is None:
            workspace = _prepare_workspace(task_id, repository)
            cwd = workspace
            command = [str(project_dir / "scripts" / "autonomous-provider-failover.sh"), str(prompt_path)]
        else:
            cwd = project_dir
            command = [*executor, str(prompt_path)]

        env = os.environ.copy()
        env["SHOPVIVALIZ_AGENT_TASK_STATE_DIR"] = str(runtime_dir)
        env["SHOPVIVALIZ_CONTINUITY_STATE_CLI"] = str(project_dir / "scripts" / "agent_task_state.py")
        env["SHOPVIVALIZ_TASK_ID"] = task_id
        env["SHOPVIVALIZ_TASK_REPOSITORY"] = repository
        env["SHOPVIVALIZ_RESUME_STAGE"] = "cli_last"
        env["SHOPVIVALIZ_RESUME_RESULT_MODE"] = "task_state"
        env["SHOPVIVALIZ_RESUME_BACKGROUND"] = "1"
        env["SHOPVIVALIZ_RESUME_HISTORY_LENGTH"] = str(len(state.get("history", [])))
        env["SHOPVIVALIZ_RESUME_SOURCE"] = "task-continuation-watchdog"
        env["SHOPVIVALIZ_RESUME_REQUEST_ID"] = str(request.get("id", ""))
        env["SHOPVIVALIZ_RESUME_FINGERPRINT"] = str(request.get("fingerprint", ""))

        completed = subprocess.run(
            command,
            cwd=cwd,
            env=env,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            check=False,
            timeout=max(1, int(timeout_seconds)),
        )
        exit_code = int(completed.returncode)
        state_path = _state_path(runtime_dir, task_id)
        after_state = _load_json(state_path)
        if after_state and _material_progress(state, after_state):
            if str(after_state.get("status", "")).strip() in TERMINAL_STATES:
                result = "terminal"
            else:
                result = "progress"
        else:
            result = "no_progress"
            if after_state:
                _restore_executor_owned_no_progress(
                    state_path,
                    state,
                    after_state,
                    str(request.get("id", "")),
                )
                after_state = _load_json(state_path)
        diagnostic = _summarize_executor_artifacts(workspace) if workspace is not None else {}
        return result, exit_code, after_state, diagnostic
    except subprocess.TimeoutExpired:
        diagnostic = _summarize_executor_artifacts(workspace) if workspace is not None else {}
        return "timeout", None, _load_json(_state_path(runtime_dir, task_id)), diagnostic
    except (OSError, subprocess.SubprocessError):
        diagnostic = _summarize_executor_artifacts(workspace) if workspace is not None else {}
        return "executor_error", exit_code, _load_json(_state_path(runtime_dir, task_id)), diagnostic
    finally:
        if prompt_path is not None:
            prompt_path.unlink(missing_ok=True)
        if workspace is not None:
            shutil.rmtree(workspace, ignore_errors=True)


def run_once(
    *,
    runtime_dir: Path | None = None,
    project_dir: Path | None = None,
    executor: Sequence[str] | None = None,
    timeout_seconds: int = DEFAULT_TIMEOUT_SECONDS,
    max_requests: int = DEFAULT_MAX_REQUESTS,
    retry_after_seconds: int = DEFAULT_RETRY_AFTER_SECONDS,
) -> dict[str, Any]:
    runtime = Path(runtime_dir or RUNTIME_DIR)
    project = Path(project_dir or ROOT)
    runtime.mkdir(parents=True, exist_ok=True)
    ledger_path = runtime / EXECUTIONS_FILE
    lock_path = runtime / LOCK_FILE

    summary = {
        "ok": True,
        "scanned": 0,
        "eligible": 0,
        "executed": 0,
        "progressed": 0,
        "terminal": 0,
        "no_progress": 0,
        "failed": 0,
        "launched": 0,
        "in_flight": 0,
        "reconciled": 0,
        "recovered": 0,
        "deferred_chatgpt": 0,
        "deferred_browser_probe": 0,
        "generated_at": utc_now(),
    }

    with lock_path.open("a+", encoding="utf-8") as lock:
        try:
            fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            summary["locked"] = True
            return summary

        reconciliation = reconcile_executions(runtime, project)
        for key in ("in_flight", "reconciled", "recovered", "progressed", "terminal", "no_progress", "failed"):
            summary[key] += int(reconciliation.get(key) or 0)

        ledger_rows = _read_jsonl(ledger_path)
        latest_by_fingerprint: dict[str, dict[str, Any]] = {}
        for row in ledger_rows:
            fingerprint = str(row.get("fingerprint", "")).strip()
            if fingerprint:
                latest_by_fingerprint[fingerprint] = row

        for request in read_requests(runtime):
            summary["scanned"] += 1
            if summary["executed"] + summary["launched"] >= max(1, int(max_requests)):
                break
            if str(request.get("status", "")).strip() != "queued":
                continue

            fingerprint = str(request.get("fingerprint", "")).strip()
            task_id = str(request.get("task_id", "")).strip()
            if not fingerprint or not task_id:
                continue

            if _recent_successful_chatgpt_nudge(runtime, request):
                summary["deferred_chatgpt"] += 1
                continue

            previous = latest_by_fingerprint.get(fingerprint)
            if previous:
                previous_result = str(previous.get("result", "")).strip()
                if previous_result in {"progress", "terminal"}:
                    continue
                previous_at = _parse_utc(str(previous.get("created_at", "")))
                cooldown = max(0, int(retry_after_seconds))
                if previous_at is not None and cooldown > 0:
                    elapsed = (datetime.now(timezone.utc) - previous_at).total_seconds()
                    if elapsed < cooldown and not _failed_chatgpt_nudge_after(runtime, request, previous_at):
                        continue

            state = _load_json(_state_path(runtime, task_id))
            if not _request_matches_state(request, state):
                continue

            if _browser_only_e2e_probe(request, state):
                summary["deferred_browser_probe"] += 1
                continue

            summary["eligible"] += 1
            if executor is None:
                record = enqueue_execution(runtime, project, request, state, timeout_seconds)
                if bool(record.pop("_created", False)):
                    summary["launched"] += 1
                    summary["in_flight"] += 1
                continue

            summary["executed"] += 1
            result, exit_code, after_state, diagnostic = _execute(
                runtime_dir=runtime,
                project_dir=project,
                request=request,
                state=state,
                executor=executor,
                timeout_seconds=timeout_seconds,
            )

            row = {
                "request_id": str(request.get("id", "")).strip(),
                "task_id": task_id,
                "repository": str(state.get("repository", DEFAULT_REPOSITORY)).strip() or DEFAULT_REPOSITORY,
                "fingerprint": fingerprint,
                "result": result,
                "executor_exit_code": exit_code,
                "checkpoint_before": str(state.get("updated_at", "")).strip(),
                "checkpoint_after": str(after_state.get("updated_at", "")).strip() if after_state else "",
                "diagnostic": diagnostic or {},
                "created_at": utc_now(),
            }
            _append_jsonl(ledger_path, row)
            latest_by_fingerprint[fingerprint] = row

            if result == "terminal":
                summary["terminal"] += 1
            elif result == "progress":
                summary["progressed"] += 1
            elif result == "no_progress":
                summary["no_progress"] += 1
            else:
                summary["failed"] += 1

    return summary


def main() -> int:
    parser = argparse.ArgumentParser(description="Run one bounded detached task-continuation attempt.")
    parser.add_argument("--runtime-dir", default="")
    parser.add_argument("--project-dir", default="")
    parser.add_argument(
        "--timeout-seconds",
        type=int,
        default=int(os.getenv("SHOPVIVALIZ_RESUME_TIMEOUT_SECONDS", str(DEFAULT_TIMEOUT_SECONDS))),
    )
    parser.add_argument(
        "--max-requests",
        type=int,
        default=int(os.getenv("SHOPVIVALIZ_RESUME_MAX_REQUESTS", str(DEFAULT_MAX_REQUESTS))),
    )
    parser.add_argument(
        "--retry-after-seconds",
        type=int,
        default=int(
            os.getenv(
                "SHOPVIVALIZ_RESUME_RETRY_AFTER_SECONDS",
                str(DEFAULT_RETRY_AFTER_SECONDS),
            )
        ),
    )
    args = parser.parse_args()

    result = run_once(
        runtime_dir=Path(args.runtime_dir).expanduser() if args.runtime_dir else None,
        project_dir=Path(args.project_dir).expanduser() if args.project_dir else None,
        timeout_seconds=args.timeout_seconds,
        max_requests=args.max_requests,
        retry_after_seconds=args.retry_after_seconds,
    )
    print(json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True))
    if result["executed"] and not (result["progressed"] or result["terminal"]):
        return 75
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
