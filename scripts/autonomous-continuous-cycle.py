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

DIRECTOR_PRIORITY_FALLBACK = ["sales_flow", "conversion_impact", "seo_gap", "catalog_readiness"]
CURRENT_TASK_SELECTOR = "autonomous-continuous-cycle"
LOGS_DIR = Path("logs")
PHASE_REPORT_JSON = LOGS_DIR / "autonomy-phase-report.json"
HEALTH_REPORT_JSON = LOGS_DIR / "system-health-check.json"
CYCLE_REPORT_JSON = LOGS_DIR / "autonomous-cycle-report.json"
CYCLE_REPORT_MD = LOGS_DIR / "autonomous-cycle-report.md"
EVENTS_LOG_JSONL = LOGS_DIR / "autonomous-cycle-events.jsonl"
AUTONOMOUS_CYCLE_LOG = Path("scripts/autonomous-cycle-log.json")
AUTONOMOUS_READY_SOURCES = {
    "autonomous-site-analysis",
    "autonomous-seo-analysis",
    "autonomous-integration-analysis",
    "real-project-signals",
    "auto-task-generator-gemini",
    "auto-task-generator-claude",
}

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


def maybe_generate_backlog(queue_data: dict[str, Any]) -> dict[str, Any]:
    pending_tasks = [task for task in queue_data.get("queue", []) if task.get("status") == "pending"]
    if len(pending_tasks) >= 3:
        return {
            "triggered": False,
            "reason": "pending_threshold_satisfied",
            "pending_before": len(pending_tasks),
            "exit_code": 0,
        }

    result = run_command([sys.executable, "scripts/auto-task-generator.py"])
    refreshed_queue = load_queue()
    pending_after = len(
        [task for task in refreshed_queue.get("queue", []) if task.get("status") == "pending"]
    )
    return {
        "triggered": True,
        "reason": "pending_below_threshold",
        "pending_before": len(pending_tasks),
        "pending_after": pending_after,
        "exit_code": result.returncode,
        "stdout_tail": result.stdout.strip().splitlines()[-5:],
        "stderr_tail": result.stderr.strip().splitlines()[-5:],
    }


def run_auto_audit() -> dict[str, Any]:
    result = run_command([sys.executable, "scripts/system-health-check.py"])
    report = read_json(HEALTH_REPORT_JSON)
    return {
        "exit_code": result.returncode,
        "status": report.get("status", "UNKNOWN"),
        "errors": report.get("errors", []),
        "warnings": report.get("warnings", []),
    }


def git_changed_files() -> list[str]:
    result = run_command(["git", "status", "--porcelain"])
    if result.returncode != 0 or not result.stdout:
        return []

    paths: list[str] = []
    for line in result.stdout.splitlines():
        if len(line) < 4:
            continue
        path = line[3:].strip()
        if " -> " in path:
            path = path.split(" -> ", 1)[1].strip()
        if path:
            paths.append(path)

    return sorted(dict.fromkeys(paths))


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
    sales_markers = {
        "sales",
        "venda",
        "sales-flow",
        "roi",
        "revenue",
        "checkout",
        "payment",
        "pagamento",
        "marketplace",
        "shopee",
        "mercado livre",
        "mercadolivre",
        "product-pages",
        "produto",
        "seo",
        "cro",
        "conversion",
        "ux",
    }
    if sales_markers & tags or any(marker in text for marker in ["venda", "sales", "roi", "revenue", "checkout", "payment", "pagamento", "marketplace", "shopee", "mercado livre", "product-pages", "seo", "convers"]):
        dimension = "sales_flow"
    elif {"cro", "conversion", "ux"} & tags or "convers" in text or "checkout" in text:
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


def inferred_readiness(task: dict[str, Any], readiness_state: str) -> str:
    if readiness_state != "unknown":
        return readiness_state

    task_source = str(task.get("source", "")).strip().lower()
    if not task.get("auto_generated") and task_source not in AUTONOMOUS_READY_SOURCES:
        return readiness_state

    if task.get("requires_human_approval") or task.get("requires_manual_access"):
        return readiness_state

    required_env = [str(env).strip() for env in task.get("requires_env", []) if str(env).strip()]
    if required_env:
        return "ready_ci_with_repo_secrets_inferred"
    return "ready_local_inferred"


def eligible_pending_tasks(
    queue_data: dict[str, Any],
    readiness_map: dict[str, dict[str, Any]],
    director_priorities: list[str],
) -> list[dict[str, Any]]:
    candidates: list[dict[str, Any]] = []
    for task in executable_pending_tasks(queue_data):
        task_id = str(task.get("id", ""))
        readiness = readiness_map.get(task_id, {})
        readiness_state = inferred_readiness(
            task,
            str(readiness.get("readiness", "unknown")),
        )

        if task.get("requires_human_approval") or task.get("requires_manual_access"):
            continue
        if forbidden_by_governance(task):
            continue
        if readiness_state not in {
            "ready_local",
            "ready_ci_with_repo_secrets",
            "ready_local_inferred",
            "ready_ci_with_repo_secrets_inferred",
        }:
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
        selected_id = str(selected.get("id", ""))
        persisted_task: dict[str, Any] | None = None
        for task in queue_data.get("queue", []):
            if str(task.get("id", "")) != selected_id:
                continue
            task["status"] = "in_progress"
            task["selected_at"] = utc_now()
            task["selected_by"] = CURRENT_TASK_SELECTOR
            task["selection_reason"] = selection_reason(selected)
            persisted_task = task
            break
        save_queue(queue_data)
        if persisted_task is not None:
            selected = persisted_task

    return {
        "mode": "selected",
        "task": selected,
        "reason": str(selected.get("selection_reason") or selection_reason(selected)),
    }


