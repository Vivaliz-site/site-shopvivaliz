#!/usr/bin/env python3
"""Build and send a periodic autonomous activity email report."""
from __future__ import annotations

import json
import os
import smtplib
import subprocess
import sys
import base64
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timedelta, timezone
from email.message import EmailMessage
from pathlib import Path


DEFAULT_BASE_URL = "https://dev.shopvivaliz.com.br"
DEFAULT_RECIPIENTS = "fredmourao@gmail.com,atendimento@shopvivaliz.com.br"
DEFAULT_REPORT_LOG = "logs/email-activity-report.txt"


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


def env_flag(name: str) -> bool:
    return env(name).lower() in {"1", "true", "yes", "on"}


def report_window_hours() -> int:
    raw = env("REPORT_WINDOW_HOURS", "4")
    try:
        value = int(raw)
    except ValueError:
        return 4
    return value if value > 0 else 4


def run(cmd: list[str]) -> str:
    try:
        result = subprocess.run(cmd, capture_output=True, text=True, check=False)
        return (result.stdout or result.stderr or "").strip()
    except Exception as exc:
        return f"Erro ao rodar comando {cmd}: {exc}"


def http_json(url: str, headers: dict[str, str] | None = None) -> dict | list:
    request = urllib.request.Request(url, headers=headers or {})
    with urllib.request.urlopen(request, timeout=15) as response:
        payload = response.read()
    data = json.loads(payload)
    return data if isinstance(data, (dict, list)) else {}


def fetch_status_json(base_url: str) -> dict:
    url = f"{base_url.rstrip('/')}/api/agent/autonomous-report.php"
    try:
        data = http_json(url, {"Accept": "application/json"})
        return data if isinstance(data, dict) else {}
    except Exception:
        return {}


def fetch_github_runs(hours: int) -> tuple[list[dict], str | None]:
    token = env("GITHUB_TOKEN")
    repo = env("GITHUB_REPOSITORY")
    if not token or not repo or "/" not in repo:
        return [], "GitHub API nao configurada no ambiente atual."

    owner, name = repo.split("/", 1)
    url = (
        f"https://api.github.com/repos/{owner}/{name}/actions/runs"
        f"?per_page=50&exclude_pull_requests=true"
    )
    headers = {
        "Accept": "application/vnd.github+json",
        "Authorization": f"Bearer {token}",
        "X-GitHub-Api-Version": "2022-11-28",
    }

    try:
        data = http_json(url, headers)
    except Exception:
        return [], "Falha ao consultar a API do GitHub."

    if not isinstance(data, dict):
        return [], "Resposta invalida da API do GitHub."

    threshold = datetime.now(timezone.utc) - timedelta(hours=hours)
    runs = []
    for run_item in data.get("workflow_runs", []):
        if not isinstance(run_item, dict):
            continue
        created_at = run_item.get("created_at")
        try:
            created_dt = datetime.fromisoformat(str(created_at).replace("Z", "+00:00"))
        except ValueError:
            continue
        if created_dt < threshold:
            continue
        runs.append(
            {
                "name": str(run_item.get("name", "workflow")),
                "title": str(run_item.get("display_title", "")),
                "status": str(run_item.get("status", "")),
                "conclusion": str(run_item.get("conclusion", "")),
                "created_at": created_dt,
                "html_url": str(run_item.get("html_url", "")),
            }
        )
    return runs, None


def read_json_file(path: str) -> dict:
    file_path = Path(path)
    if not file_path.exists():
        return {}
    try:
        data = json.loads(file_path.read_text(encoding="utf-8"))
        return data if isinstance(data, dict) else {}
    except Exception:
        return {}


def summarize_runs(runs: list[dict], hours: int, note: str | None = None) -> list[str]:
    if note:
        return [note]

    if not runs:
        return [f"Nenhuma execução de workflow encontrada nas últimas {hours} horas."]

    success = sum(1 for item in runs if item["conclusion"] == "success")
    failure = sum(1 for item in runs if item["conclusion"] == "failure")
    in_progress = sum(1 for item in runs if item["status"] != "completed")
    lines = [
        f"Execuções nas últimas {hours} horas: {len(runs)}",
        f"Sucessos: {success} | Falhas: {failure} | Em andamento: {in_progress}",
    ]

    highlighted = runs[:8]
    for item in highlighted:
        when = item["created_at"].astimezone().strftime("%d/%m %H:%M")
        result = item["conclusion"] or item["status"]
        lines.append(f"- {when} | {item['name']} | {result} | {item['title']}")
    return lines


