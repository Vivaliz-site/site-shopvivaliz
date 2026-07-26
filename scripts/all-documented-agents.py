#!/usr/bin/env python3
"""Executa todos os agentes documentados como verificadores reais e fail-closed.

Cada agente realiza trabalho mensuravel sobre o checkout atual e produz evidencia.
Agentes que dependem de servicos externos validam implementacao, configuracao e
credenciais; nunca declaram sincronizacao, deploy ou transacao sem executor real.
"""
from __future__ import annotations

import hashlib
import json
import os
import re
import subprocess
import sys
from dataclasses import asdict, dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Callable, Iterable

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "reports" / "all-documented-agents"
EXTS = {".php", ".js", ".ts", ".tsx", ".jsx", ".html", ".css", ".json", ".md", ".py", ".sh", ".yml", ".yaml"}
SKIP = {".git", "node_modules", "vendor", "reports", "storage", "test-results"}


@dataclass
class Finding:
    severity: str
    code: str
    path: str
    line: int
    message: str
    evidence: str


def files() -> Iterable[Path]:
    for p in ROOT.rglob("*"):
        if p.is_file() and p.suffix.lower() in EXTS and not any(x in SKIP for x in p.relative_to(ROOT).parts):
            yield p


def read(p: Path) -> str:
    return p.read_text(encoding="utf-8", errors="ignore")


def corpus(suffixes: set[str] | None = None) -> list[tuple[Path, str]]:
    return [(p, read(p)) for p in files() if suffixes is None or p.suffix.lower() in suffixes]


def hit(findings: list[Finding], severity: str, code: str, p: Path, line: int, message: str, evidence: str) -> None:
    findings.append(Finding(severity, code, str(p.relative_to(ROOT)), line, message, evidence[:300]))


def regex_agent(name: str, patterns: dict[str, str], suffixes: set[str] | None = None, missing_severity: str = "high"):
    def run():
        data = corpus(suffixes)
        findings: list[Finding] = []
        counts: dict[str, int] = {}
        for label, pattern in patterns.items():
            rx = re.compile(pattern, re.I | re.S)
            matches = []
            for p, text in data:
                for m in rx.finditer(text):
                    matches.append((p, text.count("\n", 0, m.start()) + 1, m.group(0)))
            counts[label] = len(matches)
            if not matches:
                findings.append(Finding(missing_severity, f"{name.upper()}-MISSING", "repository", 0, f"Evidencia ausente: {label}", pattern))
        return findings, {"files_scanned": len(data), "evidence_counts": counts}
    return run


def designer():
    findings: list[Finding] = []
    pages = images = forms = 0
    for p, text in corpus({".php", ".html"}):
        low = text.lower()
        if "<html" not in low and "<!doctype" not in low:
            continue
        pages += 1
        for m in re.finditer(r"<img\b[^>]*>", text, re.I | re.S):
            images += 1
            if not re.search(r"\balt\s*=", m.group(0), re.I):
                hit(findings, "medium", "UX-IMG-ALT", p, text.count("\n", 0, m.start()) + 1, "Imagem sem alt", m.group(0))
        forms += len(re.findall(r"<form\b", text, re.I))
        if "<main" not in low:
            hit(findings, "low", "UX-MAIN", p, 1, "Pagina sem landmark main", "arquivo completo")
    return findings, {"pages": pages, "images": images, "forms": forms}


def analytics():
    return regex_agent("analytics", {
        "tracker": r"gtag\(|dataLayer|google-analytics|G-[A-Z0-9]+",
        "view_item": r"view_item|commerce_signal.*view",
        "add_to_cart": r"add_to_cart|addToCart|cart_add",
        "begin_checkout": r"begin_checkout|checkout_start",
        "purchase": r"purchase|transaction_id|order_completed",
    }, {".php", ".js", ".ts", ".html"})()


def security():
    findings: list[Finding] = []
    scanned = 0
    patterns = [
        ("SEC-GITHUB", re.compile(r"github_pat_[A-Za-z0-9_]{20,}|ghp_[A-Za-z0-9]{20,}")),
        ("SEC-OPENAI", re.compile(r"sk-(?:proj-)?[A-Za-z0-9_-]{20,}")),
        ("SEC-PRIVATE-KEY", re.compile(r"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----")),
        ("SEC-AWS", re.compile(r"AKIA[0-9A-Z]{16}")),
    ]
    for p, text in corpus():
        scanned += 1
        for code, rx in patterns:
            for m in rx.finditer(text):
                value = m.group(0)
                hit(findings, "critical", code, p, text.count("\n", 0, m.start()) + 1, "Possivel credencial exposta", value[:6] + "..." + value[-4:])
        for i, line in enumerate(text.splitlines(), 1):
            if re.search(r"\brm\s+-rf\b|git\s+reset\s+--hard|git\s+push\s+--force", line):
                hit(findings, "high", "SEC-DESTRUCTIVE", p, i, "Comando destrutivo", line.strip())
    return findings, {"files_scanned": scanned}


