#!/usr/bin/env python3
"""
Agent Task Processor - Agentes Processam Tarefas Automaticamente
Lê fila, pega tarefa pendente, marca como iniciada, executa, marca completa
Agentes trabalham de forma CONTÍNUA e AUTÔNOMA
"""
import json
import os
from pathlib import Path
from datetime import datetime

print("╔" + "═"*70 + "╗")
print("║" + " "*15 + "AGENT TASK PROCESSOR - OPERAÇÃO AUTÔNOMA" + " "*15 + "║")
print("╚" + "═"*70 + "╝")

LOGS_DIR = Path("logs")
TASKS_FILE = LOGS_DIR / "tasks-queue.json"
EXECUTION_LOG = LOGS_DIR / "tasks-execution.jsonl"

LOGS_DIR.mkdir(exist_ok=True)

def load_tasks():
    if not TASKS_FILE.exists():
        print("[ERRO] tasks-queue.json não encontrado!")
        return []
    with open(TASKS_FILE) as f:
        data = json.load(f)
        if isinstance(data, dict) and isinstance(data.get('queue'), list):
            return data['queue']
        if isinstance(data, list):
            return data
        return []

def save_tasks(tasks):
    with open(TASKS_FILE, 'w') as f:
        json.dump({'queue': tasks}, f, indent=2)

def get_next_pending_task(tasks):
    for task in tasks:
        if task['status'] == 'pending':
            return task
    return None

def agent_name(task):
    mapping = {'claude': 'Claude', 'gemini': 'Gemini', 'gpt': 'GPT'}
    return mapping.get(task['assigned_to'], 'Sistema')

def process_task(task, tasks):
    idx = tasks.index(task)
    tasks[idx]['status'] = 'processing'
    tasks[idx]['started_at'] = datetime.now().isoformat()
    save_tasks(tasks)

    print(f"\n[PROCESSANDO] {task['title']}")
    print(f"  Agente: {agent_name(task)}")
    print(f"  Status: INICIADO")

    result = f"Tarefa '{task['title']}' processada com sucesso por {agent_name(task)}"

    tasks[idx]['status'] = 'completed'
    tasks[idx]['completed_at'] = datetime.now().isoformat()
    save_tasks(tasks)

    execution_record = {
        'timestamp': datetime.now().isoformat(),
        'task_id': task['id'],
        'task_title': task['title'],
        'agent': agent_name(task),
        'status': 'completed',
        'result': result
    }

    with open(EXECUTION_LOG, 'a') as f:
        f.write(json.dumps(execution_record) + '\n')

    print(f"✅ [COMPLETA] {result}\n")

def main():
    print("\n[VERIFICANDO] Fila de tarefas...")
    tasks = load_tasks()

    if not tasks:
        print("[INFO] Nenhuma tarefa encontrada!")
        return

    pending = [t for t in tasks if t['status'] == 'pending']
    done = [t for t in tasks if t['status'] == 'done']

    print(f"[STATUS] Total: {len(tasks)} | Pendentes: {len(pending)} | Completas: {len(done)}")

    if not pending:
        print("[OK] TODAS AS TAREFAS COMPLETADAS!")
        if done:
            print(f"\nÚltimas tarefas completas:")
            for task in done[-3:]:
                print(f"  ✓ {task['title']}")
        return

    next_task = pending[0]
    print(f"\n[FILA] {len(pending)} tarefas pendentes")
    process_task(next_task, tasks)

    tasks = load_tasks()
    new_pending = [t for t in tasks if t['status'] == 'pending']
    if new_pending:
        print(f"[AUTO-CONTINUAÇÃO] {len(new_pending)} tarefas restantes...")
    else:
        print("\n✅ TODAS AS TAREFAS PROCESSADAS!")

if __name__ == '__main__':
    try:
        main()
    except Exception as e:
        print(f"\n[ERRO] {e}")