def summarize_status(status_data: dict) -> list[str]:
    if not status_data:
        return ["Relatório autônomo HTTP indisponível no momento."]

    catalog = status_data.get("catalog", {})
    roi = status_data.get("roi", {})
    tri_sync = status_data.get("tri_environment_sync", {})
    sales_flow = status_data.get("sales_flow", {})
    email_report = status_data.get("email_report", {})
    system_health = status_data.get("system_health", {})
    deploy_diagnostic = status_data.get("deploy_diagnostic", {})
    codex_rounds = status_data.get("codex_rounds", {})

    lines = [
        f"Catálogo: {catalog.get('total', 0)} produtos | sem imagem: {catalog.get('no_image', 0)} | preço zero: {catalog.get('zero_price', 0)}",
        f"ROI report disponível: {'sim' if roi.get('available') else 'não'}",
        f"Tri-sync: status={tri_sync.get('status', 'desconhecido')} | branch={tri_sync.get('branch', 'n/a')} | dirty={tri_sync.get('dirty_count', 'n/a')}",
        f"Sales flow pronto agora: {'sim' if sales_flow.get('ready_now') else 'não'}",
        f"Email SMTP configurado: {'sim' if email_report.get('smtp_configured') else 'não'}",
        f"Health check: {system_health.get('status', 'indisponivel')} | erros={system_health.get('errors', 'n/a')} | avisos={system_health.get('warnings', 'n/a')}",
        f"Deploy diagnostic: {'ok' if deploy_diagnostic.get('ok') else 'pendente/indisponivel'}",
        f"Relatorio Codex recente: {'sim' if codex_rounds.get('available') else 'não'}",
    ]

    missing = sales_flow.get("missing_credentials") or []
    if missing:
        lines.append("Credenciais ausentes para fluxo de vendas: " + ", ".join(map(str, missing[:8])))
    return lines


def summarize_health() -> list[str]:
    health = read_json_file("logs/system-health-check.json")
    if not health:
        return ["Sem `logs/system-health-check.json` local para resumir."]

    queue = health.get("checks", {}).get("task_queue", {})
    return [
        f"Status local: {health.get('status', 'UNKNOWN')}",
        f"Fila: total={queue.get('total', 'n/a')} | concluídas={queue.get('completed', 'n/a')} | pendentes={queue.get('pending', 'n/a')}",
        f"Erros locais: {len(health.get('errors', []))} | avisos locais: {len(health.get('warnings', []))}",
    ]


def summarize_git(hours: int) -> list[str]:
    commits = run(["git", "log", f"--since={hours} hours ago", "--oneline", "--no-decorate"])
    if not commits or commits.startswith("Erro ao rodar comando"):
        return [f"Nenhum commit local encontrado nas últimas {hours} horas."]

    lines = [f"Commits locais nas últimas {hours} horas:"]
    for line in commits.splitlines()[:8]:
        lines.append(f"- {line}")
    return lines


def build_report() -> str:
    now = datetime.now(timezone.utc).astimezone()
    hours = report_window_hours()
    status_data = fetch_status_json(env("REPORT_BASE_URL", DEFAULT_BASE_URL))
    workflow_runs, workflow_note = fetch_github_runs(hours)

    sections = [
        "RELATORIO DE ATIVIDADE AUTONOMA",
        f"Gerado em: {now.strftime('%d/%m/%Y %H:%M:%S %Z')}",
        f"Janela analisada: ultimas {hours} horas",
        "",
        "1. STATUS 24/7",
        *summarize_status(status_data),
        "",
        "2. GITHUB ACTIONS RECENTES",
        *summarize_runs(workflow_runs, hours, workflow_note),
        "",
        "3. SAUDE LOCAL DO REPOSITORIO",
        *summarize_health(),
        "",
        "4. COMMITS E MUDANCAS RECENTES",
        *summarize_git(hours),
    ]

    latest_report_path = Path("logs/autonomous-cycle-report.md")
    if latest_report_path.exists():
        latest_excerpt = latest_report_path.read_text(encoding="utf-8").strip().splitlines()[:12]
        if latest_excerpt:
            sections.extend(["", "5. ULTIMO RELATORIO AUTONOMO", *latest_excerpt])

    sections.extend(["", "ShopVivaliz 24/7 - relatorio automatizado por email."])
    return "\n".join(sections).strip() + "\n"


def persist_report(report: str) -> None:
    path = Path(env("REPORT_LOG_PATH", DEFAULT_REPORT_LOG))
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(report, encoding="utf-8")