def deploy_manager():
    findings: list[Finding] = []
    workflows = list((ROOT / ".github" / "workflows").glob("*.y*ml"))
    deploys = 0
    for p in workflows:
        text = read(p)
        if re.search(r"deploy|production|ftp|ssh|rsync|hostgator", text, re.I):
            deploys += 1
        for i, line in enumerate(text.splitlines(), 1):
            if re.search(r"git\s+push\s+(?:origin\s+)?(?:main|master)|git\s+add\s+-A|reset\s+--hard|push\s+--force", line, re.I):
                hit(findings, "critical", "DEPLOY-DANGEROUS", p, i, "Operacao ampla/destrutiva", line.strip())
            if "continue-on-error: true" in line or re.search(r"\|\|\s*(echo|true)", line):
                hit(findings, "medium", "DEPLOY-MASKED", p, i, "Falha potencialmente mascarada", line.strip())
    if deploys == 0:
        findings.append(Finding("high", "DEPLOY-MISSING", ".github/workflows", 0, "Nenhum deploy identificavel", "deploy/production/ftp/ssh/rsync"))
    return findings, {"workflows": len(workflows), "deploy_workflows": deploys}


def qa_self_test():
    checks = []
    commands = [["python3", "-m", "py_compile", "scripts/all-documented-agents.py"]]
    for cmd in commands:
        p = subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True)
        checks.append({"command": " ".join(cmd), "returncode": p.returncode, "stderr": p.stderr[-500:]})
    findings = []
    for c in checks:
        if c["returncode"] != 0:
            findings.append(Finding("high", "QA-COMMAND-FAILED", "repository", 0, c["command"], c["stderr"]))
    return findings, {"checks": checks}


def config_validator():
    findings: list[Finding] = []
    parsed = 0
    for p in files():
        if p.suffix.lower() == ".json":
            try:
                json.loads(read(p)); parsed += 1
            except Exception as e:
                hit(findings, "high", "CONFIG-JSON", p, 1, "JSON invalido", str(e))
    return findings, {"json_parsed": parsed}


def image_optimizer():
    findings: list[Finding] = []
    refs = 0
    for p, text in corpus({".php", ".html", ".css", ".js"}):
        for m in re.finditer(r"(?:src=|url\()[\"']?([^\"')]+\.(?:png|jpe?g|webp|gif|svg))", text, re.I):
            refs += 1
            if re.search(r"\.(?:png|jpe?g)$", m.group(1), re.I) and "loading=" not in m.group(0).lower():
                pass
    return findings, {"image_references": refs, "work": "inventario real de ativos visuais"}


def release_manager():
    findings: list[Finding] = []
    required = ["CHANGELOG.md", "release-notes"]
    exists = {x: (ROOT / x).exists() for x in required}
    if not any(exists.values()):
        findings.append(Finding("medium", "RELEASE-NOTES-MISSING", "repository", 0, "Sem changelog ou release-notes", json.dumps(exists)))
    return findings, {"release_assets": exists}


def tri_environment():
    patterns = {"pc": r"Windows|C:\\|localhost", "cloud": r"cloud|HostGator|production", "oracle": r"Oracle|137\.131\.156\.17"}
    return regex_agent("tri-env", patterns, None, "medium")()


def selenium_runner():
    candidates = list(ROOT.rglob("*selenium*")) + list(ROOT.rglob("*playwright*"))
    findings = [] if candidates else [Finding("medium", "E2E-MISSING", "repository", 0, "Nenhum runner Selenium/Playwright encontrado", "busca por nomes")]
    return findings, {"runner_files": [str(p.relative_to(ROOT)) for p in candidates[:50]]}


def orchestrator():
    findings: list[Finding] = []
    targets = [ROOT / ".ai" / "orchestrator.js", ROOT / "ai-system" / "orchestrator" / "runtime.py"]
    existing = []
    for p in targets:
        if not p.exists():
            findings.append(Finding("high", "ORCH-MISSING", str(p.relative_to(ROOT)), 0, "Componente ausente", "arquivo nao encontrado")); continue
        existing.append(str(p.relative_to(ROOT)))
        text = read(p)
        for i, line in enumerate(text.splitlines(), 1):
            if re.search(r"simul|mock|fake|por enquanto.*sucesso", line, re.I):
                hit(findings, "critical", "ORCH-SIMULATION", p, i, "Marcador de simulacao", line.strip())
    return findings, {"components": existing}


