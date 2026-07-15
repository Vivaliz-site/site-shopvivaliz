#!/usr/bin/env python3
"""
ShopVivaliz Slack Integration - Real-time Alerts

Envia notificacoes para Slack quando algo importante acontece.
"""

import os
import requests
from typing import Optional, Dict, Any
from datetime import datetime

SLACK_WEBHOOK_URL = os.getenv("SLACK_WEBHOOK_URL", "")


class SlackNotifier:
    """Notificador via Slack."""

    @staticmethod
    def send_message(
        text: str,
        title: Optional[str] = None,
        color: str = "#36a64f",
        fields: Optional[Dict[str, str]] = None,
    ) -> bool:
        """Enviar mensagem para Slack."""
        if not SLACK_WEBHOOK_URL:
            return False

        try:
            payload = {
                "attachments": [
                    {
                        "color": color,
                        "title": title or "ShopVivaliz Alert",
                        "text": text,
                        "ts": int(datetime.now().timestamp()),
                    }
                ]
            }

            if fields:
                payload["attachments"][0]["fields"] = [
                    {"title": k, "value": str(v), "short": True}
                    for k, v in fields.items()
                ]

            response = requests.post(SLACK_WEBHOOK_URL, json=payload, timeout=10)
            return response.status_code == 200

        except Exception as e:
            print(f"❌ Erro ao enviar para Slack: {e}")
            return False

    @staticmethod
    def alert_error(
        title: str,
        message: str,
        environment: str = "unknown",
        extra: Optional[Dict] = None,
    ) -> bool:
        """Enviar alerta de erro."""
        fields = {
            "Environment": environment,
            "Timestamp": datetime.now().isoformat(),
        }
        if extra:
            fields.update(extra)

        return SlackNotifier.send_message(
            text=message,
            title=f"🚨 {title}",
            color="#ff0000",
            fields=fields,
        )

    @staticmethod
    def alert_success(
        title: str,
        message: str,
        environment: str = "unknown",
    ) -> bool:
        """Enviar alerta de sucesso."""
        return SlackNotifier.send_message(
            text=message,
            title=f"✅ {title}",
            color="#36a64f",
            fields={"Environment": environment},
        )

    @staticmethod
    def alert_warning(title: str, message: str) -> bool:
        """Enviar alerta de aviso."""
        return SlackNotifier.send_message(
            text=message,
            title=f"⚠️ {title}",
            color="#ff9900",
        )


if __name__ == "__main__":
    print("🧪 Testando Slack...")

    if not SLACK_WEBHOOK_URL:
        print("❌ SLACK_WEBHOOK_URL não configurado")
        print("   Configure em .env: SLACK_WEBHOOK_URL=https://hooks.slack.com/...")
    else:
        # Teste
        SlackNotifier.alert_success(
            "ShopVivaliz Online",
            "Sistema iniciado e pronto para uso",
            "docker",
        )
        print("✅ Mensagem enviada para Slack")
