#!/usr/bin/env python3
"""Production-only end-to-end probe for detached task-continuity recovery.

DETACHED_TASK_RECOVERY_E2E_V7

This probe creates exactly one synthetic RUNNING checkpoint and then only
reads durable task state and ledger files on disk while the already-running
autonomous daemon performs stale detection, request queuing, one detached
recovery attempt, and Gemini background execution entirely on its own.

Hard boundary: this probe must never import or call the daemon's internal
stale-detection or detached-execution modules or entrypoints. Any observed
effect must come from the real, already-running production daemon cycling
on its own schedule. This file is intentionally observe-only.
"""
from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import time
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parent.parent
RUNTIME_DIR_DEFAULT = ROOT / "storage" / "agent-task-state"
TERMINAL_OK = "CONCLUIDO"
EXPECTED_VERIFICATION = "continuity_e2e_pass"
DEFAULT_TIMEOUT_SECONDS = 900
DEFAULT_POLL_INTERVAL_SECONDS = 15
DEFAULT_REPOSITORY = "Vivaliz-site/site-shopvivaliz"


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def _read_json(path: Path) -> dict[str, Any]:
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


def _child_env(runtime_dir: Path) -> dict[str, str]:
    env = os.environ.copy()
    env["SHOPVIVALIZ_AGENT_TASK_STATE_DIR"] = str(runtime_dir)
    return env


def build_sentinel_next_action(task_id: str) -> str:
    """Instruct the eventual executor using only allowlisted, mechanical steps.

    The instruction deliberately avoids requiring any generic file-write tool
    call: it only asks for two already-approved `agent_task_state.py`
    invocations, so the probe's own pass/fail does not depend on unrelated
    executor tool-approval policy.
    """
    return (
        "Esta tarefa e um probe de continuidade automatica; nao ha edicao de "
        "codigo real necessaria. Rode exatamente estes dois comandos, nesta "
        "ordem, e nada mais:\n"
        f'1) python3 scripts/agent_task_state.py ready --task {task_id} '
        f'--evidence "continuity e2e sentinel executed" --verification {EXPECTED_VERIFICATION}\n'
        f"2) python3 scripts/agent_task_state.py complete --task {task_id}"
    )


def create_synthetic_task(
    *,
    runtime_dir: Path,
    task_id: str,
    repository: str,
    agent_task_state_script: Path,
    conversation_id: str,
    runner: Any = subprocess.run,
) -> None:
    env = _child_env(runtime_dir)
    runner(
        [
            sys.executable,
            str(agent_task_state_script),
            "start",
            "--task",
            task_id,
            "--goal",
            "Prove the detached continuity recovery pipeline end-to-end",
            "--agent",
            "chatgpt-common",
            "--repository",
            repository,
        ],
        check=True,
        env=env,
        timeout=30,
    )
    runner(
        [
            sys.executable,
            str(agent_task_state_script),
            "bind-conversation",
            "--task",
            task_id,
            "--conversation-id",
            conversation_id,
        ],
        check=True,
        env=env,
        timeout=30,
    )
    runner(
        [
            sys.executable,
            str(agent_task_state_script),
            "progress",
            "--task",
            task_id,
            "--next-action",
            build_sentinel_next_action(task_id),
            "--evidence",
            "continuity e2e checkpoint created",
        ],
        check=True,
        env=env,
        timeout=30,
    )


def poll_for_terminal_evidence(
    *,
    runtime_dir: Path,
    task_id: str,
    repository: str,
    conversation_id: str,
    timeout_seconds: int,
    poll_interval_seconds: int,
    sleep: Any = time.sleep,
    now: Any = time.monotonic,
) -> dict[str, Any]:
    deadline = now() + max(1, int(timeout_seconds))
    state_path = runtime_dir / f"{task_id}.json"
    requests_path = runtime_dir / "_resume-requests.jsonl"
    ledger_path = runtime_dir / "_resume-executions.jsonl"
    nudges_path = runtime_dir / "_chatgpt-continuity-nudges.jsonl"

    observed_request = False
    matching_request: dict[str, Any] | None = None
    matching_nudge: dict[str, Any] | None = None
    matching_execution: dict[str, Any] | None = None
    state: dict[str, Any] = {}

    while True:
        state = _read_json(state_path)
        for row in _read_jsonl(requests_path):
            if str(row.get("task_id", "")) == task_id and str(row.get("repository", DEFAULT_REPOSITORY)) == repository:
                observed_request = True
                matching_request = row

        request_fingerprint = str((matching_request or {}).get("fingerprint", ""))
        for row in _read_jsonl(nudges_path):
            if (
                str(row.get("task_id", "")) == task_id
                and str(row.get("repository", DEFAULT_REPOSITORY)) == repository
                and request_fingerprint
                and str(row.get("fingerprint", "")) == request_fingerprint
            ):
                matching_nudge = row

        for row in _read_jsonl(ledger_path):
            if (
                str(row.get("task_id", "")) == task_id
                and str(row.get("repository", DEFAULT_REPOSITORY)) == repository
                and (not request_fingerprint or str(row.get("fingerprint", "")) == request_fingerprint)
            ):
                matching_execution = row

        done = (
            observed_request
            and matching_request is not None
            and matching_nudge is not None
            and str(matching_nudge.get("worker_status", "")).upper() == "PROGRESS_CONFIRMED"
            and str(matching_nudge.get("conversation_id", "")) == conversation_id
            and str(state.get("conversation_id", "")) == conversation_id
            and str(state.get("status", "")) == TERMINAL_OK
            and str(state.get("verification", "")) == EXPECTED_VERIFICATION
        )
        if done or now() >= deadline:
            break
        sleep(max(1, int(poll_interval_seconds)))

    return {
        "task_id": task_id,
        "repository": repository,
        "observed_request": observed_request,
        "request": matching_request,
        "chatgpt_nudge": matching_nudge,
        "execution": matching_execution,
        "conversation_id": conversation_id,
        "final_state": state,
    }