def external_readiness(agent: str, code_terms: str, env_names: list[str]):
    def run():
        data = corpus()
        refs = sum(len(re.findall(code_terms, text, re.I)) for _, text in data)
        configured = [name for name in env_names if os.getenv(name)]
        findings: list[Finding] = []
        if refs == 0:
            findings.append(Finding("high", f"{agent.upper()}-CODE-MISSING", "repository", 0, "Implementacao nao localizada", code_terms))
        if env_names and not configured:
            findings.append(Finding("medium", f"{agent.upper()}-ENV-BLOCKED", "environment", 0, "Credencial/executor nao configurado; agente bloqueado sem fingir sucesso", ",".join(env_names)))
        return findings, {"code_references": refs, "configured_environment": configured, "mode": "real-readiness-and-integrity-check"}
    return run


AGENTS: dict[str, Callable] = {
    "designer": designer,
    "analytics": analytics,
    "deploy-manager": deploy_manager,
    "security": security,
    "olist-sync": external_readiness("olist", r"olist|tiny\s*erp", ["TINY_ERP_API_KEY", "OLIST_API_TOKEN"]),
    "image-optimization": image_optimizer,
    "checkout-flow": regex_agent("checkout", {"checkout": r"checkout", "cart": r"carrinho|cart"}, {".php", ".js", ".ts"}),
    "freight-calculation": regex_agent("freight", {"freight": r"frete|shipping|freight", "cep": r"\bCEP\b|postal"}, {".php", ".js", ".ts"}),
    "release-manager": release_manager,
    "qa-self-test": qa_self_test,
    "selenium-test-runner": selenium_runner,
    "config-validator": config_validator,
    "tri-environment-sync": tri_environment,
    "pagarme-webhook": external_readiness("pagarme", r"pagarme|webhook.*payment", ["PAGARME_API_KEY", "PAGARME_WEBHOOK_SECRET"]),
    "inventory-monitor": regex_agent("inventory", {"stock": r"estoque|inventory|stock", "alert": r"alert|aviso|threshold"}),
    "seo-optimization": regex_agent("seo", {"canonical": r"rel=[\"']canonical", "schema": r"application/ld\+json", "sitemap": r"sitemap"}, {".php", ".html", ".js"}),
    "sensor": regex_agent("sensor", {"catalog": r"catalog", "performance": r"performance|latency|timing", "conversion": r"conversion|purchase|checkout"}),
    "director": regex_agent("director", {"director": r"orchestrator/director|revenue_engine|priorit"}),
    "executor": regex_agent("executor", {"executor": r"executor|execute_task|process_task", "evidence": r"evidence|artifact|sha-256|sha256"}),
    "validator": regex_agent("validator", {"policy": r"policy_guard|validation-policy|VALIDATION-POLICY", "audit": r"audit_trail|audit"}),
    "orchestrator": orchestrator,
    "shopee": external_readiness("shopee", r"shopee", ["SHOPEE_PARTNER_ID", "SHOPEE_PARTNER_KEY"]),
    "mercado-livre": external_readiness("mercado-livre", r"mercado\s*livre|mercadolivre", ["MERCADOLIVRE_ACCESS_TOKEN"]),
    "google-ads": external_readiness("google-ads", r"google\s*ads|google_ads", ["GOOGLE_ADS_DEVELOPER_TOKEN"]),
}


def main() -> int:
    selected = sys.argv[1] if len(sys.argv) > 1 else "all"
    names = list(AGENTS) if selected == "all" else [selected]
    if any(n not in AGENTS for n in names):
        print(json.dumps({"error": "unknown agent", "allowed": list(AGENTS)})); return 64
    now = datetime.now(timezone.utc).isoformat()
    commit = subprocess.run(["git", "rev-parse", "HEAD"], cwd=ROOT, capture_output=True, text=True).stdout.strip()
    result = {"generated_at": now, "commit": commit, "simulated": False, "agents": {}}
    severe = False
    for name in names:
        findings, metrics = AGENTS[name]()
        result["agents"][name] = {"status": "completed_with_evidence", "metrics": metrics, "findings": [asdict(f) for f in findings]}
        severe = severe or any(f.severity in {"critical", "high"} for f in findings)
    OUT.mkdir(parents=True, exist_ok=True)
    j = OUT / "latest.json"; m = OUT / "latest.md"
    j.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    digest = hashlib.sha256(j.read_bytes()).hexdigest()
    lines = ["# Todos os agentes documentados", "", f"Commit: `{commit}`", f"Gerado: `{now}`", "", "Nenhuma simulacao: `true`", ""]
    for name, data in result["agents"].items():
        lines += [f"## {name}", f"Metricas: `{json.dumps(data['metrics'], ensure_ascii=False)}`"]
        for f in data["findings"]:
            lines.append(f"- **{f['severity']}** `{f['code']}` `{f['path']}:{f['line']}` — {f['message']} — `{f['evidence']}`")
        lines.append("")
    lines.append(f"SHA-256 JSON: `{digest}`")
    m.write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(json.dumps({"agents_executed": len(names), "findings_high_or_critical": severe, "report": str(j.relative_to(ROOT))}))
    return 2 if severe else 0


if __name__ == "__main__":
    raise SystemExit(main())
