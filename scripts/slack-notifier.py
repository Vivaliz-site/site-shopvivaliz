#!/usr/bin/env python3
"""
1. Slack/Discord Notifier - Notificações em tempo real
"""
import json
import os
from pathlib import Path
from datetime import datetime

class SlackNotifier:
    def __init__(self):
        self.webhook_url = os.getenv('SLACK_WEBHOOK_URL')
        self.discord_webhook = os.getenv('DISCORD_WEBHOOK_URL')

    def notify_task_completed(self, task_id, agent_name, elapsed_time):
        """Notificar conclusão de tarefa"""
        message = f"""
 **Tarefa Completada!**
- Task: {task_id}
- Agente: {agent_name}
- Tempo: {elapsed_time:.1f}s
- Timestamp: {datetime.now().strftime('%H:%M:%S')}
        """
        self.send_notification(message, "success")

    def notify_error(self, task_id, error_msg):
        """Notificar erro"""
        message = f"""
 **Erro Detectado!**
- Task: {task_id}
- Erro: {error_msg}
- Timestamp: {datetime.now().strftime('%H:%M:%S')}
        """
        self.send_notification(message, "error")

    def notify_budget_warning(self, agent_name, used, limit):
        """Notificar limite de budget"""
        percentage = (used / limit) * 100
        message = f"""
 **Budget Warning!**
- Agente: {agent_name}
- Gasto: ${used:.2f} / ${limit:.2f} ({percentage:.1f}%)
- Ação: Considere pausar ou otimizar
        """
        self.send_notification(message, "warning")

    def send_notification(self, message, level="info"):
        """Enviar notificação para Slack/Discord"""
        print(f"📤 Enviando notificação Slack: {level}")
        print(message)

        # Em produção, enviaria real via webhook
        # Para teste, apenas printa

    def command_handler(self, command):
        """Processar comando via Slack"""
        commands = {
            '/execute-task': 'Executar próxima tarefa agora',
            '/status': 'Ver status do sistema',
            '/metrics': 'Mostrar métricas',
            '/pause': 'Pausar execução',
            '/resume': 'Retomar execução'
        }

        if command in commands:
            print(f" Comando {command}: {commands[command]}")
            return True
        return False
