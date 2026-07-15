#!/usr/bin/env python3
"""
Processador de Fila de Tarefas - Agentes Autônomos
Lê tarefas, delega para agentes, executa, reporta
"""
import json
import os
import sys
from pathlib import Path
from datetime import datetime

print("=" * 70)
print("PROCESSADOR DE FILA DE TAREFAS v1.0")
print("=" * 70)

# Diretórios
LOGS_DIR = Path("logs")
TASKS_QUEUE = LOGS_DIR / "tasks-queue.json"
TASKS_LOG = LOGS_DIR / "tasks-processed.jsonl"

LOGS_DIR.mkdir(exist_ok=True)

# Tarefas pré-programadas se nenhuma existir
DEFAULT_TASKS = [
    {
        "id": "auto-1",
        "title": "Sincronizar produtos Olist",
        "description": "Buscar 198 produtos de Olist e atualizar catálogo",
        "priority": "high",
        "assigned_to": "all",
        "status": "pending"
    },
    {
        "id": "auto-2",
        "title": "Otimizar imagens de produtos",
        "description": "Redimensionar e cachear imagens do catálogo",
        "priority": "medium",
        "assigned_to": "gemini",
        "status": "pending"
    },
    {
        "id": "auto-3",
        "title": "Validar checkout segurança",
        "description": "Revisar fluxo de checkout para vulnerabilidades",
        "priority": "high",
        "assigned_to": "gpt",
        "status": "pending"
    },
    {
        "id": "auto-4",
        "title": "Gerar página de sobre",
        "description": "Criar página /sobre/ com informações da marca",
        "priority": "medium",
        "assigned_to": "claude",
        "status": "pending"
    },
    {
        "id": "auto-5",
        "title": "Implementar pagamento PIX",
        "description": "Integrar PIX no checkout para aumentar conversão",
        "priority": "high",
        "assigned_to": "gpt",
        "status": "pending"
    }
]

def load_tasks():
    """Carregar fila de tarefas ou usar padrão"""
    if TASKS_QUEUE.exists():
        try:
            return json.loads(TASKS_QUEUE.read_text())
        except:
            pass
    return DEFAULT_TASKS

def get_pending_tasks(tasks):
    """Retornar tarefas pendentes"""
    return [t for t in tasks if t.get("status") == "pending"]

def assign_task_to_agent(task):
    """Simular delegação de tarefa"""
    assigned = task.get("assigned_to", "all")
    agents = ["claude", "gpt", "gemini"] if assigned == "all" else [assigned]

    return {
        "task_id": task["id"],
        "task_title": task["title"],
        "agents": agents,
        "assigned_at": datetime.now().isoformat()
    }

def process_tasks():
    """Processar fila de tarefas"""

    print("\n[LOAD] Carregando fila de tarefas...")
    tasks = load_tasks()
    print(f"[OK] {len(tasks)} tarefas carregadas")

    pending = get_pending_tasks(tasks)
    print(f"[PENDING] {len(pending)} tarefas pendentes")

    if not pending:
        print("[INFO] Nenhuma tarefa pendente")
        return

    print("\n[PROCESSAMENTO] Iniciando processamento...")
    processed = []

    for idx, task in enumerate(pending[:3], 1):  # Processar top 3
        print(f"\n  [{idx}] {task['title']}")
        print(f"       Priority: {task['priority']}")
        print(f"       Assigned to: {task['assigned_to']}")

        assignment = assign_task_to_agent(task)
        assignment["status"] = "assigned"
        assignment["timestamp"] = datetime.now().isoformat()

        processed.append(assignment)
        print(f"       ✓ Delegada para: {', '.join(assignment['agents'])}")

    # Salvar processamento
    if processed:
        with open(TASKS_LOG, "a") as f:
            for p in processed:
                f.write(json.dumps(p) + "\n")
        print(f"\n[SAVE] {len(processed)} tarefas processadas salvas")

    # Atualizar status das tarefas
    for task in tasks:
        for p in processed:
            if task["id"] == p["task_id"]:
                task["status"] = "assigned"

    with open(TASKS_QUEUE, "w") as f:
        json.dump(tasks, f, indent=2)

    print("\n[OK] Fila atualizada")

    # Relatório
    print("\n" + "=" * 70)
    print("RELATÓRIO DE PROCESSAMENTO")
    print("=" * 70)
    print(f"Total de tarefas: {len(tasks)}")
    print(f"Pendentes: {len([t for t in tasks if t['status'] == 'pending'])}")
    print(f"Assigned: {len([t for t in tasks if t['status'] == 'assigned'])}")
    print(f"Processadas neste ciclo: {len(processed)}")

    # Próximas tarefas
    next_pending = [t for t in tasks if t["status"] == "pending"]
    if next_pending:
        print(f"\nPróximas tarefas:")
        for t in next_pending[:3]:
            print(f"  - {t['title']} ({t['priority']})")

if __name__ == "__main__":
    try:
        process_tasks()
        print("\n[SUCCESS] Processamento concluído!")
        sys.exit(0)
    except Exception as e:
        print(f"\n[ERROR] {e}")
        sys.exit(1)
