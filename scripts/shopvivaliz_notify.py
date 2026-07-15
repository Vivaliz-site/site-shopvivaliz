#!/usr/bin/env python3
"""
ShopVivaliz Notificações - Email + GitHub

Sistema de alertas para falhas e eventos importantes.
"""

import os
import smtplib
from email.message import EmailMessage
from typing import Optional
import requests
from datetime import datetime


class Notificator:
    """Sistema de notificações."""

    def __init__(self):
        """Inicializar notificador."""
        self.email_enabled = bool(os.getenv("EMAIL_FROM"))
        self.github_token = os.getenv("GITHUB_TOKEN")
        self.github_repo = "Vivaliz-site/site-shopvivaliz"

    def notify_error(
        self,
        title: str,
        message: str,
        environment: str,
        severity: str = "error",
    ):
        """Notificar sobre erro."""
        print(f"🔴 [{severity.upper()}] {title}")
        print(f"   {message}")
        print(f"   Env: {environment}")

        # GitHub Issue
        if self.github_token:
            self._create_github_issue(
                title=f"🚨 [{environment}] {title}",
                body=f"""
**Severidade:** {severity}
**Ambiente:** {environment}
**Timestamp:** {datetime.now().isoformat()}

## Mensagem
{message}

---
*Criada automaticamente por ShopVivaliz*
""",
                labels=["alert", "auto-generated"]
            )

        # Email
        if self.email_enabled:
            self._send_email(
                subject=f"🚨 ShopVivaliz Alert - {title}",
                body=f"{message}\n\nEnviron: {environment}",
            )

    def notify_success(self, title: str, message: str, environment: str):
        """Notificar sucesso."""
        print(f"✅ {title}")
        print(f"   {message}")
        print(f"   Env: {environment}")

        # GitHub Comment em issue aberta
        if self.github_token:
            self._comment_on_open_issue(
                f"✅ **[{environment}]** {title}\n{message}"
            )

    def notify_sync_completed(self, environment: str, stats: dict):
        """Notificar sync completado."""
        message = f"""
✅ Sync completado em {environment}

**Estatísticas:**
- Status: {stats.get('status', 'unknown')}
- Commits puxados: {stats.get('commits_pulled', 0)}
- Commits feitos push: {stats.get('commits_pushed', 0)}
- Arquivos alterados: {stats.get('files_changed', 0)}
- Tempo: {stats.get('duration', '?')}s
"""
        print(message)

        if self.github_token:
            self._comment_on_open_issue(message)

    def notify_task_status(self, task_id: str, status: str, message: Optional[str] = None):
        """Notificar mudança de status de tarefa."""
        status_emoji = {
            "pending": "⏳",
            "running": "🚀",
            "done": "✅",
            "failed": "❌"
        }.get(status, "❓")

        title = f"{status_emoji} Tarefa {task_id}: {status}"
        print(title)

        if message:
            print(f"   {message}")

        if self.github_token:
            self._create_github_issue(
                title=f"Task: {task_id} [{status}]",
                body=f"**Status:** {status}\n{message or ''}",
                labels=["task", status]
            )

    # ======================================================================
    # IMPLEMENTAÇÕES
    # ======================================================================

    def _send_email(self, subject: str, body: str):
        """Enviar email."""
        try:
            smtp_host = os.getenv("EMAIL_SMTP_HOST", "smtp.gmail.com")
            smtp_port = int(os.getenv("EMAIL_SMTP_PORT", "587"))
            email_from = os.getenv("EMAIL_FROM")
            email_password = os.getenv("EMAIL_PASSWORD")
            email_to = os.getenv("EMAIL_TO", email_from)

            msg = EmailMessage()
            msg["Subject"] = subject
            msg["From"] = email_from
            msg["To"] = email_to
            msg.set_content(body)

            with smtplib.SMTP(smtp_host, smtp_port, timeout=10) as server:
                server.starttls()
                server.login(email_from, email_password)
                server.send_message(msg)

            print(f"📧 Email enviado para {email_to}")

        except Exception as e:
            print(f"⚠️ Erro ao enviar email: {e}")

    def _create_github_issue(self, title: str, body: str, labels: list = None):
        """Criar issue no GitHub."""
        try:
            url = f"https://api.github.com/repos/{self.github_repo}/issues"

            headers = {
                "Authorization": f"token {self.github_token}",
                "Accept": "application/vnd.github.v3+json",
            }

            data = {
                "title": title,
                "body": body,
                "labels": labels or []
            }

            response = requests.post(url, json=data, headers=headers, timeout=10)
            response.raise_for_status()

            issue_number = response.json()["number"]
            print(f"📍 Issue #{ issue_number} criada no GitHub")

        except Exception as e:
            print(f"⚠️ Erro ao criar issue: {e}")

    def _comment_on_open_issue(self, comment: str, labels: list = None):
        """Comentar em uma issue aberta com label 'agentes'."""
        try:
            # Buscar issues abertas com label 'agentes'
            url = f"https://api.github.com/repos/{self.github_repo}/issues"

            headers = {
                "Authorization": f"token {self.github_token}",
                "Accept": "application/vnd.github.v3+json",
            }

            params = {
                "labels": "agentes",
                "state": "open",
                "per_page": 1
            }

            response = requests.get(url, params=params, headers=headers, timeout=10)
            response.raise_for_status()

            issues = response.json()
            if not issues:
                # Nenhuma issue aberta, criar uma
                self._create_github_issue(
                    title="🤖 Agentes - Status",
                    body=comment,
                    labels=["agentes"]
                )
                return

            issue_number = issues[0]["number"]

            # Comentar na issue
            comment_url = f"https://api.github.com/repos/{self.github_repo}/issues/{issue_number}/comments"

            comment_data = {"body": comment}

            response = requests.post(
                comment_url,
                json=comment_data,
                headers=headers,
                timeout=10
            )
            response.raise_for_status()

            print(f"💬 Comentário adicionado à issue #{issue_number}")

        except Exception as e:
            print(f"⚠️ Erro ao comentar: {e}")


# CLI para testar
if __name__ == "__main__":
    notificator = Notificator()

    print("🧪 Testando notificações...\n")

    # Teste 1: Sucesso
    notificator.notify_success(
        "Sync Completado",
        "Repositório sincronizado com sucesso",
        "windows-local"
    )

    print("\n" + "="*50 + "\n")

    # Teste 2: Erro
    notificator.notify_error(
        "Sync Falhou",
        "Erro de autenticação Git",
        "ubuntu-vm",
        severity="error"
    )

    print("\n" + "="*50 + "\n")

    # Teste 3: Task Status
    notificator.notify_task_status(
        "SYNC-001",
        "completed",
        "Sincronização de produtos concluída"
    )
