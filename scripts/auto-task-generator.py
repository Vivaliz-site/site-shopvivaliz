#!/usr/bin/env python3
"""
Auto Task Generator - gera novas tarefas reais a partir de sinais do projeto
e, quando disponivel, complementa com sugestoes de IA.
"""
import json
import os
import re
import subprocess
from pathlib import Path

from task_queue_lib import load_queue, save_queue, upsert_task

ROOT = Path(__file__).resolve().parents[1]


def read_json(path: Path, default):
    if not path.exists():
        return default
    try:
        data = json.loads(path.read_text(encoding='utf-8'))
        return data if isinstance(data, type(default)) else default
    except Exception:
        return default


def project_signal_tasks():
    signals = []
    health = read_json(ROOT / 'logs' / 'system-health-check.json', {})
    cycle = read_json(ROOT / 'logs' / 'autonomous-cycle-report.json', {})
    tri = read_json(ROOT / 'logs' / 'tri-environment-sync.json', {})
    deploy = read_json(ROOT / 'logs' / 'deploy-diagnostic.json', {})
    email = read_json(ROOT / 'logs' / 'email-config-check.json', {})
    catalog = read_json(ROOT / 'api' / 'catalog' / 'fallback-products.json', [])

    if health.get('status') not in (None, 'HEALTHY'):
        signals.append({
            "title": "Resolver anomalias reais detectadas no health check",
            "description": "Investigar e corrigir erros ou warnings apontados em logs/system-health-check.json para manter a operacao autonoma estavel.",
            "priority": "high",
        })

    if cycle.get('selection', {}).get('mode') == 'idle':
        signals.append({
            "title": "Gerar backlog autonomo novo quando a fila entrar em idle",
            "description": "O ciclo autonomo entrou em idle. Criar missoes seguras e executaveis com base em sinais reais do projeto para manter evolucao continua.",
            "priority": "high",
        })

    if tri and tri.get('status') not in ('healthy', 'warning'):
        signals.append({
            "title": "Restaurar telemetria triambiente no monitor operacional",
            "description": "Corrigir ou repopular o status de sincronizacao entre local, GitHub e Ubuntu para devolver visibilidade real ao monitor.",
            "priority": "high",
        })

    if deploy.get('ok') is False:
        signals.append({
            "title": "Corrigir falhas concretas do validador de deploy",
            "description": "Tratar os problemas apontados em logs/deploy-diagnostic.json para impedir deploy com erro silencioso.",
            "priority": "high",
        })

    if email and not email.get('ok', False):
        signals.append({
            "title": "Restaurar configuracao real de email do 24/7",
            "description": "Validar aliases SMTP/MAIL/EMAIL, destinatarios e autenticacao para que os relatorios autonomos voltem a ser entregues.",
            "priority": "high",
        })

    if isinstance(catalog, list):
        no_image = sum(
            1 for item in catalog
            if isinstance(item, dict) and str(item.get('image_url', '')).strip() in ('', '/favicon.ico')
        )
        if no_image > 0:
            signals.append({
                "title": "Reparar produtos reais do catalogo sem imagem valida",
                "description": f"Foram encontrados {no_image} produtos sem imagem valida no catalogo fallback; revisar cobertura visual desses SKUs.",
                "priority": "high",
            })

    return signals[:8]


def get_gemini_suggestions():
    api_key = os.getenv('GEMINI_API_KEY', '').strip()
    if not api_key:
        return None

    try:
        import google.generativeai as genai
        genai.configure(api_key=api_key)
        model = genai.GenerativeModel(os.getenv('GEMINI_MODEL') or 'gemini-2.5-flash')
        prompt = """Voce esta analisando o projeto ShopVivaliz.

Considere que a fila precisa de tarefas novas, seguras e executaveis.
Retorne ate 3 tarefas reais que aumentem conversao, confiabilidade ou observabilidade.

Formato JSON:
{
  "tasks": [
    {"title": "...", "description": "...", "priority": "high"}
  ]
}"""
        response = model.generate_content(prompt)
        return response.text
    except ModuleNotFoundError:
        print("Gemini skipped: runtime google.generativeai indisponivel neste ambiente")
        return None
    except Exception as exc:
        print(f"Gemini error: {exc}")
        return None


def get_claude_analysis():
    if not os.getenv('ANTHROPIC_API_KEY', '').strip():
        return None

    try:
        from anthropic import Anthropic
        client = Anthropic()
        message = client.messages.create(
            model=os.getenv("ANTHROPIC_MODEL") or "claude-haiku-4-5-20251001",
            max_tokens=int(os.getenv("ANTHROPIC_MAX_TOKENS") or "512"),
            messages=[{
                "role": "user",
                "content": """Analise o ecommerce ShopVivaliz e retorne ate 3 tarefas urgentes, seguras e auditaveis.
Priorize operacao, monitoramento, checkout e marketplace.
Responda em JSON no formato {"tasks":[...]}."""
            }],
        )
        return message.content[0].text
    except ModuleNotFoundError:
        print("Claude skipped: runtime anthropic indisponivel neste ambiente")
        return None
    except Exception as exc:
        print(f"Claude error: {exc}")
        return None


def parse_tasks_blob(blob):
    if not blob:
        return []
    try:
        match = re.search(r'\{.*\}', blob, re.DOTALL)
        if not match:
            return []
        payload = json.loads(match.group())
        tasks = payload.get('tasks', [])
        return tasks if isinstance(tasks, list) else []
    except Exception:
        return []


def append_tasks(task_rows, source):
    queue = load_queue()
    created = 0
    for task_data in task_rows:
        new_task = {
            "title": task_data.get('title', ''),
            "description": task_data.get('description', ''),
            "priority": task_data.get('priority', 'medium'),
            "status": "pending",
            "auto_generated": True,
            "source": source,
        }
        _, created_now = upsert_task(queue, new_task)
        if created_now:
            created += 1
    save_queue(queue)
    return created


def main():
    print("Auto Task Generator - criando tarefas autonomamente")

    real_created = append_tasks(project_signal_tasks(), 'real-project-signals')
    print(f"{real_created} tarefas criadas a partir de sinais reais")

    gemini_created = append_tasks(parse_tasks_blob(get_gemini_suggestions()), 'auto-task-generator-gemini')
    if gemini_created:
        print(f"{gemini_created} tarefas criadas por Gemini")

    claude_created = append_tasks(parse_tasks_blob(get_claude_analysis()), 'auto-task-generator-claude')
    if claude_created:
        print(f"{claude_created} tarefas criadas por Claude")

    total_created = real_created + gemini_created + claude_created
    print(f"Total de tarefas novas: {total_created}")

    try:
        subprocess.run(["git", "add", "tasks-queue.json"], check=True)
    except Exception as exc:
        print(f"Git add warning: {exc}")


if __name__ == "__main__":
    main()