class SmtpNotConfiguredError(Exception):
    """Raised when SMTP configuration is incomplete."""


def first_non_empty(*values: str) -> str:
    for value in values:
        if value and value.strip():
            return value.strip()
    return ""


def smtp_candidates() -> list[dict[str, Any]]:
    candidates: list[dict[str, Any]] = []
    ports = [
        env("SMTP_PORT"),
        env("EMAIL_SMTP_PORT"),
        env("MAIL_PORT"),
        "465",
    ]
    candidate_rows = [
        {
            "label": "smtp_primary",
            "host": first_non_empty(env("SMTP_HOST"), env("EMAIL_SMTP_HOST"), env("MAIL_HOST")),
            "port": first_non_empty(*ports),
            "user": env("SMTP_USER"),
            "password": env("SMTP_PASS"),
        },
        {
            "label": "email_alias",
            "host": first_non_empty(env("EMAIL_SMTP_HOST"), env("SMTP_HOST"), env("MAIL_HOST")),
            "port": first_non_empty(env("EMAIL_SMTP_PORT"), env("SMTP_PORT"), env("MAIL_PORT"), "465"),
            "user": env("EMAIL_USER"),
            "password": env("EMAIL_PASSWORD"),
        },
        {
            "label": "mail_alias",
            "host": first_non_empty(env("MAIL_HOST"), env("SMTP_HOST"), env("EMAIL_SMTP_HOST")),
            "port": first_non_empty(env("MAIL_PORT"), env("SMTP_PORT"), env("EMAIL_SMTP_PORT"), "465"),
            "user": env("MAIL_USER"),
            "password": env("MAIL_PASS"),
        },
    ]

    seen: set[tuple[str, str, str, str]] = set()
    for row in candidate_rows:
        normalized = (
            row["host"],
            row["port"],
            row["user"],
            row["password"],
        )
        if not all(normalized):
            continue
        if normalized in seen:
            continue
        seen.add(normalized)
        candidates.append(row)
    return candidates


def parse_email_agentes_secret() -> dict[str, str]:
    raw = env("EMAIL_AGENTES_SECRET")
    if not raw:
        return {}

    parsed: dict[str, str] = {}
    candidates = [raw]
    try:
        decoded = urllib.parse.unquote(raw)
        if decoded != raw:
            candidates.append(decoded)
    except Exception:
        pass
    try:
        padded = raw + "=" * (-len(raw) % 4)
        decoded_base64 = base64.b64decode(padded).decode("utf-8", errors="ignore").strip()
        if decoded_base64 and decoded_base64 not in candidates:
            candidates.append(decoded_base64)
    except Exception:
        pass

    for candidate in candidates:
        stripped = candidate.strip()
        if not stripped:
            continue
        if stripped.startswith("{") and stripped.endswith("}"):
            try:
                data = json.loads(stripped)
            except json.JSONDecodeError:
                continue
            if isinstance(data, dict):
                for key, value in data.items():
                    if isinstance(value, str):
                        parsed[str(key)] = value
                return parsed
        if stripped.startswith("re_"):
            return {"provider": "resend", "api_key": stripped}

    return parsed


def secret_diagnostics() -> dict[str, Any]:
    raw = env("EMAIL_AGENTES_SECRET")
    parsed = parse_email_agentes_secret()
    keys = sorted(parsed.keys())
    api_key = first_non_empty(
        parsed.get("api_key", ""),
        parsed.get("resend_api_key", ""),
        parsed.get("token", ""),
    )
    provider = first_non_empty(
        parsed.get("provider", ""),
        "resend" if api_key.startswith("re_") else "",
    )
    return {
        "present": bool(raw),
        "length": len(raw),
        "starts_with_re": raw.startswith("re_"),
        "starts_with_json": raw.lstrip().startswith("{"),
        "looks_urlencoded": "%" in raw,
        "parsed_keys": keys,
        "provider": provider or "unknown",
        "has_api_key": bool(api_key),
        "api_key_prefix": api_key[:3] if api_key else "",
    }


