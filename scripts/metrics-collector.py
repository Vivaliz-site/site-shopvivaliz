#!/usr/bin/env python3
"""
Metrics Collector - Rastrear performance dos agentes
"""
import json
import os
from pathlib import Path
from datetime import datetime

class MetricsCollector:
    def __init__(self):
        self.metrics_file = Path("logs/metrics.jsonl")
        self.metrics_file.parent.mkdir(parents=True, exist_ok=True)

    def log_task_completion(self, agent_name, task_id, elapsed_time, success=True, cost=0.0):
        """Registrar conclusão de tarefa"""
        metric = {
            'timestamp': datetime.now().isoformat(),
            'agent': agent_name,
            'task_id': task_id,
            'elapsed_seconds': elapsed_time,
            'success': success,
            'api_cost': cost,
            'tokens_used': 0
        }

        with open(self.metrics_file, 'a') as f:
            f.write(json.dumps(metric, ensure_ascii=False) + "\n")

    def get_agent_stats(self):
        """Retornar estatísticas por agente"""
        stats = {}

        if not self.metrics_file.exists():
            return stats

        with open(self.metrics_file) as f:
            for line in f:
                metric = json.loads(line)
                agent = metric['agent']

                if agent not in stats:
                    stats[agent] = {
                        'total_tasks': 0,
                        'successful': 0,
                        'failed': 0,
                        'total_time': 0,
                        'total_cost': 0.0,
                        'avg_time': 0
                    }

                stats[agent]['total_tasks'] += 1
                if metric['success']:
                    stats[agent]['successful'] += 1
                else:
                    stats[agent]['failed'] += 1

                stats[agent]['total_time'] += metric['elapsed_seconds']
                stats[agent]['total_cost'] += metric['api_cost']

        # Calcular médias
        for agent in stats:
            if stats[agent]['total_tasks'] > 0:
                stats[agent]['avg_time'] = stats[agent]['total_time'] / stats[agent]['total_tasks']
                stats[agent]['success_rate'] = (stats[agent]['successful'] / stats[agent]['total_tasks']) * 100

        return stats

    def get_budget_usage(self):
        """Retornar custo total por API"""
        budget = {
            'Gemini': {'used': 0.0, 'limit': 50.0},
            'Claude': {'used': 0.0, 'limit': 100.0},
            'ChatGPT': {'used': 0.0, 'limit': 50.0}
        }

        if not self.metrics_file.exists():
            return budget

        with open(self.metrics_file) as f:
            for line in f:
                metric = json.loads(line)
                agent = metric['agent']
                if agent in budget:
                    budget[agent]['used'] += metric['api_cost']

        return budget

    def generate_report(self):
        """Gerar relatório de métricas"""
        stats = self.get_agent_stats()
        budget = self.get_budget_usage()

        report = "#  Relatório de Métricas\n\n"
        report += f"**Gerado:** {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n\n"

        report += "## Performance por Agente\n\n"
        for agent, data in stats.items():
            report += f"### {agent}\n"
            report += f"- Tarefas Completas: {data['successful']}/{data['total_tasks']}\n"
            report += f"- Taxa de Sucesso: {data.get('success_rate', 0):.1f}%\n"
            report += f"- Tempo Médio: {data['avg_time']:.1f}s\n"
            report += f"- Custo Total: ${data['total_cost']:.2f}\n\n"

        report += "## Budget de APIs\n\n"
        for api, data in budget.items():
            used = data['used']
            limit = data['limit']
            percentage = (used / limit) * 100
            status = "🟢" if percentage < 80 else "🟡" if percentage < 95 else "🔴"
            report += f"{status} **{api}**: ${used:.2f} / ${limit:.2f} ({percentage:.1f}%)\n"

        return report

    def save_report(self):
        """Salvar relatório"""
        report_file = Path("metrics-report.md")
        report = self.generate_report()
        report_file.write_text(report)
        print(" Relatório salvo em metrics-report.md")

if __name__ == "__main__":
    collector = MetricsCollector()
    collector.save_report()
