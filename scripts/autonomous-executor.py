#!/usr/bin/env python3
"""Executor autônomo do Trio IA"""

import argparse
import json
import os
import subprocess
import sys

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


def main() -> int:
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


if __name__ == "__main__":
    raise SystemExit(main())
