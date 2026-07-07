#!/usr/bin/env python3
"""Executor autônomo do Trio IA"""

import subprocess
import sys
from datetime import datetime
from pathlib import Path
import argparse
import json
import os

from task_queue_lib import executable_pending_tasks, load_queue, save_queue, utc_now

BLOCKED_MISSING_ENV = "blocked_missing_env"
BLOCKED_MANUAL_ACCESS = "blocked_manual_access"
BLOCKED_HUMAN_APPROVAL = "blocked_human_approval_required"
BLOCKED_POLICY = "blocked_price_approval_required"


def missing_env_vars(task: dict) -> list[str]:
    return [env for env in task.get("requires_env", []) if not os.getenv(env)]


def requires_human_approval(task: dict) -> bool:
    return bool(task.get("requires_human_approval"))


def choose_next_task(queue_data: dict) -> tuple[dict | None, str | None]:
    for task in executable_pending_tasks(queue_data):
        if task.get("requires_manual_access"):
            task["status"] = BLOCKED_MANUAL_ACCESS
            task["blocked_at"] = utc_now()
            task["blocked_reason"] = "requires_manual_access"
            continue

        if requires_human_approval(task):
            task["status"] = BLOCKED_HUMAN_APPROVAL
            task["blocked_at"] = utc_now()
            task["blocked_reason"] = str(task.get("approval_scope", "human_approval_required"))
            continue

        missing = missing_env_vars(task)
        if missing:
            task["status"] = BLOCKED_MISSING_ENV
            task["blocked_at"] = utc_now()
            task["blocked_reason"] = f"missing_env:{','.join(missing)}"
            continue

        return task, None

    return None, "no_executable_pending_tasks"

def main():
    parser = argparse.ArgumentParser(description="Executor autônomo do Trio IA")
    parser.add_argument("--dry-run", action="store_true", help="Seleciona e valida a próxima tarefa sem executar ai_collaboration.py")
    args = parser.parse_args()

    queue_data = load_queue()
    original_snapshot = json.dumps(queue_data, ensure_ascii=False, sort_keys=True)

    task, reason = choose_next_task(queue_data)
    if json.dumps(queue_data, ensure_ascii=False, sort_keys=True) != original_snapshot:
        save_queue(queue_data)

    if not task:
        if reason == "no_executable_pending_tasks":
            print("[OK] Nenhuma tarefa executavel pendente. Restam apenas itens completos ou bloqueados.")
        else:
            print("[OK] Nenhuma tarefa pendente. Fila vazia ou todas completas!")
        return 0

    task_id = task["id"]
    task_title = task["title"]
    task_desc = task["description"]

    print(f"[EXEC] Executando tarefa: {task_title}")
    print(f"   ID: {task_id}")
    print(f"   Descrição: {task_desc[:100]}...")
    print()


    # Guardião da política autônoma
    guard = subprocess.run(
        [
            sys.executable,
            "scripts/autonomous-policy-guard.py",
            "--title",
            task_title,
            "--description",
            task_desc,
        ],
        capture_output=True,
        text=True,
    )

    print(guard.stdout)

    if guard.returncode != 0:
        task["status"] = BLOCKED_POLICY
        task["blocked_at"] = utc_now()
        task["blocked_reason"] = "pricing_policy"
        save_queue(queue_data)

        print("[BLOCKED] Tarefa bloqueada pela politica de precos.")
        return 2

    if args.dry_run:
        print("[DRY-RUN] Tarefa validada e pronta para execução autônoma.")
        return 0

    # Executar Trio IA com a tarefa
    try:
        result = subprocess.run(
            [
                sys.executable,
                "ai_collaboration.py",
                "--modo",
                "ecommerce",
                "--tarefa",
                f"{task_title}: {task_desc}",
            ],
            capture_output=True,
            text=True,
            timeout=600,
        )

        if result.returncode == 0:
            print("[OK] Tarefa executada com sucesso!")

            # Marcar tarefa como completa
            task["status"] = "completed"
            task["completed_at"] = utc_now()

            # Salvar fila atualizada
            save_queue(queue_data)

            # Commit das mudanças
            subprocess.run(["git", "add", "-A"], check=True)

            # Verificar se há mudanças
            diff_result = subprocess.run(
                ["git", "diff", "--cached", "--quiet"], capture_output=True
            )

            if diff_result.returncode != 0:
                subprocess.run(
                    [
                        "git",
                        "commit",
                        "-m",
                        f"feat: {task_title}\n\nExecutado automaticamente pelo Trio IA\nTask ID: {task_id}\n\nCo-Authored-By: Trio IA Autônomo <trio-autonomo@shopvivaliz.com.br>",
                    ],
                    check=True,
                )
                subprocess.run(["git", "push", "origin", "HEAD"], check=True)
                print("[PUSH] Código commitado e publicado na branch atual")
                print("[FLOW] PR e deploy seguem o fluxo Git protegido do projeto")
            else:
                print("[INFO] Nenhuma mudanca de codigo para commitar")

            print(f"[OK] Tarefa {task_id} marcada como completa")
            print()
            print("[NEXT] Proxima execucao em 1 hora")
            return 0
        else:
            print(f"[ERROR] Erro ao executar: {result.stderr}")
            return 1

    except subprocess.TimeoutExpired:
        print("[TIMEOUT] Execucao acima de 10 min. Tentando novamente na proxima hora.")
        return 1
    except Exception as e:
        print(f"[ERROR] Erro: {e}")
        return 1
"""Executor autonomo seguro do ShopVivaliz.

Objetivo:
- usar tasks-queue.json como fila canonica;
- manter logs/tasks-queue.json como espelho legado;
- nunca publicar direto em main;
- abrir branch/PR para tarefas seguras;
- deixar relatorio auditavel mesmo quando nao ha chave de IA ou mudancas.
"""
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

    if returncode == 3:
        task["status"] = "blocked_external_access_required"
        task["blocked_at"] = utc_now()
        task["blocked_reason"] = output
        report["status"] = "blocked_external_access_required"
        save_queue(queue_data)
        return report
    if returncode != 0:
        task["status"] = "pending"
        task["last_error_at"] = utc_now()
        task["last_error"] = output[-1000:]
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

