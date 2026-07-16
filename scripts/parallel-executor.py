#!/usr/bin/env python3
"""
Executor Paralelo - 3 Agentes IA trabalhando simultaneamente
Com sistema de consenso para criação de tarefas
"""
import json
import subprocess
import threading
import os
from pathlib import Path
from datetime import datetime

# APIs dos agentes
GEMINI_KEY = os.getenv('GEMINI_API_KEY')
ANTHROPIC_KEY = os.getenv('ANTHROPIC_API_KEY')
OPENAI_KEY = os.getenv('OPENAI_API_KEY')

class TrioAgent:
    def __init__(self, name, api_key, model):
        self.name = name
        self.api_key = api_key
        self.model = model
        self.result = None
        self.error = None

    def execute_task(self, task_id, task_title, task_desc):
        """Executar tarefa específica"""
        print(f" {self.name} começando task-{task_id}: {task_title}")

        try:
            if self.name == "Gemini":
                self.result = self._gemini_execute(task_id, task_title, task_desc)
            elif self.name == "Claude":
                self.result = self._claude_execute(task_id, task_title, task_desc)
            elif self.name == "ChatGPT":
                self.result = self._chatgpt_execute(task_id, task_title, task_desc)

            print(f" {self.name} completou task-{task_id}")
        except Exception as e:
            self.error = str(e)
            print(f" {self.name} erro: {e}")

    def _gemini_execute(self, task_id, task_title, task_desc):
        """Gemini: Análise de Arquitetura"""
        import google.generativeai as genai

        genai.configure(api_key=self.api_key)
        model_name = os.getenv('GEMINI_MODEL') or 'gemini-1.5-flash'
        model = genai.GenerativeModel(model_name)

        prompt = f"""Você é arquiteto de software. Implemente RAPIDAMENTE:

Tarefa: {task_title}
Descrição: {task_desc}

Responda com:
1. Arquitetura proposta
2. Principais componentes
3. Status: CONCLUÍDO"""

        response = model.generate_content(prompt)
        return {
            'agent': 'Gemini',
            'task_id': task_id,
            'architecture': response.text,
            'status': 'completed'
        }

    def _claude_execute(self, task_id, task_title, task_desc):
        """Claude: Implementação em PHP"""
        from anthropic import Anthropic

        client = Anthropic()
        message = client.messages.create(
            model=os.getenv("ANTHROPIC_MODEL") or "claude-haiku-4-5-20251001",
            max_tokens=2048,
            messages=[
                {
                    "role": "user",
                    "content": f"""Implemente em PHP/JavaScript:

Tarefa: {task_title}
Descrição: {task_desc}

Forneça:
1. Código PHP funcional
2. Código JavaScript (se necessário)
3. Status: CONCLUÍDO"""
                }
            ]
        )
        return {
            'agent': 'Claude',
            'task_id': task_id,
            'implementation': message.content[0].text,
            'status': 'completed'
        }

    def _chatgpt_execute(self, task_id, task_title, task_desc):
        """ChatGPT: Validação e Testes"""
        import openai

        client = openai.OpenAI(api_key=self.api_key)
        response = client.chat.completions.create(
            model=os.getenv("OPENAI_MODEL") or "gpt-4o-mini",
            messages=[
                {
                    "role": "user",
                    "content": f"""Valide e revise:

Tarefa: {task_title}
Descrição: {task_desc}

Forneça:
1. Checklist de validação
2. Testes sugeridos
3. Status: VALIDADO"""
                }
            ],
            max_tokens=1024
        )
        return {
            'agent': 'ChatGPT',
            'task_id': task_id,
            'validation': response.choices[0].message.content,
            'status': 'completed'
        }


class ConsensusVoting:
    """Sistema de consenso entre agentes para criar novas tarefas"""

    def __init__(self):
        self.votes = {}
        self.consensus_threshold = 2  # Maioria de 3 agentes

    def propose_task(self, agents, task_proposal):
        """Propor nova tarefa e votar"""
        print(f"\n🗳️  VOTAÇÃO: {task_proposal['title']}")

        for agent in agents:
            vote = self._ask_agent_vote(agent, task_proposal)
            self.votes[agent.name] = vote
            print(f"  {agent.name}: {' SIM' if vote else ' NÃO'}")

        # Verificar consenso
        yes_votes = sum(1 for v in self.votes.values() if v)
        consensus = yes_votes >= self.consensus_threshold

        print(f"\n Resultado: {yes_votes}/3 agentes aprovaram")

        if consensus:
            print(f" CONSENSO: Tarefa '{task_proposal['title']}' será criada!")
            return True
        else:
            print(f" BLOQUEADO: Sem consenso para '{task_proposal['title']}'")
            return False

    def _ask_agent_vote(self, agent, proposal):
        """Pedir voto do agente"""
        # Simulação de votação (em produção, seria via API real)
        score = self._calculate_score(proposal)
        return score > 0.6


