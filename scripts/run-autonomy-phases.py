#!/usr/bin/env python3
"""Assess and report phased autonomous execution readiness."""
from __future__ import annotations

import json
import os
import subprocess
import sys
from collections import OrderedDict
from pathlib import Path

from task_queue_lib import load_queue

PHASE_ORDER = OrderedDict(
    [
        ("phase-1-foundation", "Fase 1 - Fundacao autonoma"),
        ("phase-2-revenue", "Fase 2 - Receita, SEO e paginas"),
        ("phase-3-marketplaces", "Fase 3 - Marketplaces com secrets"),
        ("phase-4-approval-gated", "Fase 4 - Itens com aprovacao ou acesso manual"),
    ]
)

MISSION_IDS = {
    "task-041",
    "task-042",
    "task-043",
    "task-044",
    "task-045",
    "task-046",
    "task-047",
    "task-048",
}

REPORTABLE_TAGS = {
    "sales_flow",
    "conversion",
    "seo",
    "product-pages",
    "roi",
    "revenue",
    "checkout",
    "marketplace",
    "shopee",
    "mercado livre",
    "mercadolivre",
    "catalog",
}

AUTONOMOUS_SOURCES = {
    "autonomous-site-analysis",
    "autonomous-seo-analysis",
    "autonomous-integration-analysis",
    "real-project-signals",
    "auto-task-generator-gemini",
    "auto-task-generator-claude",
}


def current_branch() -> str:
    result = subprocess.run(
        ["git", "rev-parse", "--abbrev-ref", "HEAD"],
        capture_output=True,
        text=True,
        check=True,
    )
    return result.stdout.strip()


def github_secret_names() -> set[str]:
    result = subprocess.run(
        ["gh", "secret", "list"],
        capture_output=True,
        text=True,
        check=True,
    )
    names: set[str] = set()
    for line in result.stdout.splitlines():
        parts = line.split()
        if parts:
            names.add(parts[0].strip())
    return names


def latest_workflow_status(workflow_file: str, branch: str) -> dict[str, str]:
    result = subprocess.run(
        [
            "gh",
            "run",
            "list",
            "--workflow",
            workflow_file,
            "--branch",
            branch,
            "--limit",
            "1",
            "--json",
            "databaseId,status,conclusion,url,createdAt,displayTitle",
        ],
        capture_output=True,
        text=True,
        check=True,
    )
    runs = json.loads(result.stdout)
    if not runs:
        return {"status": "not_found", "conclusion": "unknown"}
    run = runs[0]
    return {
        "status": str(run.get("status", "unknown")),
        "conclusion": str(run.get("conclusion", "unknown")),
        "url": str(run.get("url", "")),
        "created_at": str(run.get("createdAt", "")),
        "title": str(run.get("displayTitle", "")),
    }


def classify_task(task: dict, repo_secrets: set[str]) -> tuple[str, list[str], list[str]]:
    missing_local = [env for env in task.get("requires_env", []) if not os.getenv(env)]
    missing_repo = [env for env in task.get("requires_env", []) if env not in repo_secrets]

    if task.get("requires_manual_access"):
        return "blocked_manual_access", missing_local, missing_repo

    if task.get("requires_human_approval"):
        return "blocked_human_approval_required", missing_local, missing_repo

    if not missing_local:
        return "ready_local", [], []

    if missing_local and not missing_repo:
        return "ready_ci_with_repo_secrets", missing_local, []

    return "blocked_missing_secret", missing_local, missing_repo


def should_include_task(task: dict) -> bool:
    task_id = str(task.get("id", "")).strip()
    phase = str(task.get("phase", "")).strip()
    tags = {str(tag).strip().lower() for tag in task.get("tags", [])}

    if task_id in MISSION_IDS:
        return True
    if phase.startswith("phase-"):
        return True
    if tags & REPORTABLE_TAGS:
        return True
    if task.get("auto_generated"):
        return True
    if str(task.get("source", "")).strip().lower() in AUTONOMOUS_SOURCES:
        return True
    return False


