#!/usr/bin/env python3
from __future__ import annotations

import json
import os
import shutil
import subprocess
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
LOG_DIR = ROOT / "logs"
REPORT_PATH = LOG_DIR / "autonomous-cycle-report.json"
QUEUE_PATH = ROOT / "tasks-queue.json"
GUARDIAN_LOG = LOG_DIR / "autonomous-hourly-guardian.json"
EVENTS_LOG = LOG_DIR / "autonomous-hourly-guardian.jsonl"
STALE_MINUTES = 70


def utc_now() -> datetime:
    return datetime.now(timezone.utc)


def read_json(path: Path, default: Any) -> Any:
    if not path.exists():
        return default
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return default
    return data if isinstance(data, type(default)) else default


def run(cmd: list[str]) -> subprocess.CompletedProcess[str]:
    return subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True, check=False)


def parse_dt(raw: str | None) -> datetime | None:
    if not raw:
        return None
    try:
        return datetime.fromisoformat(raw.replace("Z", "+00:00"))
    except ValueError:
        return None


@dataclass
class GuardianDecision:
    idle: bool
    stale: bool
    no_pending: bool
    reason: str


def inspect_state() -> tuple[dict[str, Any], dict[str, Any], GuardianDecision]:
    report = read_json(REPORT_PATH, {})
    queue = read_json(QUEUE_PATH, {})
    generated_at = parse_dt(report.get("generated_at"))
    backlog = report.get("backlog", {}) if isinstance(report.get("backlog", {}), dict) else {}
    pending = int(backlog.get("pending", 0) or 0)
    in_progress = int(backlog.get("in_progress", 0) or 0)
    result = report.get("result", {}) if isinstance(report.get("result", {}), dict) else {}
    summary = str(result.get("summary", "")).lower()
    status = str(result.get("status", "")).lower()
    stale = generated_at is None or generated_at < utc_now() - timedelta(minutes=STALE_MINUTES)
    idle = (
        "nenhuma tarefa segura elegível" in summary
        or "nenhuma tarefa segura elegivel" in summary
        or status == "idle"
    )
    no_pending = pending <= 1 and in_progress == 0
    reason_parts = []
    if stale:
        reason_parts.append("stale_cycle")
    if idle:
        reason_parts.append("idle_result")
    if no_pending:
        reason_parts.append("thin_backlog")
    decision = GuardianDecision(
        idle=idle,
        stale=stale,
        no_pending=no_pending,
        reason=",".join(reason_parts) or "healthy",
    )
    return report, queue, decision


def append_event(payload: dict[str, Any]) -> None:
    LOG_DIR.mkdir(parents=True, exist_ok=True)
    with EVENTS_LOG.open("a", encoding="utf-8") as fh:
        fh.write(json.dumps(payload, ensure_ascii=False) + "\n")


def main() -> int:
    report, queue, decision = inspect_state()
    actions: list[dict[str, Any]] = []
    post_recovery_decision = decision

    if decision.stale or decision.idle or decision.no_pending:
        gen = run(["python3", "scripts/auto-task-generator.py"])
        actions.append(
            {
                "step": "auto_task_generator",
                "exit_code": gen.returncode,
                "stdout_tail": gen.stdout.strip().splitlines()[-5:],
                "stderr_tail": gen.stderr.strip().splitlines()[-5:],
            }
        )

        cycle = run(["python3", "scripts/autonomous-continuous-cycle.py", "--advance"])
        actions.append(
            {
                "step": "autonomous_continuous_cycle",
                "exit_code": cycle.returncode,
                "stdout_tail": cycle.stdout.strip().splitlines()[-8:],
                "stderr_tail": cycle.stderr.strip().splitlines()[-8:],
            }
        )

        executor = run(["python3", "scripts/autonomous-executor.py", "--max-cycles", "1"])
        actions.append(
            {
                "step": "autonomous_executor",
                "exit_code": executor.returncode,
                "stdout_tail": executor.stdout.strip().splitlines()[-8:],
                "stderr_tail": executor.stderr.strip().splitlines()[-8:],
            }
        )

        report, queue, post_recovery_decision = inspect_state()
        restart_needed = (
            decision.stale
            or post_recovery_decision.idle
            or all(step.get("exit_code", 1) != 0 for step in actions)
        )
        if restart_needed and shutil.which("sudo") and os.name != "nt":
            restart = run(["sudo", "systemctl", "restart", "shopvivaliz-agent.service"])
            actions.append(
                {
                    "step": "restart_agent_service",
                    "exit_code": restart.returncode,
                    "stdout_tail": restart.stdout.strip().splitlines()[-5:],
                    "stderr_tail": restart.stderr.strip().splitlines()[-5:],
                }
            )
            report, queue, post_recovery_decision = inspect_state()

    payload = {
        "generated_at": utc_now().isoformat().replace("+00:00", "Z"),
        "decision": {
            "idle": decision.idle,
            "stale": decision.stale,
            "no_pending": decision.no_pending,
            "reason": decision.reason,
        },
        "post_recovery": {
            "idle": post_recovery_decision.idle,
            "stale": post_recovery_decision.stale,
            "no_pending": post_recovery_decision.no_pending,
            "reason": post_recovery_decision.reason,
        },
        "report_generated_at": report.get("generated_at"),
        "queue_size": len(queue.get("queue", [])) if isinstance(queue, dict) else 0,
        "actions": actions,
    }
    LOG_DIR.mkdir(parents=True, exist_ok=True)
    GUARDIAN_LOG.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    append_event(payload)
    print(json.dumps(payload, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
