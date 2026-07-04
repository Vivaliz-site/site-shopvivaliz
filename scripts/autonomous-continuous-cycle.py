#!/usr/bin/env python3
"""Run a safe autonomous cycle without waiting for manual follow-up."""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from collections import OrderedDict
from pathlib import Path
from typing import Any

from task_queue_lib import executable_pending_tasks, load_queue, save_queue, utc_now

PHASE_ORDER = OrderedDict(
    [
        ("phase-1-foundation", 0),
        ("phase-2-revenue", 1),
        ("phase-3-marketplaces", 2),
        ("phase-4-approval-gated", 3),
    ]
)

DIRECTOR_PRIORITY_FALLBACK = ["conversion_impact", "seo_gap", "catalog_readiness"]
CURRENT_TASK_SELECTOR = "autonomous-continuous-cycle"
LOGS_DIR = Path("logs")
PHASE_REPORT_JSON = LOGS_DIR / "autonomy-phase-report.json"
HEALTH_REPORT_JSON = LOGS_DIR / "system-health-check.json"
CYCLE_REPORT_JSON = LOGS_DIR / "autonomous-cycle-report.json"
CYCLE_REPORT_MD = LOGS_DIR / "autonomous-cycle-report.md"

FORBIDDEN_KEYWORDS = (
    "preco",
    "price",
    "discount",
    "desconto",
    "campaign",
    "campanha",
    "ads publish",
    "publish campaign",
    "deploy production",
)

SAFE_CONSTRAINT_PATTERNS = (
    r"sem alterar[^.;]*",
    r"sem modificar[^.;]*",
    r"sem impact[oa][^.;]*",
    r"mantendo o guardi[aã]o de pre[cç]o[^.;]*",
)


def read_json(path: Path) -> dict[str, Any]:
    if not path.exists():
        return {}
    return json.loads(path.read_text(encoding="utf-8"))


def run_command(command: list[str], *, check: bool = False) -> subprocess.CompletedProcess[str]:
    return subprocess.run(command, capture_output=True, text=True, check=check)


def load_director_priorities() -> list[str]:
    config = read_json(Path("config/ai-orchestrator.json"))
    priorities = (
        config.get("growth_policy", {})
        .get("revenue_engine", {})
        .get("prioritize_by", DIRECTOR_PRIORITY_FALLBACK)
    )
    return [str(item) for item in priorities] or DIRECTOR_PRIORITY_FALLBACK


def update_phase_report() -> dict[str, Any]:
    run_command([sys.executable, "scripts/run-autonomy-phases.py"])
    return read_json(PHASE_REPORT_JSON)


def run_auto_audit() -> dict[str, Any]:
    result = run_command([sys.executable, "scripts/system-health-check.py"])
    report = read_json(HEALTH_REPORT_JSON)
    return {
        "exit_code": result.returncode,
        "status": report.get("status", "UNKNOWN"),
        "errors": report.get("errors", []),
        "warnings": report.get("warnings", []),
    }


def phase_readiness_map(phase_report: dict[str, Any]) -> dict[str, dict[str, Any]]:
    readiness: dict[str, dict[str, Any]] = {}
    for phase in phase_report.get("phases", []):
        for task in phase.get("tasks", []):
            task_id = str(task.get("id", "")).strip()
            if task_id:
                readiness[task_id] = task
    return readiness


def forbidden_by_governance(task: dict[str, Any]) -> bool:
    haystack = " ".join(
        [
            str(task.get("title", "")),
            str(task.get("description", "")),
            " ".join(str(tag) for tag in task.get("tags", [])),
        ]
    ).lower()
    for pattern in SAFE_CONSTRAINT_PATTERNS:
        haystack = re.sub(pattern, " ", haystack, flags=re.IGNORECASE)
    return any(keyword in haystack for keyword in FORBIDDEN_KEYWORDS)