def send_via_resend(subject: str, body: str, recipients: list[str]) -> bool:
    parsed = parse_email_agentes_secret()
    api_key = first_non_empty(
        parsed.get("api_key", ""),
        parsed.get("resend_api_key", ""),
        parsed.get("token", ""),
    )
    provider = first_non_empty(parsed.get("provider", ""), "resend" if api_key.startswith("re_") else "")
    if provider.lower() != "resend" or not api_key:
        return False

    from_email = first_non_empty(
        parsed.get("from", ""),
        parsed.get("from_email", ""),
        env("EMAIL_FROM"),
        "onboarding@resend.dev",
    )
    payload = {
        "from": from_email,
        "to": recipients,
        "subject": subject,
        "text": body,
    }
    request = urllib.request.Request(
        "https://api.resend.com/emails",
        data=json.dumps(payload).encode("utf-8"),
        headers={
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json",
        },
        method="POST",
    )
    with urllib.request.urlopen(request, timeout=30) as response:
        response.read()
    print("Envio concluido via Resend fallback.")
    return True


def send_email(subject: str, body: str) -> None:
    email_to = env("EMAIL_TO", DEFAULT_RECIPIENTS)
    configured_from = env("EMAIL_FROM")

    if not email_to:
        raise SmtpNotConfiguredError(
            "SMTP incompleto: configure host, usuario, senha e destinatarios."
        )

    recipients = [item.strip() for item in email_to.split(",") if item.strip()]
    if not recipients or any("@" not in item or "." not in item for item in recipients):
        raise SmtpNotConfiguredError("Destinatarios de email invalidos em EMAIL_TO.")

    candidates = smtp_candidates()
    if not candidates:
        raise SmtpNotConfiguredError(
            "SMTP incompleto: nenhuma combinacao valida de host, usuario e senha foi encontrada."
        )

    last_error: Exception | None = None
    for candidate in candidates:
        smtp_host = candidate["host"]
        smtp_port = int(candidate["port"])
        smtp_user = candidate["user"]
        smtp_pass = candidate["password"]
        email_from = first_non_empty(configured_from, smtp_user)
        print(
            "Tentando envio SMTP "
            f"perfil={candidate['label']} host={smtp_host} port={smtp_port} "
            f"user_len={len(smtp_user)} pass_len={len(smtp_pass)}"
        )

        msg = EmailMessage()
        msg["Subject"] = subject
        msg["From"] = email_from
        msg["To"] = ", ".join(recipients)
        msg["X-ShopVivaliz-SMTP-Profile"] = candidate["label"]
        msg.set_content(body)

        try:
            if smtp_port == 465:
                server = smtplib.SMTP_SSL(smtp_host, smtp_port, timeout=30)
            else:
                server = smtplib.SMTP(smtp_host, smtp_port, timeout=30)

            with server:
                if smtp_port != 465:
                    server.starttls()
                server.login(smtp_user, smtp_pass)
                server.send_message(msg)
            print(f"Envio SMTP concluido com perfil {candidate['label']}.")
            return
        except Exception as exc:
            print(f"Falha SMTP no perfil {candidate['label']}: {exc}")
            last_error = exc
            continue

    try:
        diagnostics = secret_diagnostics()
        print(f"Diagnostico EMAIL_AGENTES_SECRET: {json.dumps(diagnostics, ensure_ascii=False)}")
        if send_via_resend(subject, body, recipients):
            return
    except Exception as exc:
        print(f"Falha no fallback EMAIL_AGENTES_SECRET: {exc}")
        last_error = exc

    if last_error is not None:
        raise last_error
    raise RuntimeError("Falha desconhecida ao enviar email.")


def main() -> int:
    load_env_files([".env", ".env.local"])
    if env_flag("REPORT_EMAIL_DEBUG_ONLY"):
        print(json.dumps({
            "smtp_candidates": [
                {
                    "label": row["label"],
                    "host": row["host"],
                    "port": row["port"],
                    "user_len": len(row["user"]),
                    "pass_len": len(row["password"]),
                }
                for row in smtp_candidates()
            ],
            "secret_diagnostics": secret_diagnostics(),
        }, ensure_ascii=False, indent=2))
        return 0
    hours = report_window_hours()
    report = build_report()
    subject = (
        f"[ShopVivaliz] Relatorio autonomo {hours}h - "
        f"{datetime.now().strftime('%d/%m/%Y %H:%M')}"
    )

    print(report)
    persist_report(report)
    if env_flag("REPORT_SKIP_EMAIL"):
        print("Envio por email ignorado por REPORT_SKIP_EMAIL.")
        return 0
    try:
        send_email(subject, report)
        print("Relatorio enviado por email com sucesso.")
        return 0
    except SmtpNotConfiguredError as exc:
        print(f"[AVISO] {exc}", file=sys.stderr)
        return 0
    except urllib.error.URLError as exc:
        print(f"Falha ao consultar endpoints remotos: {exc}", file=sys.stderr)
        return 1
    except Exception as exc:
        print(f"Falha ao enviar relatorio: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
