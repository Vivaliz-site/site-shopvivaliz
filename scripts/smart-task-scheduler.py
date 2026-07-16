#!/usr/bin/env python3
"""
Smart Task Scheduler - Priorização inteligente + Budget control
"""
import json
from pathlib import Path
from datetime import datetime

class SmartTaskScheduler:
    def __init__(self):
        self.queue_file = Path("logs/tasks-queue.json")
        self.api_budget = {
            'Gemini': {'used': 0.0, 'limit': 50.0, 'cost_per_task': 0.50},
            'Claude': {'used': 0.0, 'limit': 100.0, 'cost_per_task': 1.50},
            'ChatGPT': {'used': 0.0, 'limit': 50.0, 'cost_per_task': 0.75}
        }
        self.agent_strengths = {
            'Gemini': ['architecture', 'analysis', 'design'],
            'Claude': ['implementation', 'code', 'php'],
            'ChatGPT': ['validation', 'testing', 'review']
        }

    def load_tasks(self):
        """Carregar tarefas"""
        with open(self.queue_file) as f:
            return json.load(f)

    def assign_best_agent(self, task):
        """Atribuir melhor agente para tarefa"""
        task_desc = (task['title'] + ' ' + task['description']).lower()
        best_agent = None
        max_score = 0

        for agent, strengths in self.agent_strengths.items():
            # Verificar orçamento
            if not self.can_afford(agent):
                print(f"   {agent} sem budget")
                continue

            # Calcular score baseado em força
            score = 0
            for strength in strengths:
                if strength in task_desc:
                    score += 10

            # Bonus por prioridade
            if task['priority'] == 'high':
                score += 5

            if score > max_score:
                max_score = score
                best_agent = agent

        return best_agent or 'Gemini'  # Fallback

    def can_afford(self, agent):
        """Verificar se pode afrodar tarefa com este agente"""
        budget = self.api_budget[agent]
        remaining = budget['limit'] - budget['used']
        task_cost = budget['cost_per_task']

        if remaining < task_cost:
            print(f"   {agent}: ${remaining:.2f} restante < ${task_cost:.2f} necessário")
            return False

        return True

    def get_budget_status(self):
        """Status do budget"""
        print("\n💰 STATUS DE BUDGET:\n")

        for agent, budget in self.api_budget.items():
            used = budget['used']
            limit = budget['limit']
            remaining = limit - used
            percentage = (used / limit) * 100

            if percentage < 50:
                status = "🟢"
            elif percentage < 80:
                status = "🟡"
            else:
                status = "🔴"

            print(f"{status} {agent}")
            print(f"   Gasto: ${used:.2f} / Limite: ${limit:.2f}")
            print(f"   Restante: ${remaining:.2f} ({100-percentage:.1f}%)")
            print()

    def schedule_tasks(self):
        """Agendar tarefas com priorização inteligente"""
        data = self.load_tasks()
        pending = [t for t in data['queue'] if t['status'] == 'pending']

        # Ordenar por prioridade
        priority_order = {'high': 0, 'medium': 1, 'low': 2}
        pending.sort(key=lambda t: priority_order.get(t['priority'], 3))

        print(" AGENDA INTELIGENTE:\n")
        print(f"Total de tarefas pendentes: {len(pending)}\n")

        schedule = []
        for i, task in enumerate(pending[:9], 1):  # Próximas 9 tarefas
            agent = self.assign_best_agent(task)
            cost = self.api_budget[agent]['cost_per_task']

            schedule.append({
                'order': i,
                'task_id': task['id'],
                'title': task['title'],
                'priority': task['priority'],
                'assigned_to': agent,
                'estimated_cost': cost
            })

            print(f"{i}. {task['id']} - {task['title']}")
            print(f"   Prioridade: {task['priority'].upper()}")
            print(f"   Agente: {agent}")
            print(f"   Custo: ${cost:.2f}")
            print()

        return schedule

    def predict_costs(self):
        """Prever custos totais"""
        data = self.load_tasks()
        pending = [t for t in data['queue'] if t['status'] == 'pending']

        total_cost = 0
        for task in pending:
            agent = self.assign_best_agent(task)
            total_cost += self.api_budget[agent]['cost_per_task']

        print(f"\n💵 CUSTO ESTIMADO PARA COMPLETAR TODAS AS TAREFAS:")
        print(f"   ${total_cost:.2f}")

        for agent, budget in self.api_budget.items():
            if total_cost > budget['limit']:
                print(f"    ALERTA: Vai exceder budget!")

        return total_cost

if __name__ == "__main__":
    scheduler = SmartTaskScheduler()

    print("=" * 60)
    print(" SMART TASK SCHEDULER")
    print("=" * 60)

    # Mostrar budget
    scheduler.get_budget_status()

    # Agendar tarefas
    schedule = scheduler.schedule_tasks()

    # Prever custos
    scheduler.predict_costs()
