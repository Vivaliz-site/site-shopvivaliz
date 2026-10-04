#!/usr/bin/env python3
"""Envia um status horário do projeto por email usando secrets de SMTP."""
from __future__ import annotations

import os
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from shopvivaliz_mail import send_text


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


class ProviderNotConfiguredError(Exception):
    """Raised when the ShopVivaliz transactional provider is not configured."""

def send_email(subject: str, body: str) -> None:
    email_to = env("EMAIL_TO", "fredmourao@gmail.com")
    recipients = [item.strip() for item in email_to.split(",") if item.strip()]
    if not recipients:
        raise ProviderNotConfiguredError("EMAIL_TO não configurado")
    result = send_text(
        recipients,
        subject,
        body,
        tags=["shopvivaliz-transactional", "hourly-status"],
    )
    if not result.success:
        if result.error == "provider_not_configured":
            raise ProviderNotConfiguredError("BREVO_API_KEY não configurada")
        raise RuntimeError(f"Falha no provider ShopVivaliz: {result.error}")

def main() -> int:
    load_env_files([".env", ".env.local"])
    report = build_report()
    subject = f"[ShopVivaliz] Status horário - {datetime.now().strftime('%Y-%m-%d %H:%M')}"

    print(report)
    try:
        send_email(subject, report)
        print("Email enviado com sucesso.")
        return 0
    except ProviderNotConfiguredError as exc:
        print(f"[ERRO] {exc}", file=sys.stderr)
        print("[ERRO] Provider Brevo da ShopVivaliz não configurado.", file=sys.stderr)
        return 1
    except Exception as exc:
        print(f"[ERRO] Falha ao enviar email: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
