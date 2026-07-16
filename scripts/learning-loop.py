#!/usr/bin/env python3
"""
2. Learning Loop - Agentes aprendem com erros
"""
import json
from pathlib import Path
from datetime import datetime

class LearningLoop:
    def __init__(self):
        self.kb_file = Path("logs/agent-knowledge-base.jsonl")
        self.kb_file.parent.mkdir(parents=True, exist_ok=True)
        self.errors_file = Path("logs/errors-and-solutions.jsonl")

    def log_error_and_solution(self, agent_name, task_id, error, solution):
        """Registrar erro e solução para aprender"""
        lesson = {
            'timestamp': datetime.now().isoformat(),
            'agent': agent_name,
            'task_id': task_id,
            'error': error,
            'solution': solution,
            'category': self._categorize_error(error)
        }

        with open(self.errors_file, 'a') as f:
            f.write(json.dumps(lesson, ensure_ascii=False) + "\n")

        print(f"📚 Lição aprendida: {agent_name} - {error[:50]}")

    def _categorize_error(self, error):
        """Categorizar tipo de erro"""
        if 'syntax' in error.lower():
            return 'syntax_error'
        elif 'security' in error.lower():
            return 'security_issue'
        elif 'performance' in error.lower():
            return 'performance'
        elif 'dependency' in error.lower():
            return 'dependency'
        return 'other'

    def get_lessons_for_agent(self, agent_name, category=None):
        """Recuperar lições aprendidas"""
        lessons = []

        if not self.errors_file.exists():
            return lessons

        with open(self.errors_file) as f:
            for line in f:
                lesson = json.loads(line)
                if lesson['agent'] == agent_name:
                    if category is None or lesson['category'] == category:
                        lessons.append(lesson)

        return lessons

    def generate_agent_training(self):
        """Gerar treinamento para agentes"""
        training = {
            'Gemini': {
                'common_errors': [],
                'best_practices': []
            },
            'Claude': {
                'common_errors': [],
                'best_practices': []
            },
            'ChatGPT': {
                'common_errors': [],
                'best_practices': []
            }
        }

        for agent in training.keys():
            lessons = self.get_lessons_for_agent(agent)
            if lessons:
                training[agent]['common_errors'] = [
                    l['error'] for l in lessons[:3]
                ]
                training[agent]['best_practices'] = [
                    l['solution'] for l in lessons[:3]
                ]

        return training

    def should_use_different_approach(self, agent_name, task_type):
        """Decidir se usar abordagem diferente baseado em histórico"""
        lessons = self.get_lessons_for_agent(agent_name, 'syntax_error')

        if len(lessons) > 3:
            print(f" {agent_name} tem histórico de syntax errors - recomendando mudança de abordagem")
            return True

        return False
