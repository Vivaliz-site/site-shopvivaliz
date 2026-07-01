#!/usr/bin/env python3
"""Envia um status horário do projeto por email usando secrets de SMTP."""
from __future__ import annotations

import os
import smtplib
import subprocess
import sys
from datetime import datetime, timezone
from email.message import EmailMessage
from pathlib import Path


def env(name: str, default: str = "") -> str:
    return os.getenv(name, default).strip()


def run(cmd: list[str]) -> str:
    result = subprocess.run(cmd, capture_output=True, text=True, check=False)
    return (result.stdout or result.stderr or "").strip()


def build_report() -> str:
    now = datetime.now(timezone.utc).astimezone()
    lines = [
        "Status Horario - ShopVivaliz",
        "=" * 60,
        f"Horario: {now.isoformat(timespec='seconds')}",
        "",
        "Resumo do que foi mexido:",
    ]

    status = run(["git", "status", "--short"])
    if status:
        lines.append(status)
    else:
        lines.append("Sem mudancas locais pendentes detectadas.")

    lines.extend([
        "",
        "Ultimos commits:",
        run(["git", "log", "--oneline", "-n", "5"]),
        "",
        "Arquivos principais tocados hoje:",
        run(["git", "diff", "--name-only", "HEAD~1..HEAD"]) or "Sem diff de commit disponivel.",
        "",
        "Proximo foco:",
        "Padronizar pages publicas, reduzir duplicacao e continuar limpando scripts legados.",
    ])
    return "\n".join(lines).strip() + "\n"


def send_email(subject: str, body: str) -> None:
    smtp_host = env("SMTP_HOST", env("EMAIL_SMTP_HOST"))
    smtp_port = int(env("SMTP_PORT", env("EMAIL_SMTP_PORT") or "465"))
    smtp_user = env("SMTP_USER", env("EMAIL_USER"))
    smtp_pass = env("SMTP_PASS", env("EMAIL_PASSWORD"))
    email_from = env("EMAIL_FROM", smtp_user)
    email_to = env("EMAIL_TO")

    if not all([smtp_host, smtp_user, smtp_pass, email_to]):
        raise RuntimeError("SMTP/EMAIL secrets incompletos")

    msg = EmailMessage()
    msg["Subject"] = subject
    msg["From"] = email_from
    msg["To"] = email_to
    msg.set_content(body)

    if smtp_port == 465:
        server = smtplib.SMTP_SSL(smtp_host, smtp_port, timeout=30)
    else:
        server = smtplib.SMTP(smtp_host, smtp_port, timeout=30)

    with server:
        if smtp_port != 465:
            server.starttls()
        server.login(smtp_user, smtp_pass)
        server.send_message(msg)


def main() -> int:
    report = build_report()
    subject = f"[ShopVivaliz] Status horario - {datetime.now().strftime('%Y-%m-%d %H:%M')}"

    print(report)
    try:
        send_email(subject, report)
        print("Email enviado com sucesso.")
        return 0
    except Exception as exc:
        print(f"Falha ao enviar email: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