def build_report() -> dict:
    queue = load_queue()
    repo_secrets = github_secret_names()
    branch = current_branch()

    phase_rows: dict[str, list[dict]] = {phase: [] for phase in PHASE_ORDER}
    for task in queue.get("queue", []):
        if not should_include_task(task):
            continue
        phase = str(task.get("phase", "phase-4-approval-gated"))
        readiness, missing_local, missing_repo = classify_task(task, repo_secrets)
        phase_rows.setdefault(phase, []).append(
            {
                "id": task.get("id"),
                "title": task.get("title"),
                "status": task.get("status"),
                "readiness": readiness,
                "missing_runtime_env": missing_local,
                "missing_repo_secrets": missing_repo,
                "requires_env": task.get("requires_env", []),
                "requires_manual_access": bool(task.get("requires_manual_access")),
                "requires_human_approval": bool(task.get("requires_human_approval")),
            }
        )

    return {
        "branch": branch,
        "repo_secrets_detected": sorted(
            [
                name
                for name in repo_secrets
                if name.startswith(("SHOPEE_", "ML_", "GOOGLE_ADS_", "OPENAI_", "GEMINI_", "ANTHROPIC_"))
            ]
        ),
        "qa_workflow": latest_workflow_status("shopvivaliz-qa.yml", branch),
        "phases": [
            {
                "key": phase_key,
                "label": phase_label,
                "tasks": phase_rows.get(phase_key, []),
            }
            for phase_key, phase_label in PHASE_ORDER.items()
        ],
    }


def write_reports(report: dict) -> tuple[Path, Path]:
    logs_dir = Path("logs")
    logs_dir.mkdir(parents=True, exist_ok=True)

    json_path = logs_dir / "autonomy-phase-report.json"
    md_path = logs_dir / "autonomy-phase-report.md"

    json_path.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

    lines = [
        "# Autonomous Phase Report",
        "",
        f"- Branch: `{report['branch']}`",
        f"- QA workflow: `{report['qa_workflow'].get('status')}` / `{report['qa_workflow'].get('conclusion')}`",
    ]
    if report["qa_workflow"].get("url"):
        lines.append(f"- QA run: {report['qa_workflow']['url']}")
    lines.append("")
    lines.append("## Repo secrets detectados")
    for name in report["repo_secrets_detected"]:
        lines.append(f"- `{name}`")
    lines.append("")

    for phase in report["phases"]:
        lines.append(f"## {phase['label']}")
        if not phase["tasks"]:
            lines.append("- Nenhuma tarefa nesta fase.")
            lines.append("")
            continue
        for task in phase["tasks"]:
            line = f"- `{task['id']}` {task['title']} -> status `{task['status']}` / readiness `{task['readiness']}`"
            if task["missing_runtime_env"]:
                line += f" (runtime local ausente: {', '.join(task['missing_runtime_env'])})"
            if task["missing_repo_secrets"]:
                line += f" (repo secrets ausentes: {', '.join(task['missing_repo_secrets'])})"
            lines.append(line)
        lines.append("")

    md_path.write_text("\n".join(lines) + "\n", encoding="utf-8")
    return json_path, md_path


def print_summary(report: dict, json_path: Path, md_path: Path) -> int:
    print("Relatorio de fases autonomas")
    print(f"Branch: {report['branch']}")
    print(
        "QA workflow: "
        f"{report['qa_workflow'].get('status')} / {report['qa_workflow'].get('conclusion')}"
    )
    if report["qa_workflow"].get("url"):
        print(f"QA run: {report['qa_workflow']['url']}")
    print()

    for phase in report["phases"]:
        print(phase["label"])
        for task in phase["tasks"]:
            suffix = ""
            if task["missing_runtime_env"]:
                suffix = " | runtime local ausente: " + ", ".join(task["missing_runtime_env"])
            if task["missing_repo_secrets"]:
                suffix += " | repo secrets ausentes: " + ", ".join(task["missing_repo_secrets"])
            print(f"  - {task['id']} -> status {task['status']} / {task['readiness']}{suffix}")
        print()

    print(f"JSON salvo em: {json_path}")
    print(f"Markdown salvo em: {md_path}")
    return 0


def main() -> int:
    report = build_report()
    json_path, md_path = write_reports(report)
    return print_summary(report, json_path, md_path)


if __name__ == "__main__":
    raise SystemExit(main())
