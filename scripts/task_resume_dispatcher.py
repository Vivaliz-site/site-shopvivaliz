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
    from .task_continuation_watchdog import read_requests
except ImportError:
    from agent_task_state import DEFAULT_REPOSITORY, RUNTIME_DIR
    from task_continuation_watchdog import read_requests

ROOT = Path(__file__).resolve().parents[1]
EXECUTIONS_FILE = "_resume-executions.jsonl"
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


def _request_matches_state(request: dict[str, Any], state: dict[str, Any]) -> bool:
    if str(state.get("status", "")).strip() != "RUNNING":
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
        after_state = _load_json(_state_path(runtime_dir, task_id))
        after = _state_signature(after_state) if after_state else ""
        if after and after != before:
            if str(after_state.get("status", "")).strip() in TERMINAL_STATES:
                result = "terminal"
            else:
                result = "progress"
        else:
            result = "no_progress"
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
        "deferred_chatgpt": 0,
        "generated_at": utc_now(),
    }

    with lock_path.open("a+", encoding="utf-8") as lock:
        try:
            fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            summary["locked"] = True
            return summary

        ledger_rows = _read_jsonl(ledger_path)
        latest_by_fingerprint: dict[str, dict[str, Any]] = {}
        for row in ledger_rows:
            fingerprint = str(row.get("fingerprint", "")).strip()
            if fingerprint:
                latest_by_fingerprint[fingerprint] = row

        for request in read_requests(runtime):
            summary["scanned"] += 1
            if summary["executed"] >= max(1, int(max_requests)):
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
                    if elapsed < cooldown:
                        continue

            state = _load_json(_state_path(runtime, task_id))
            if not _request_matches_state(request, state):
                continue

            summary["eligible"] += 1
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
