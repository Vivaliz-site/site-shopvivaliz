#!/usr/bin/env python3
"""Executor autônomo seguro do ShopVivaliz.

Objetivo:
- usar tasks-queue.json como fila canonica;
- manter logs/tasks-queue.json como espelho legado;
- nunca publicar direto em main;
- abrir branch/PR para tarefas seguras;
- deixar relatorio auditavel mesmo quando nao ha chave de IA ou mudancas.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

CANONICAL_QUEUE = Path("tasks-queue.json")
LEGACY_QUEUE = Path("logs/tasks-queue.json")
REPORT_DIR = Path("logs/autonomous")
PRIORITY_ORDER = {"high": 0, "medium": 1, "low": 2}
STOP_STATUSES = {
    "completed",
    "pr_opened",
    "blocked_price_approval_required",
    "blocked_manual_approval_required",
    "blocked_external_access_required",
    "blocked_no_safe_change",
}

DEFAULT_QUEUE = {
    "version": "1.0",
    "created_at": datetime.now(timezone.utc).isoformat(),
    "queue": [],
}


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def run(cmd: list[str], *, check: bool = False, timeout: int | None = None) -> subprocess.CompletedProcess[str]:
    print("$ " + " ".join(cmd))
    return subprocess.run(cmd, check=check, capture_output=True, text=True, timeout=timeout)


def load_queue() -> dict[str, Any]:
    if not CANONICAL_QUEUE.exists() and LEGACY_QUEUE.exists():
        CANONICAL_QUEUE.write_text(LEGACY_QUEUE.read_text(encoding="utf-8"), encoding="utf-8")
    if not CANONICAL_QUEUE.exists():
        CANONICAL_QUEUE.write_text(json.dumps(DEFAULT_QUEUE, indent=2, ensure_ascii=False), encoding="utf-8")
    return json.loads(CANONICAL_QUEUE.read_text(encoding="utf-8"))


def save_queue(queue_data: dict[str, Any]) -> None:
    text = json.dumps(queue_data, indent=2, ensure_ascii=False) + "\n"
    CANONICAL_QUEUE.write_text(text, encoding="utf-8")
    LEGACY_QUEUE.parent.mkdir(parents=True, exist_ok=True)
    LEGACY_QUEUE.write_text(text, encoding="utf-8")


def write_report(report: dict[str, Any]) -> None:
    REPORT_DIR.mkdir(parents=True, exist_ok=True)
    report["updated_at"] = utc_now()
    (REPORT_DIR / "last_autonomous_executor_run.json").write_text(
        json.dumps(report, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )
    with (REPORT_DIR / "autonomous_executor_history.jsonl").open("a", encoding="utf-8") as fh:
        fh.write(json.dumps(report, ensure_ascii=False) + "\n")


def normalize_task(task: dict[str, Any]) -> dict[str, Any]:
    task.setdefault("priority", "medium")
    task.setdefault("status", "pending")
    task.setdefault("created_at", utc_now())
    return task


def select_next_task(queue_data: dict[str, Any]) -> dict[str, Any] | None:
    tasks = [normalize_task(t) for t in queue_data.get("queue", [])]
    pending = [t for t in tasks if t.get("status") == "pending"]
    if not pending:
        return None
    pending.sort(key=lambda t: (PRIORITY_ORDER.get(str(t.get("priority", "medium")), 9), str(t.get("created_at", ""))))
    return pending[0]


def slugify(value: str) -> str:
    value = re.sub(r"[^a-zA-Z0-9]+", "-", value.lower()).strip("-")
    return value[:48] or "task"


def policy_guard(task: dict[str, Any]) -> tuple[bool, str]:
    guard_script = Path("scripts/autonomous-policy-guard.py")
    if not guard_script.exists():
        return True, "policy guard ausente; prosseguindo com guardrails internos"
    result = run(
        [
            sys.executable,
            str(guard_script),
            "--title",
            str(task.get("title", "")),
            "--description",
            str(task.get("description", "")),
        ]
    )
    output = (result.stdout + "\n" + result.stderr).strip()
    return result.returncode == 0, output


def ensure_git_identity() -> None:
    run(["git", "config", "user.email", "agente-autonomo@shopvivaliz.com.br"])
    run(["git", "config", "user.name", "Agente Autonomo ShopVivaliz"])


def create_task_branch(task: dict[str, Any]) -> str:
    branch = f"agent/{task.get('id', 'task')}-{slugify(str(task.get('title', 'autonomo')))}"
    current = run(["git", "rev-parse", "--abbrev-ref", "HEAD"])
    if current.stdout.strip() != branch:
        checkout = run(["git", "checkout", "-B", branch])
        if checkout.returncode != 0:
            raise RuntimeError(checkout.stderr or checkout.stdout)
    return branch


def run_ai_collaboration(task: dict[str, Any]) -> tuple[int, str]:
    ai_script = Path("ai_collaboration.py")
    if not ai_script.exists():
        return 3, "ai_collaboration.py nao encontrado; tarefa nao executada para evitar falso positivo"
    cmd = [
        sys.executable,
        str(ai_script),
        "--modo",
        "ecommerce",
        "--tarefa",
        f"{task.get('title')}: {task.get('description')}",
    ]
    result = run(cmd, timeout=1800)
    return result.returncode, (result.stdout + "\n" + result.stderr).strip()


def classify_ai_result(returncode: int, output: str) -> tuple[str, str]:
    if returncode == 2:
        reason = output or "Nenhum cliente de IA disponivel; credenciais ou SDK ausentes."
        return "blocked_external_access_required", reason
    if returncode == 3:
        reason = output or "O fluxo de IA nao pode iniciar por dependencias ausentes."
        return "blocked_external_access_required", reason
    if returncode != 0:
        return "failed", output or "Falha inesperada no fluxo de IA."
    return "ok", output or "Fluxo de IA executado sem incidencias."


def open_or_report_pr(branch: str, task: dict[str, Any]) -> str | None:
    if not os.getenv("GH_TOKEN"):
        return None
    body = (
        "## Execucao autonoma segura\n\n"
        f"Task: `{task.get('id')}` - {task.get('title')}\n\n"
        "- Nao houve push direto em `main`.\n"
        "- Nao altera precos, campanhas, orcamento ou deploy automaticamente.\n"
        "- Revisao humana continua obrigatoria antes do merge/deploy.\n"
    )
    result = run([
        "gh",
        "pr",
        "create",
        "--draft",
        "--base",
        "main",
        "--head",
        branch,
        "--title",
        f"[agente] {task.get('title')}",
        "--body",
        body,
    ])
    if result.returncode == 0:
        return result.stdout.strip()
    if "already exists" in (result.stderr + result.stdout).lower():
        view = run(["gh", "pr", "view", branch, "--json", "url", "--jq", ".url"])
        return view.stdout.strip() or None
    print(result.stderr or result.stdout)
    return None


def execute_one(queue_data: dict[str, Any]) -> dict[str, Any]:
    task = select_next_task(queue_data)
    report: dict[str, Any] = {"status": "idle", "task": None, "events": []}
    if task is None:
        report["events"].append("Nenhuma tarefa pendente encontrada na fila canonica.")
        return report

    report["status"] = "running"
    report["task"] = {"id": task.get("id"), "title": task.get("title"), "priority": task.get("priority")}
    ok, guard_output = policy_guard(task)
    report["events"].append(guard_output)
    if not ok:
        task["status"] = "blocked_manual_approval_required"
        task["blocked_at"] = utc_now()
        task["blocked_reason"] = guard_output
        report["status"] = "blocked"
        save_queue(queue_data)
        return report

    ensure_git_identity()
    branch = create_task_branch(task)
    task["status"] = "in_progress"
    task["started_at"] = utc_now()
    task["branch"] = branch
    save_queue(queue_data)

    returncode, output = run_ai_collaboration(task)
    report["events"].append(output[-4000:] if output else "sem saida do agente")

    status, reason = classify_ai_result(returncode, output)
    if status == "blocked_external_access_required":
        task["status"] = "blocked_external_access_required"
        task["blocked_at"] = utc_now()
        task["blocked_reason"] = reason
        report["status"] = "blocked_external_access_required"
        save_queue(queue_data)
        return report
    if status == "failed":
        task["status"] = "pending"
        task["last_error_at"] = utc_now()
        task["last_error"] = reason[-1000:]
        report["status"] = "failed"
        save_queue(queue_data)
        return report

    save_queue(queue_data)
    run(["git", "add", "-A"])
    diff = run(["git", "diff", "--cached", "--quiet"])
    if diff.returncode == 0:
        task["status"] = "blocked_no_safe_change"
        task["blocked_at"] = utc_now()
        task["blocked_reason"] = "Agente executou, mas nao produziu alteracao auditavel."
        report["status"] = "no_safe_change"
        save_queue(queue_data)
        return report

    save_queue(queue_data)
    run(["git", "add", "-A"], check=True)
    run([
        "git",
        "commit",
        "-m",
        f"feat: {task.get('title')}\n\nExecutado por agente autonomo seguro.\nTask ID: {task.get('id')}\n",
    ], check=True)
    push = run(["git", "push", "-u", "origin", branch])
    if push.returncode != 0:
        task["status"] = "pending"
        task["last_error_at"] = utc_now()
        task["last_error"] = push.stderr[-1000:]
        report["status"] = "push_failed"
        save_queue(queue_data)
        return report

    pr_url = open_or_report_pr(branch, task)
    task["status"] = "pr_opened"
    task["completed_at"] = utc_now()
    task["pr_url"] = pr_url
    report["status"] = "pr_opened"
    report["pr_url"] = pr_url
    save_queue(queue_data)
    return report


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--max-cycles", type=int, default=int(os.getenv("AUTONOMOUS_MAX_CYCLES", "1")))
    args = parser.parse_args()

    queue_data = load_queue()
    reports = []
    for _ in range(max(1, args.max_cycles)):
        report = execute_one(queue_data)
        reports.append(report)
        write_report({"cycle_report": report})
        if report.get("status") in {"idle", "failed", "push_failed"}:
            break

    final = {"status": reports[-1].get("status") if reports else "idle", "cycles": reports}
    write_report(final)
    print(json.dumps(final, indent=2, ensure_ascii=False))
    return 0 if final["status"] not in {"failed", "push_failed"} else 1


if __name__ == "__main__":
    sys.exit(main())


