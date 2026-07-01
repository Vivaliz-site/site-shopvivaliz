#!/usr/bin/env python3
"""Gerenciar fila de tarefas do Trio IA Autônomo"""
import json
import sys
import argparse
from datetime import datetime
from pathlib import Path

QUEUE_FILE = Path("logs/tasks-queue.json")

def load_queue():
    if not QUEUE_FILE.exists():
        return {"queue": []}
    with open(QUEUE_FILE, "r", encoding="utf-8") as f:
        data = json.load(f)
        if isinstance(data, dict) and isinstance(data.get("queue"), list):
            return data
        if isinstance(data, list):
            return {"queue": data}
        return {"queue": []}

def save_queue(data):
    QUEUE_FILE.parent.mkdir(parents=True, exist_ok=True)
    with open(QUEUE_FILE, "w", encoding="utf-8") as f:
        json.dump(data, f, indent=2, ensure_ascii=False)

def list_tasks(status=None):
    data = load_queue()
    tasks = data["queue"]

    if status:
        tasks = [t for t in tasks if t["status"] == status]

    if not tasks:
        print(f"Nenhuma tarefa encontrada (status={status or 'qualquer'})")
        return

    print(f"\n{'ID':<12} | {'Título':<40} | {'Status':<10} | {'Prioridade':<8}")
    print("-" * 75)

    for task in tasks:
        print(f"{task['id']:<12} | {task['title'][:40]:<40} | {task['status']:<10} | {task['priority']:<8}")

    print()

def add_task(title, description, priority="medium"):
    data = load_queue()

    # Gerar ID
    task_ids = []
    for t in data["queue"]:
        task_id = str(t.get("id", ""))
        if task_id.startswith("task-"):
            try:
                task_ids.append(int(task_id.split("-")[1]))
            except (IndexError, ValueError):
                continue
    new_id = f"task-{max(task_ids or [0]) + 1:03d}"

    new_task = {
        "id": new_id,
        "title": title,
        "description": description,
        "priority": priority,
        "status": "pending",
        "created_at": datetime.utcnow().isoformat() + "Z"
    }

    data["queue"].append(new_task)
    save_queue(data)

    print(f" Tarefa adicionada!")
    print(f"   ID: {new_id}")
    print(f"   Título: {title}")
    print(f"   Prioridade: {priority}")

def remove_task(task_id):
    data = load_queue()
    data["queue"] = [t for t in data["queue"] if t["id"] != task_id]
    save_queue(data)
    print(f" Tarefa {task_id} removida da fila")

def mark_task(task_id, status):
    data = load_queue()
    task = next((t for t in data["queue"] if t["id"] == task_id), None)

    if not task:
        print(f" Tarefa {task_id} não encontrada")
        return

    task["status"] = status
    if status == "completed":
        task["completed_at"] = datetime.utcnow().isoformat() + "Z"

    save_queue(data)
    print(f" Tarefa {task_id} marcada como {status}")

def priority(task_id, new_priority):
    data = load_queue()
    task = next((t for t in data["queue"] if t["id"] == task_id), None)

    if not task:
        print(f" Tarefa {task_id} não encontrada")
        return

    task["priority"] = new_priority
    save_queue(data)
    print(f" Prioridade de {task_id} alterada para {new_priority}")

def stats():
    data = load_queue()
    tasks = data["queue"]

    total = len(tasks)
    completed = len([t for t in tasks if t["status"] == "completed"])
    pending = len([t for t in tasks if t["status"] == "pending"])

    print(f"\n Estatísticas da Fila:")
    print(f"   Total: {total}")
    print(f"    Completas: {completed}")
    print(f"   ⏳ Pendentes: {pending}")
    print(f"   Taxa: {(completed/total*100):.1f}%" if total else "   Taxa: 0.0%")
    print()

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Gerenciar fila de tarefas do Trio IA")
    subparsers = parser.add_subparsers(dest="comando", help="Comando")

    # List
    list_cmd = subparsers.add_parser("list", help="Listar tarefas")
    list_cmd.add_argument("--status", choices=["pending", "completed"], help="Filtrar por status")

    # Add
    add_cmd = subparsers.add_parser("add", help="Adicionar tarefa")
    add_cmd.add_argument("title", help="Título da tarefa")
    add_cmd.add_argument("description", help="Descrição da tarefa")
    add_cmd.add_argument("--priority", default="medium", choices=["low", "medium", "high"], help="Prioridade")

    # Remove
    remove_cmd = subparsers.add_parser("remove", help="Remover tarefa")
    remove_cmd.add_argument("task_id", help="ID da tarefa")

    # Mark
    mark_cmd = subparsers.add_parser("mark", help="Marcar tarefa como completa/pendente")
    mark_cmd.add_argument("task_id", help="ID da tarefa")
    mark_cmd.add_argument("--status", choices=["pending", "completed"], default="completed", help="Novo status")

    # Priority
    priority_cmd = subparsers.add_parser("priority", help="Alterar prioridade")
    priority_cmd.add_argument("task_id", help="ID da tarefa")
    priority_cmd.add_argument("level", choices=["low", "medium", "high"], help="Novo nível de prioridade")

    # Stats
    subparsers.add_parser("stats", help="Ver estatísticas")

    args = parser.parse_args()

    if args.comando == "list":
        list_tasks(status=args.status)
    elif args.comando == "add":
        add_task(args.title, args.description, args.priority)
    elif args.comando == "remove":
        remove_task(args.task_id)
    elif args.comando == "mark":
        mark_task(args.task_id, args.status)
    elif args.comando == "priority":
        priority(args.task_id, args.level)
    elif args.comando == "stats":
        stats()
    else:
        list_tasks()