def director_dimension(task: dict[str, Any], director_priorities: list[str]) -> int:
    tags = {str(tag).lower() for tag in task.get("tags", [])}
    title = str(task.get("title", "")).lower()
    description = str(task.get("description", "")).lower()
    text = " ".join([title, description, " ".join(tags)])

    dimension = "catalog_readiness"
    if {"cro", "conversion", "ux"} & tags or "convers" in text or "checkout" in text:
        dimension = "conversion_impact"
    elif "seo" in tags or "seo" in text:
        dimension = "seo_gap"
    elif {"catalog", "product-pages", "dynamic-content"} & tags or "catalog" in text or "produto" in text:
        dimension = "catalog_readiness"

    if dimension in director_priorities:
        return director_priorities.index(dimension)
    return len(director_priorities)


def current_autonomous_task(queue_data: dict[str, Any]) -> dict[str, Any] | None:
    for task in queue_data.get("queue", []):
        if (
            task.get("status") == "in_progress"
            and task.get("selected_by") == CURRENT_TASK_SELECTOR
        ):
            return task
    return None


def eligible_pending_tasks(
    queue_data: dict[str, Any],
    readiness_map: dict[str, dict[str, Any]],
    director_priorities: list[str],
) -> list[dict[str, Any]]:
    candidates: list[dict[str, Any]] = []
    for task in executable_pending_tasks(queue_data):
        task_id = str(task.get("id", ""))
        readiness = readiness_map.get(task_id, {})
        readiness_state = str(readiness.get("readiness", "unknown"))

        if task.get("requires_human_approval") or task.get("requires_manual_access"):
            continue
        if forbidden_by_governance(task):
            continue
        if readiness_state not in {"ready_local", "ready_ci_with_repo_secrets"}:
            continue

        enriched = dict(task)
        enriched["_readiness"] = readiness_state
        enriched["_director_rank"] = director_dimension(task, director_priorities)
        enriched["_phase_rank"] = PHASE_ORDER.get(str(task.get("phase", "")), 99)
        candidates.append(enriched)

    candidates.sort(
        key=lambda task: (
            int(task.get("_phase_rank", 99)),
            int(task.get("_director_rank", 99)),
            int(task.get("queue_rank", 9999)),
            str(task.get("created_at", "")),
            str(task.get("id", "")),
        )
    )
    return candidates


def selection_reason(task: dict[str, Any]) -> str:
    bits = [
        f"phase={task.get('phase', 'unphased')}",
        f"readiness={task.get('_readiness', 'unknown')}",
        f"queue_rank={task.get('queue_rank', 'na')}",
        f"director_rank={task.get('_director_rank', 'na')}",
    ]
    return "; ".join(bits)


def select_task(
    queue_data: dict[str, Any],
    readiness_map: dict[str, dict[str, Any]],
    director_priorities: list[str],
    *,
    advance: bool,
) -> dict[str, Any]:
    current = current_autonomous_task(queue_data)
    if current:
        return {
            "mode": "resume",
            "task": current,
            "reason": current.get("selection_reason", "resume_existing_in_progress"),
        }

    candidates = eligible_pending_tasks(queue_data, readiness_map, director_priorities)
    if not candidates:
        return {"mode": "idle", "task": None, "reason": "no_safe_eligible_task"}

    selected = candidates[0]
    if advance:
        for task in queue_data.get("queue", []):
            if task.get("id") != selected.get("id"):
                continue
            task["status"] = "in_progress"
            task["selected_at"] = utc_now()
            task["selected_by"] = CURRENT_TASK_SELECTOR
            task["selection_reason"] = selection_reason(selected)
            break
        save_queue(queue_data)

    return {"mode": "selected", "task": selected, "reason": selection_reason(selected)}


