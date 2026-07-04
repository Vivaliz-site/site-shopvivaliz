#!/usr/bin/env python3
"""
Auto Task Generator - Agentes criam tarefas autonomamente
Analisa projeto e sugere novas features/melhorias
"""
import json
import subprocess
import os
import sys
from task_queue_lib import load_queue, save_queue, upsert_task

def get_gemini_suggestions():
    """Gemini analisa projeto e sugere tarefas"""
    try:
        import google.generativeai as genai

        genai.configure(api_key=os.getenv('GEMINI_API_KEY'))
        model = genai.GenerativeModel('gemini-1.5-flash')

        prompt = """Você é um arquiteto de software analisando um ecommerce ShopVivaliz.

Analize o que já foi implementado e sugira 3 novas tarefas/features de ALTA PRIORIDADE que faltam:

Já implementado:
- Filtro de preço 
- Em desenvolvimento: Carrinho persistente

Requisitos:
- Features que aumentem conversão
- Melhorias de UX/performance
- Integrações importantes

Retorne JSON com:
{
  "tasks": [
    {"title": "...", "description": "...", "priority": "high"},
    ...
  ]
}

Seja específico e técnico."""

        response = model.generate_content(prompt)
        return response.text
    except Exception as e:
        print(f" Gemini error: {e}")
        return None

def get_claude_analysis():
    """Claude valida e refina as sugestões"""
    try:
        from anthropic import Anthropic

        client = Anthropic()
        message = client.messages.create(
            model="claude-3-5-sonnet-20241022",
            max_tokens=1024,
            messages=[
                {
                    "role": "user",
                    "content": """Você é um revisor técnico. Analise o ShopVivaliz ecommerce.

Que features CRÍTICAS faltam para um ecommerce estar pronto para produção?

Priorize:
1. Segurança
2. Conversão
3. Performance
4. Conformidade legal

Retorne JSON com 2-3 tarefas URGENTES."""
                }
            ]
        )
        return message.content[0].text
    except Exception as e:
        print(f" Claude error: {e}")
        return None

def parse_and_create_tasks(suggestions):
    """Extrai tarefas e cria na fila"""
    queue_data = load_queue()

    # Extrair JSON das sugestões
    try:
        import re
        json_match = re.search(r'\{.*\}', suggestions, re.DOTALL)
        if json_match:
            tasks_data = json.loads(json_match.group())
            new_tasks = tasks_data.get('tasks', [])
        else:
            print(" Nenhuma sugestão estruturada recebida")
            return 0
    except json.JSONDecodeError:
        print(" Erro ao parsear JSON das sugestões")
        return 0

    # Adicionar à fila
    created = 0
    for task_data in new_tasks:
        if len(queue_data['queue']) >= 20:  # Limite de 20 tarefas
            break

        new_task = {
            "title": task_data.get('title', ''),
            "description": task_data.get('description', ''),
            "priority": task_data.get('priority', 'medium'),
            "status": "pending",
            "auto_generated": True,
            "source": "auto-task-generator",
        }

        task_record, created_now = upsert_task(queue_data, new_task)
        if not created_now:
            continue
        created += 1

        print(f" Tarefa criada: {task_record.get('id')} - {task_data.get('title')}")

    # Salvar fila atualizada
    save_queue(queue_data)

    return created

def main():
    print(" Auto Task Generator - Agentes sugerindo novas tarefas\n")

    # Gemini sugere
    print("  Gemini analisando projeto...")
    gemini_suggestions = get_gemini_suggestions()

    if gemini_suggestions:
        print(" Gemini forneceu sugestões")
        created = parse_and_create_tasks(gemini_suggestions)
        print(f" {created} tarefas criadas por Gemini\n")

    # Claude valida
    print("  Claude validando...")
    claude_analysis = get_claude_analysis()

    if claude_analysis:
        print(" Claude forneceu análise")
        created = parse_and_create_tasks(claude_analysis)
        print(f" {created} tarefas criadas por Claude\n")

    # Commit automático
    try:
        subprocess.run(["git", "add", "tasks-queue.json", "logs/tasks-queue.json"], check=True)
        subprocess.run([
            "git", "commit", "-m",
            "feat: Trio IA gerou novas tarefas autonomamente\n\nAgentes analisaram o projeto e sugeriram melhorias."
        ], check=True)
        subprocess.run(["git", "push", "origin", "HEAD"], check=True)
        print(" Tarefas commitadas e enviadas ao repositório")
    except Exception as e:
        print(f"  Erro ao fazer commit: {e}")

    print("\n Sistema de auto-geração de tarefas operacional!")

if __name__ == "__main__":
    main()