def write_report(report: dict[str, Any], report_path: str = "") -> str:
    text = json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True)
    if report_path:
        destination = Path(report_path).expanduser()
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_text(text + "\n", encoding="utf-8")
    return text


def evaluate(observation: dict[str, Any]) -> tuple[bool, list[str]]:
    reasons: list[str] = []
    state = observation.get("final_state") or {}
    request = observation.get("request") or {}
    nudge = observation.get("chatgpt_nudge") or {}
    repository = str(observation.get("repository", DEFAULT_REPOSITORY))
    conversation_id = str(observation.get("conversation_id", "")).strip()

    if not observation.get("observed_request") or not request:
        reasons.append("no resume request observed for this task_id")

    if not conversation_id:
        reasons.append("explicit conversation_id is required")
    if str(state.get("conversation_id", "")).strip() != conversation_id:
        reasons.append("checkpoint conversation_id does not match explicit binding")

    request_fingerprint = str(request.get("fingerprint", "")).strip()
    nudge_fingerprint = str(nudge.get("fingerprint", "")).strip()
    if not nudge:
        reasons.append("no ChatGPT nudge evidence observed for this task_id")
    else:
        if str(nudge.get("worker_status", "")).strip().upper() != "PROGRESS_CONFIRMED":
            reasons.append("ChatGPT worker status is not PROGRESS_CONFIRMED")
        if str(nudge.get("conversation_id", "")).strip() != conversation_id:
            reasons.append("ChatGPT nudge conversation_id does not match explicit binding")
        if not request_fingerprint or nudge_fingerprint != request_fingerprint:
            reasons.append("ChatGPT nudge fingerprint does not match current resume request")

    if str(state.get("repository", DEFAULT_REPOSITORY)) != repository:
        reasons.append("checkpoint repository does not match requested repository")
    if str(state.get("status", "")) != TERMINAL_OK:
        reasons.append(f"checkpoint status is not {TERMINAL_OK}: {state.get('status')!r}")
    if str(state.get("verification", "")) != EXPECTED_VERIFICATION:
        reasons.append(
            f"checkpoint verification is not {EXPECTED_VERIFICATION}: {state.get('verification')!r}"
        )

    return (len(reasons) == 0), reasons

def main() -> int:
    parser = argparse.ArgumentParser(
        description="Detached continuity production E2E probe (creates one task, then only observes)."
    )
    parser.add_argument("--runtime-dir", default=str(RUNTIME_DIR_DEFAULT))
    parser.add_argument("--timeout-seconds", type=int, default=DEFAULT_TIMEOUT_SECONDS)
    parser.add_argument("--poll-interval-seconds", type=int, default=DEFAULT_POLL_INTERVAL_SECONDS)
    parser.add_argument("--task-id", default="")
    parser.add_argument("--repository", default=DEFAULT_REPOSITORY)
    parser.add_argument("--conversation-id", required=True)
    parser.add_argument(
        "--report-path",
        default="",
        help="Optional path for the final single JSON report, separate from stdout.",
    )
    args = parser.parse_args()

    runtime_dir = Path(args.runtime_dir).expanduser()
    runtime_dir.mkdir(parents=True, exist_ok=True)
    task_id = args.task_id.strip() or f"continuity-e2e-{uuid.uuid4()}"
    repository = args.repository.strip() or DEFAULT_REPOSITORY
    agent_task_state_script = ROOT / "scripts" / "agent_task_state.py"

    create_synthetic_task(
        runtime_dir=runtime_dir,
        task_id=task_id,
        repository=repository,
        agent_task_state_script=agent_task_state_script,
        conversation_id=args.conversation_id.strip(),
    )

    observation = poll_for_terminal_evidence(
        runtime_dir=runtime_dir,
        task_id=task_id,
        repository=repository,
        conversation_id=args.conversation_id.strip(),
        timeout_seconds=args.timeout_seconds,
        poll_interval_seconds=args.poll_interval_seconds,
    )
    ok, reasons = evaluate(observation)

    report = {
        "task_id": task_id,
        "repository": repository,
        "pass": ok,
        "reasons": reasons,
        "observed_request": observation.get("observed_request"),
        "request_fingerprint": ((observation.get("request") or {}).get("fingerprint")),
        "chatgpt_nudge": observation.get("chatgpt_nudge"),
        "execution": observation.get("execution"),
        "conversation_id": observation.get("conversation_id"),
        "final_status": (observation.get("final_state") or {}).get("status"),
        "final_verification": (observation.get("final_state") or {}).get("verification"),
        "generated_at": utc_now(),
    }
    print(write_report(report, args.report_path))
    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