def build_report(advance: bool) -> dict[str, Any]:
    director_priorities = load_director_priorities()
    phase_report = update_phase_report()
    audit = run_auto_audit()
    queue_data = load_queue()
    readiness_map = phase_readiness_map(phase_report)
    selection = select_task(
        queue_data,
        readiness_map,
        director_priorities,
        advance=advance,
    )

    backlog_snapshot = {
        "pending": len([task for task in queue_data.get("queue", []) if task.get("status") == "pending"]),
        "in_progress": len([task for task in queue_data.get("queue", []) if task.get("status") == "in_progress"]),
        "completed": len([task for task in queue_data.get("queue", []) if task.get("status") == "completed"]),
    }

    report = {
        "generated_at": utc_now(),
        "advance": advance,
        "director_priorities": director_priorities,
        "auto_audit": audit,
        "backlog_snapshot": backlog_snapshot,
        "phase_summary": {
            "qa_workflow": phase_report.get("qa_workflow", {}),
            "phases": phase_report.get("phases", []),
        },
        "selection": {
            "mode": selection["mode"],
            "reason": selection["reason"],
            "task": selection["task"],
        },
    }
    return report


def write_report(report: dict[str, Any]) -> tuple[Path, Path]:
    LOGS_DIR.mkdir(parents=True, exist_ok=True)
    CYCLE_REPORT_JSON.write_text(
        json.dumps(report, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )

    lines = [
        "# Autonomous Continuous Cycle",
        "",
        f"- Generated at: `{report['generated_at']}`",
        f"- Advance mode: `{report['advance']}`",
        f"- Auto audit: `{report['auto_audit']['status']}` (exit `{report['auto_audit']['exit_code']}`)",
        f"- Director priorities: `{', '.join(report['director_priorities'])}`",
        "",
        "## Backlog Snapshot",
        f"- Pending: `{report['backlog_snapshot']['pending']}`",
        f"- In progress: `{report['backlog_snapshot']['in_progress']}`",
        f"- Completed: `{report['backlog_snapshot']['completed']}`",
        "",
        "## Selection",
        f"- Mode: `{report['selection']['mode']}`",
        f"- Reason: `{report['selection']['reason']}`",
    ]

    task = report["selection"].get("task")
    if task:
        lines.extend(
            [
                f"- Task: `{task.get('id')}` {task.get('title')}",
                f"- Status: `{task.get('status')}`",
                f"- Phase: `{task.get('phase', 'unphased')}`",
            ]
        )

    if report["auto_audit"]["errors"]:
        lines.extend(["", "## Auto Audit Errors"])
        for error in report["auto_audit"]["errors"]:
            lines.append(f"- {error}")

    CYCLE_REPORT_MD.write_text("\n".join(lines) + "\n", encoding="utf-8")
    return CYCLE_REPORT_JSON, CYCLE_REPORT_MD


def print_report(report: dict[str, Any], json_path: Path, md_path: Path) -> int:
    print("Autonomous continuous cycle")
    print(f"Generated at: {report['generated_at']}")
    print(
        f"Auto audit: {report['auto_audit']['status']} "
        f"(exit {report['auto_audit']['exit_code']})"
    )
    print(f"Director priorities: {', '.join(report['director_priorities'])}")
    print(
        "Backlog snapshot: "
        f"pending={report['backlog_snapshot']['pending']} "
        f"in_progress={report['backlog_snapshot']['in_progress']} "
        f"completed={report['backlog_snapshot']['completed']}"
    )
    print(
        f"Selection: mode={report['selection']['mode']} "
        f"reason={report['selection']['reason']}"
    )

    task = report["selection"].get("task")
    if task:
        print(f"Task: {task.get('id')} - {task.get('title')}")
        print(f"Status: {task.get('status')} | Phase: {task.get('phase', 'unphased')}")

    print(f"JSON saved at: {json_path}")
    print(f"Markdown saved at: {md_path}")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description="Run the ShopVivaliz autonomous continuous cycle.")
    parser.add_argument(
        "--advance",
        action="store_true",
        help="Persist the selected safe task as in_progress.",
    )
    args = parser.parse_args()

    report = build_report(advance=args.advance)
    json_path, md_path = write_report(report)
    return print_report(report, json_path, md_path)


if __name__ == "__main__":
    raise SystemExit(main())
