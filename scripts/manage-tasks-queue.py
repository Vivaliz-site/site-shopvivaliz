#!/usr/bin/env python3
"""Read-only inspection CLI for the retired autonomous task queue."""
from __future__ import annotations

import argparse
import sys
from typing import Any

from task_queue_lib import ALLOWED_STATES, load_queue, queue_summary, task_identifier

RETIRED_MESSAGE = (
    "Mutation blocked: the legacy autonomous queue is retired. "
    "Submit changes through a reviewed pull request and use completed_verified only "
    "after run, commit, tests, read-back and artifact evidence exist."
)


def _sort_key(task: dict[str, Any]) -> tuple[int, str, str]:
    return (
        int(task.get("queue_rank", 9999)),
        str(task.get("created_at", "")),
        task_identifier(task),
    )


def list_tasks(status: str | None = None) -> int:
    data = load_queue()
    tasks = data["tasks"]
    if status:
        tasks = [task for task in tasks if task.get("status") == status]
    tasks = sorted(tasks, key=_sort_key)

    if not tasks:
        print(f"Nenhuma tarefa encontrada (status={status or 'qualquer'})")
        return 0

    print(f"\n{'ID':<16} | {'Título':<40} | {'Status':<20} | {'Prioridade':<10}")
    print("-" * 96)
    for task in tasks:
        print(
            f"{task_identifier(task):<16} | "
            f"{str(task.get('title', ''))[:40]:<40} | "
            f"{str(task.get('status', '')):<20} | "
            f"{str(task.get('priority', 'medium')):<10}"
        )
    print()
    return 0


def stats() -> int:
    summary = queue_summary(load_queue())
    print("\nEstatísticas da fila canônica:")
    print(f"   Total: {summary['total']}")
    for state in sorted(ALLOWED_STATES):
        print(f"   {state}: {summary[state]}")
    print()
    return 0


def retired_mutation(_: argparse.Namespace) -> int:
    print(RETIRED_MESSAGE, file=sys.stderr)
    return 2


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Inspecionar a fila canônica; mutações diretas estão aposentadas"
    )
    subparsers = parser.add_subparsers(dest="command")

    list_cmd = subparsers.add_parser("list", help="Listar tarefas sem alterar a fila")
    list_cmd.add_argument("--status", choices=sorted(ALLOWED_STATES), help="Filtrar por status")

    subparsers.add_parser("stats", help="Ver estatísticas sem alterar a fila")

    add_cmd = subparsers.add_parser("add", help="Aposentado: exige pull request revisado")
    add_cmd.add_argument("title")
    add_cmd.add_argument("description")
    add_cmd.add_argument("--priority", default="medium")
    add_cmd.set_defaults(handler=retired_mutation)

    remove_cmd = subparsers.add_parser("remove", help="Aposentado: exige pull request revisado")
    remove_cmd.add_argument("task_id")
    remove_cmd.set_defaults(handler=retired_mutation)

    mark_cmd = subparsers.add_parser("mark", help="Aposentado: conclusão exige evidência")
    mark_cmd.add_argument("task_id")
    mark_cmd.add_argument("--status")
    mark_cmd.set_defaults(handler=retired_mutation)

    priority_cmd = subparsers.add_parser("priority", help="Aposentado: exige pull request revisado")
    priority_cmd.add_argument("task_id")
    priority_cmd.add_argument("level")
    priority_cmd.set_defaults(handler=retired_mutation)
    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()
    handler = getattr(args, "handler", None)
    if handler is not None:
        return int(handler(args))
    if args.command == "stats":
        return stats()
    if args.command == "list":
        return list_tasks(args.status)
    return list_tasks()


if __name__ == "__main__":
    raise SystemExit(main())
