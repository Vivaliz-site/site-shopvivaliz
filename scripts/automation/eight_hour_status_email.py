#!/usr/bin/env python3
"""Envia um relatório de 8 horas do projeto por email usando secrets de SMTP."""
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
    try:
        result = subprocess.run(cmd, capture_output=True, text=True, check=False)
        return (result.stdout or result.stderr or "").strip()
    except Exception as e:
        return f"Erro ao rodar comando {cmd}: {e}"


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
        "============================================================",
        "📊 RELATÓRIO DE 8 HORAS - ATIVIDADE DOS AGENTES",
        "============================================================",
        f"Data/Hora de Geração: {now.strftime('%d/%m/%Y %H:%M:%S %Z')}",
        "",
    ]

    # Status da API
    status_data = fetch_status_json()
    if status_data:
        ok = status_data.get("ok", False)
        lines += [
            "🟢 Status do Sistema:" if ok else "🔴 Status do Sistema: DEGRADADO",
            f"  - Uptime: {status_data.get('uptime', '?')}%",
            f"  - Streak OK: {status_data.get('streak', '?')} runs consecutivas",
            f"  - Última Ação EHA: Run #{status_data.get('eha_run', '?')} ({status_data.get('eha_action', '?')})",
            "",
        ]
    else:
        lines += ["⚪ Status do Sistema: Offline ou Indisponível (Dev/Staging)", ""]

    # Atividade do Git nas últimas 8 horas
    commit_shas = [
        line.strip()
        for line in run(["git", "log", "--since=8 hours ago", "--format=%H"]).splitlines()
        if line.strip()
    ]
    commits_8h = run(["git", "log", "--since=8 hours ago", "--oneline"])
    lines += ["📝 Commits realizados nas últimas 8 horas:"]
    if commits_8h:
        lines += [f"  {line}" for line in commits_8h.splitlines()]
    else:
        lines += ["  Nenhum commit detectado nas últimas 8 horas (agentes ociosos ou em standby)."]
    lines += [""]

    # Arquivos alterados pelos commits das últimas 8 horas (via SHAs de git log + git show)
    if commit_shas:
        changed_files = set()
        for sha in commit_shas:
            out = run(["git", "show", "--name-only", "--format=", sha])
            changed_files.update(f.strip() for f in out.splitlines() if f.strip())
        files_8h = "\n".join(sorted(changed_files))
    else:
        # Fallback: sem commits nas últimas 8h, mostra o último commit existente
        files_8h = run(["git", "diff", "--name-only", "HEAD~1..HEAD"])

    lines += ["📂 Arquivos modificados recentemente:"]
    if files_8h:
        lines += [f"  - {line}" for line in files_8h.splitlines()]
    else:
        lines += ["  Nenhuma modificação de arquivo recente."]
    lines += [""]

    # Último Relatório do Watchdog
    latest_report_path = Path("reports/hourly/latest.md")
    if latest_report_path.exists():
        lines += ["📋 Resumo do Watchdog Autônomo (latest.md):", ""]
        lines += [latest_report_path.read_text(encoding="utf-8").strip()]
        lines += [""]

    lines += [
        "---",
        "🤖 ShopVivaliz 24/7 Autônomo",
        "Monitoramento, CRO e QA Integrados."
    ]
    
    return "\n".join(lines).strip() + "\n"


class SmtpNotConfiguredError(Exception):
    """Raised when SMTP secrets are missing."""


def send_email(subject: str, body: str) -> None:
    smtp_host = env("SMTP_HOST", env("EMAIL_SMTP_HOST"))
    smtp_port = int(env("SMTP_PORT", env("EMAIL_SMTP_PORT") or "465"))
    smtp_user = env("SMTP_USER", env("EMAIL_USER"))
    smtp_pass = env("SMTP_PASS", env("EMAIL_PASSWORD"))
    email_from = env("EMAIL_FROM", smtp_user)
    email_to = env("EMAIL_TO", "fredmourao@gmail.com")

    missing = [
        name
        for name, value in [
            ("SMTP_HOST/EMAIL_SMTP_HOST", smtp_host),
            ("SMTP_USER/EMAIL_USER", smtp_user),
            ("SMTP_PASS/EMAIL_PASSWORD", smtp_pass),
            ("EMAIL_TO", email_to),
        ]
        if not value
    ]
    if missing:
        raise SmtpNotConfiguredError(f"Secrets SMTP ausentes: {', '.join(missing)}")

    print(f"Enviando relatório via {smtp_host}:{smtp_port} para {email_to}")

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
    subject = f"[ShopVivaliz] Relatório Autônomo 8h - {datetime.now().strftime('%d/%m/%Y %H:%M')}"

    print(report)
    try:
        send_email(subject, report)
        print("Relatório enviado por email com sucesso.")
        return 0
    except SmtpNotConfiguredError as exc:
        print(f"[ERRO] {exc}", file=sys.stderr)
        return 1
    except Exception as exc:
        print(f"Falha ao enviar relatório: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