def build_report(advance: bool) -> dict[str, Any]:
    director_priorities = load_director_priorities()
    audit = run_auto_audit()
    queue_data = load_queue()
    backlog_generation = maybe_generate_backlog(queue_data)
    if backlog_generation.get("triggered"):
        queue_data = load_queue()
    phase_report = update_phase_report()
    roi_report = read_json(Path("logs/roi-engine-report.json"))
    changed_files = git_changed_files()
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
        "changed_files": changed_files,
        "tests_executed": [
            "python scripts/auto-task-generator.py",
            "python scripts/run-autonomy-phases.py",
            "python scripts/system-health-check.py",
        ],
        "backlog_generation": backlog_generation,
        "backlog_snapshot": backlog_snapshot,
        "phase_summary": {
            "qa_workflow": phase_report.get("qa_workflow", {}),
            "phases": phase_report.get("phases", []),
        },
        "sales_focus": {
            "available": bool(roi_report),
            "generated_at": roi_report.get("generated_at"),
            "top_opportunities": roi_report.get("top_opportunities", [])[:3] if isinstance(roi_report.get("top_opportunities"), list) else [],
            "priority_modes": director_priorities,
        },
        "selection": {
            "mode": selection["mode"],
            "reason": selection["reason"],
            "task": selection["task"],
        },
    }
    task = selection.get("task")
    report["result"] = {
        "status": "idle" if selection["mode"] == "idle" else "active",
        "summary": (
            "Nenhuma tarefa segura elegível encontrada."
            if selection["mode"] == "idle"
            else f"Tarefa {task.get('id')} preparada em {task.get('status')}"
        ),
    }
    report["next_task"] = {
        "id": task.get("id") if task else None,
        "title": task.get("title") if task else None,
        "status": task.get("status") if task else None,
        "reason": selection["reason"],
        "mode": selection["mode"],
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
        f"- Result: `{report['result']['summary']}`",
        "",
        "## Backlog Generation",
        f"- Triggered: `{report['backlog_generation'].get('triggered')}`",
        f"- Reason: `{report['backlog_generation'].get('reason')}`",
        f"- Pending before: `{report['backlog_generation'].get('pending_before', 'na')}`",
        f"- Pending after: `{report['backlog_generation'].get('pending_after', report['backlog_snapshot']['pending'])}`",
        "",
        "## Backlog Snapshot",
        f"- Pending: `{report['backlog_snapshot']['pending']}`",
        f"- In progress: `{report['backlog_snapshot']['in_progress']}`",
        f"- Completed: `{report['backlog_snapshot']['completed']}`",
        "",
        "## Trace",
        f"- Changed files: `{', '.join(report['changed_files']) if report['changed_files'] else 'nenhum'}`",
        f"- Tests executed: `{', '.join(report['tests_executed'])}`",
        f"- Next task: `{report['next_task']['id'] or 'none'}`",
        f"- Next task reason: `{report['next_task']['reason']}`",
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
    event_record = {
        "generated_at": report["generated_at"],
        "advance": report["advance"],
        "changed_files": report["changed_files"],
        "tests_executed": report["tests_executed"],
        "result": report["result"],
        "backlog_generation": report["backlog_generation"],
        "next_task": report["next_task"],
        "selection": report["selection"],
        "auto_audit": report["auto_audit"],
    }
    with EVENTS_LOG_JSONL.open("a", encoding="utf-8") as f:
        f.write(json.dumps(event_record, ensure_ascii=False) + "\n")

    cycle_state = {
        "generated_at": report["generated_at"],
        "last_cycle_at": report["generated_at"],
        "status": "idle" if report["selection"]["mode"] == "idle" else "running",
        "mode": report["selection"]["mode"],
        "advance": report["advance"],
        "task": report["selection"].get("task"),
        "next_task": report["next_task"],
        "selection_reason": report["selection"]["reason"],
        "backlog_generation": report["backlog_generation"],
        "changed_files": report["changed_files"],
        "tests_executed": report["tests_executed"],
        "maintenance_window": False,
    }
    AUTONOMOUS_CYCLE_LOG.write_text(
        json.dumps(cycle_state, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )
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

    print(
        "Trace: "
        f"changed_files={len(report['changed_files'])} "
        f"tests={len(report['tests_executed'])} "
        f"next_task={report['next_task']['id'] or 'none'}"
    )

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