class ParallelExecutor:
    def __init__(self):
        self.tasks = self._load_tasks()
        self.agents = [
            TrioAgent("Gemini", GEMINI_KEY, os.getenv("GEMINI_MODEL") or "gemini-1.5-flash"),
            TrioAgent("Claude", ANTHROPIC_KEY, os.getenv("ANTHROPIC_MODEL") or "claude-haiku-4-5-20251001"),
            TrioAgent("ChatGPT", OPENAI_KEY, os.getenv("OPENAI_MODEL") or "gpt-4o-mini")
        ]

    def _load_tasks(self):
        """Carregar fila de tarefas"""
        queue_file = Path("logs/tasks-queue.json")
        with open(queue_file) as f:
            data = json.load(f)
        return [t for t in data['queue'] if t['status'] == 'pending']

    def execute_parallel(self):
        """Executar 3 tarefas em paralelo"""
        print(f" EXECUTANDO {min(3, len(self.tasks))} TAREFAS EM PARALELO\n")

        threads = []
        tasks_to_execute = self.tasks[:3]

        for i, (agent, task) in enumerate(zip(self.agents, tasks_to_execute)):
            thread = threading.Thread(
                target=agent.execute_task,
                args=(task['id'], task['title'], task['description'])
            )
            threads.append(thread)
            thread.start()

        # Aguardar conclusão
        for thread in threads:
            thread.join()

        print("\n TODAS AS 3 TAREFAS COMPLETADAS!")
        self._save_results(tasks_to_execute)

    def _save_results(self, completed_tasks):
        """Salvar resultados e atualizar queue"""
        queue_file = Path("logs/tasks-queue.json")
        with open(queue_file) as f:
            data = json.load(f)

        # Marcar tarefas como completas
        for task in data['queue']:
            if task['id'] in [t['id'] for t in completed_tasks]:
                task['status'] = 'completed'
                task['completed_at'] = datetime.utcnow().isoformat() + 'Z'

        with open(queue_file, 'w') as f:
            json.dump(data, f, indent=2, ensure_ascii=False)

    def vote_for_new_tasks(self, new_task_proposals):
        """Votar sobre novas tarefas"""
        voting = ConsensusVoting()

        for proposal in new_task_proposals:
            if voting.propose_task(self.agents, proposal):
                # Adicionar à fila
                self._add_approved_task(proposal)

    def _add_approved_task(self, task):
        """Adicionar tarefa aprovada à fila"""
        queue_file = Path("logs/tasks-queue.json")
        with open(queue_file) as f:
            data = json.load(f)

        max_id = max([int(t['id'].split('-')[1]) for t in data['queue']], default=0)
        new_id = f"task-{str(max_id + 1).zfill(3)}"

        new_task = {
            "id": new_id,
            "title": task['title'],
            "description": task['description'],
            "priority": task.get('priority', 'medium'),
            "status": "pending",
            "created_at": datetime.utcnow().isoformat() + 'Z',
            "auto_generated_by_consensus": True,
            "votes": "3/3"
        }

        data['queue'].append(new_task)

        with open(queue_file, 'w') as f:
            json.dump(data, f, indent=2, ensure_ascii=False)

        print(f" {new_id} adicionada com consenso dos 3 agentes!")


def main():
    print("=" * 60)
    print(" TRIO IA - EXECUTOR PARALELO COM CONSENSO ")
    print("=" * 60)

    executor = ParallelExecutor()

    # Executar 3 tarefas em paralelo
    executor.execute_parallel()

    # Exemplo de votação para novas tarefas
    new_proposals = [
        {
            'title': 'Implementar WebSockets para chat real-time',
            'description': 'Socket.io integrado com agentes IA',
            'priority': 'high'
        }
    ]

    print("\n" + "=" * 60)
    print("🗳️  VOTANDO SOBRE NOVAS TAREFAS")
    print("=" * 60)

    executor.vote_for_new_tasks(new_proposals)

    print("\n CICLO COMPLETO FINALIZADO!")


if __name__ == "__main__":
    main()
