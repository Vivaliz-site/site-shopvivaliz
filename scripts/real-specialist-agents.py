#!/usr/bin/env python3
"""Executa agentes especialistas com trabalho verificavel e gera evidencias JSON/Markdown.

Nao altera fila, nao faz merge, nao faz push e nao marca sucesso sem evidencias.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import subprocess
import sys
from dataclasses import dataclass, asdict
from datetime import datetime, timezone
from pathlib import Path
from typing import Iterable

ROOT = Path(__file__).resolve().parents[1]
REPORT_DIR = ROOT / "reports" / "specialist-agents"
TEXT_EXTENSIONS = {".php", ".js", ".ts", ".tsx", ".jsx", ".html", ".css", ".yml", ".yaml", ".json", ".md", ".py", ".sh"}
SKIP_PARTS = {".git", "node_modules", "vendor", "reports", "test-results", "storage"}


@dataclass
class Finding:
    severity: str
    code: str
    path: str
    line: int
    message: str
    evidence: str


def files() -> Iterable[Path]:
    for path in ROOT.rglob("*"):
        if not path.is_file() or path.suffix.lower() not in TEXT_EXTENSIONS:
            continue
        if any(part in SKIP_PARTS for part in path.relative_to(ROOT).parts):
            continue
        yield path


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="ignore")


def add(findings: list[Finding], severity: str, code: str, path: Path, line: int, message: str, evidence: str) -> None:
    findings.append(Finding(severity, code, str(path.relative_to(ROOT)), line, message, evidence[:300]))


def designer_agent() -> tuple[list[Finding], dict]:
    findings: list[Finding] = []
    pages = 0
    images = 0
    forms = 0
    for path in files():
        if path.suffix.lower() not in {".php", ".html"}:
            continue
        text = read(path)
        if "<html" not in text.lower() and "<!doctype" not in text.lower():
            continue
        pages += 1
        for match in re.finditer(r"<img\b[^>]*>", text, re.I | re.S):
            images += 1
            tag = match.group(0)
            if not re.search(r"\balt\s*=", tag, re.I):
                add(findings, "medium", "UX-IMG-ALT", path, text.count("\n", 0, match.start()) + 1, "Imagem sem atributo alt", tag)
        for match in re.finditer(r"<form\b[^>]*>", text, re.I):
            forms += 1
        for match in re.finditer(r"<input\b[^>]*>", text, re.I | re.S):
            tag = match.group(0)
            if re.search(r'type\s*=\s*["\'](?:hidden|submit|button)["\']', tag, re.I):
                continue
            if not re.search(r"\b(?:aria-label|aria-labelledby|id)\s*=", tag, re.I):
                add(findings, "low", "UX-INPUT-LABEL", path, text.count("\n", 0, match.start()) + 1, "Campo sem identificador acessivel verificavel", tag)
        if "<main" not in text.lower():
            add(findings, "low", "UX-MAIN", path, 1, "Pagina sem landmark <main>", "arquivo HTML/PHP completo")
    return findings, {"pages_scanned": pages, "images_scanned": images, "forms_scanned": forms}


def analytics_agent() -> tuple[list[Finding], dict]:
    findings: list[Finding] = []
    event_patterns = {
        "view_item": re.compile(r"view_item", re.I),
        "add_to_cart": re.compile(r"add_to_cart|addToCart", re.I),
        "begin_checkout": re.compile(r"begin_checkout", re.I),
        "purchase": re.compile(r"purchase|transaction_id", re.I),
    }
    corpus = []
    scanned = 0
    for path in files():
        if path.suffix.lower() not in {".php", ".js", ".ts", ".html"}:
            continue
        text = read(path)
        scanned += 1
        corpus.append((path, text))
    counts = {name: sum(len(pattern.findall(text)) for _, text in corpus) for name, pattern in event_patterns.items()}
    for name, count in counts.items():
        if count == 0:
            findings.append(Finding("high", "ANALYTICS-EVENT-MISSING", "repository", 0, f"Evento de funil ausente: {name}", "nenhuma ocorrencia encontrada"))
    trackers = sum(len(re.findall(r"gtag\(|dataLayer|google-analytics|G-[A-Z0-9]+", text, re.I)) for _, text in corpus)
    if trackers == 0:
        findings.append(Finding("high", "ANALYTICS-TRACKER-MISSING", "repository", 0, "Nenhuma instrumentacao analitica detectada", "gtag/dataLayer/measurement id ausentes"))
    return findings, {"files_scanned": scanned, "tracker_references": trackers, "funnel_event_references": counts}


def deploy_agent() -> tuple[list[Finding], dict]:
    findings: list[Finding] = []
    workflows = list((ROOT / ".github" / "workflows").glob("*.y*ml"))
    deploy_workflows = []
    for path in workflows:
        text = read(path)
        if re.search(r"deploy|ftp|ssh|rsync|hostgator|production", text, re.I):
            deploy_workflows.append(path)
        for idx, line in enumerate(text.splitlines(), 1):
            if re.search(r"git\s+push\s+(?:origin\s+)?(?:main|master)|git\s+add\s+-A|reset\s+--hard|push\s+--force", line, re.I):
                add(findings, "critical", "DEPLOY-DANGEROUS-GIT", path, idx, "Comando Git amplo/destrutivo em workflow", line.strip())
            if "continue-on-error: true" in line:
                add(findings, "medium", "DEPLOY-MASKED-FAILURE", path, idx, "Falha pode ser mascarada por continue-on-error", line.strip())
            if re.search(r"\|\|\s*(?:echo|true)", line):
                add(findings, "medium", "DEPLOY-MASKED-FAILURE", path, idx, "Falha de comando pode ser convertida em sucesso", line.strip())
    if not deploy_workflows:
        findings.append(Finding("high", "DEPLOY-WORKFLOW-MISSING", ".github/workflows", 0, "Nenhum workflow de deploy identificavel", "busca por deploy/ftp/ssh/rsync/production"))
    return findings, {"workflows_scanned": len(workflows), "deploy_workflows": [str(p.relative_to(ROOT)) for p in deploy_workflows]}


def security_agent() -> tuple[list[Finding], dict]:
    findings: list[Finding] = []
    secret_patterns = [
        ("SEC-GITHUB-TOKEN", re.compile(r"github_pat_[A-Za-z0-9_]{20,}|ghp_[A-Za-z0-9]{20,}")),
        ("SEC-OPENAI-KEY", re.compile(r"sk-(?:proj-)?[A-Za-z0-9_-]{20,}")),
        ("SEC-PRIVATE-KEY", re.compile(r"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----")),
        ("SEC-AWS-KEY", re.compile(r"AKIA[0-9A-Z]{16}")),
    ]
    scanned = 0
    for path in files():
        text = read(path)
        scanned += 1
        for code, pattern in secret_patterns:
            for match in pattern.finditer(text):
                value = match.group(0)
                redacted = value[:6] + "..." + value[-4:]
                add(findings, "critical", code, path, text.count("\n", 0, match.start()) + 1, "Possivel credencial exposta", redacted)
        for idx, line in enumerate(text.splitlines(), 1):
            if re.search(r"\brm\s+-rf\b|git\s+reset\s+--hard|git\s+push\s+--force", line):
                add(findings, "high", "SEC-DESTRUCTIVE-CMD", path, idx, "Comando destrutivo encontrado", line.strip())
    return findings, {"files_scanned": scanned}


AGENTS = {
    "designer": designer_agent,
    "analytics": analytics_agent,
    "deploy": deploy_agent,
    "security": security_agent,
}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--agent", choices=["all", *AGENTS], default="all")
    parser.add_argument("--fail-on", choices=["none", "critical", "high", "medium", "low"], default="high")
    args = parser.parse_args()

    selected = AGENTS.items() if args.agent == "all" else [(args.agent, AGENTS[args.agent])]
    now = datetime.now(timezone.utc).isoformat()
    commit = subprocess.run(["git", "rev-parse", "HEAD"], cwd=ROOT, capture_output=True, text=True).stdout.strip()
    output = {"generated_at": now, "commit": commit, "agents": {}}
    all_findings: list[Finding] = []
    for name, runner in selected:
        findings, metrics = runner()
        all_findings.extend(findings)
        output["agents"][name] = {"metrics": metrics, "findings": [asdict(f) for f in findings]}

    REPORT_DIR.mkdir(parents=True, exist_ok=True)
    json_path = REPORT_DIR / "latest.json"
    md_path = REPORT_DIR / "latest.md"
    json_path.write_text(json.dumps(output, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    lines = ["# Relatorio de agentes especialistas", "", f"- Gerado em: `{now}`", f"- Commit: `{commit}`", ""]
    for name, result in output["agents"].items():
        lines += [f"## {name}", "", f"Metricas: `{json.dumps(result['metrics'], ensure_ascii=False)}`", ""]
        if not result["findings"]:
            lines += ["Nenhum achado.", ""]
        for item in result["findings"]:
            lines += [f"- **{item['severity']}** `{item['code']}` — `{item['path']}:{item['line']}` — {item['message']}", f"  - Evidencia: `{item['evidence']}`"]
        lines.append("")
    digest = hashlib.sha256(json_path.read_bytes()).hexdigest()
    lines += [f"SHA-256 da evidencia JSON: `{digest}`", ""]
    md_path.write_text("\n".join(lines), encoding="utf-8")

    rank = {"none": 99, "critical": 0, "high": 1, "medium": 2, "low": 3}
    finding_rank = {"critical": 0, "high": 1, "medium": 2, "low": 3}
    should_fail = args.fail_on != "none" and any(finding_rank.get(f.severity, 99) <= rank[args.fail_on] for f in all_findings)
    print(json.dumps({"report": str(json_path.relative_to(ROOT)), "findings": len(all_findings), "failed": should_fail}))
    return 2 if should_fail else 0


if __name__ == "__main__":
    sys.exit(main())
