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


def load_env_files(paths: list[str]) -> None:
    for raw_path in paths:
        path = Path(raw_path)
        if not path.exists():
            continue
        for raw_line in path.read_text(encoding="utf-8").splitlines():
            line = raw_line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, value = line.split("=", 1)
            key = key.strip()
            value = value.strip().strip("\"'")
            if key and key not in os.environ:
                os.environ[key] = value


def env(name: str, default: str = "") -> str:
    return os.getenv(name, default).strip()


def run(cmd: list[str]) -> str:
    result = subprocess.run(cmd, capture_output=True, text=True, check=False)
    return (result.stdout or result.stderr or "").strip()


def fetch_status_json(base_url: str = "https://shopvivaliz.com.br") -> dict:
    import urllib.request
    import json
    try:
        url = f"{base_url}/claude/api/status.php?format=summary"
        with urllib.request.urlopen(url, timeout=10) as r:
            return json.loads(r.read())
    except Exception:
        return {}


def build_report() -> str:
    now = datetime.now(timezone.utc).astimezone()
    lines = [
        "Status Horario - ShopVivaliz",
        "=" * 60,
        f"Horario: {now.isoformat(timespec='seconds')}",
        "",
    ]

    status_data = fetch_status_json()
    if status_data:
        ok = status_data.get("ok", False)
        lines += [
            f"Status do sistema: {'✓ SAUDAVEL' if ok else '✗ DEGRADADO'}",
            f"Uptime: {status_data.get('uptime', '?')}%  |  Streak OK: {status_data.get('streak', '?')} runs",
            f"EHA run: #{status_data.get('eha_run', '?')}  |  Acao: {status_data.get('eha_action', '?')}",
            "",
        ]
    else:
        lines += ["Status do sistema: nao disponivel (API offline?)", ""]

    lines += [
        "Ultimos commits:",
        run(["git", "log", "--oneline", "-n", "5"]),
        "",
        "Arquivos tocados no ultimo commit:",
        run(["git", "diff", "--name-only", "HEAD~1..HEAD"]) or "Sem diff de commit disponivel.",
        "",
        "Dashboard: https://shopvivaliz.com.br/claude/dashboard/",
    ]
    return "\n".join(lines).strip() + "\n"


class SmtpNotConfiguredError(Exception):
    """Raised when SMTP secrets are missing — not a real failure."""


def send_email(subject: str, body: str) -> None:
    smtp_host = env("SMTP_HOST", env("EMAIL_SMTP_HOST", env("MAIL_HOST")))
    smtp_port = int(env("SMTP_PORT", env("EMAIL_SMTP_PORT", env("MAIL_PORT") or "465")))
    smtp_user = env("SMTP_USER", env("EMAIL_USER", env("MAIL_USER")))
    smtp_pass = env("SMTP_PASS", env("EMAIL_PASSWORD", env("MAIL_PASS")))
    email_from = env("EMAIL_FROM", smtp_user)
    email_to = env("EMAIL_TO", "fredmourao@gmail.com")

    if not all([smtp_host, smtp_user, smtp_pass, email_to]):
        raise SmtpNotConfiguredError("SMTP/EMAIL secrets não configurados — impossível enviar email")

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
    load_env_files([".env", ".env.local"])
    report = build_report()
    subject = f"[ShopVivaliz] Status horário - {datetime.now().strftime('%Y-%m-%d %H:%M')}"

    print(report)
    try:
        send_email(subject, report)
        print("Email enviado com sucesso.")
        return 0
    except SmtpNotConfiguredError as exc:
        print(f"[ERRO] {exc}", file=sys.stderr)
        print("[ERRO] Secrets SMTP não configurados — impossível enviar email.", file=sys.stderr)
        return 1
    except Exception as exc:
        print(f"[ERRO] Falha ao enviar email: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
