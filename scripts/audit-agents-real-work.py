#!/usr/bin/env python3
"""Evidence-based audit for autonomous-agent routines.

The audit scans active automation surfaces and the scripts they invoke. Existing
legacy findings from the base branch remain visible as cleanup debt, while any new
critical/high-risk behavior introduced by the current branch blocks CI.
"""
from __future__ import annotations

import json
import re
import subprocess
from dataclasses import asdict, dataclass
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPORT_DIR = ROOT / "artifacts" / "agents-audit"
REPORT_JSON = REPORT_DIR / "report.json"
REPORT_MD = REPORT_DIR / "report.md"
BASE_REF = "origin/main"

EXECUTABLE_SUFFIXES = {".py", ".js", ".ts", ".php", ".sh", ".yml", ".yaml", ".json"}
EXCLUDED_FILES = {"scripts/audit-agents-real-work.py"}
EXCLUDED_PREFIXES = (
    ".git/", "node_modules/", "vendor/", "artifacts/", "storage/", "logs/",
    "docs/", "release-notes/", "tests/", "test/", "playwright-report/",
)

SIMULATION_PATTERNS = [
    re.compile(r"\bsimular(?:cao|ção|\s+processamento|\s+execucao|\s+execução|\s+trabalho)?\b", re.I),
    re.compile(r"\bfake\s+(?:success|execution|result)\b", re.I),
    re.compile(r"\bmock\s+(?:success|execution|result)\b", re.I),
    re.compile(r"sleep\s*\(\s*[12](?:\.0)?\s*\).*simul", re.I | re.S),
]
COMPLETION_PATTERNS = [
    re.compile(r"(?:status|state)[\"']?\s*[:=]\s*[\"'](?:completed|concluido|concluído|success)[\"']", re.I),
    re.compile(r"mark_(?:task_)?complete", re.I),
    re.compile(r"tasks_completed\s*\+=", re.I),
    re.compile(r"success\s*:\s*true", re.I),
]
EVIDENCE_PATTERNS = [
    re.compile(r"evidence", re.I), re.compile(r"commit_sha", re.I),
    re.compile(r"pull_request|pr_url", re.I), re.compile(r"artifact", re.I),
    re.compile(r"test_(?:report|result)|tests_passed", re.I),
]
DANGEROUS_PATTERNS = [
    ("broad_git_add", re.compile(r"(?m)^\s*git\s+add\s+(?:-A|\.)\s*$", re.I)),
    ("protected_or_force_push", re.compile(r"(?m)^\s*git\s+push\b[^\n]*(?:--force(?:-with-lease)?|\s(?:main|master)(?:\s|$))", re.I)),
    ("self_auto_merge", re.compile(r"(?m)^\s*(?:gh\s+pr\s+merge\b[^\n]*--auto|.*enable_auto_merge\s*\()", re.I)),
    ("destructive_reset", re.compile(r"(?m)^\s*(?:git\s+reset\s+--hard\b|rm\s+-rf\s+/\s*$)", re.I)),
]
PATH_REFERENCE = re.compile(r"(?<![A-Za-z0-9_.-])((?:scripts|\.ai|agents|claude/api/agent)/[A-Za-z0-9_./-]+\.(?:py|js|ts|php|sh))")


@dataclass
class Finding:
    severity: str
    rule: str
    path: str
    message: str
    line: int | None = None
    active: bool = False
    preexisting: bool = False
    fingerprint: str = ""


def run_git(*args: str, check: bool = True) -> subprocess.CompletedProcess[bytes]:
    return subprocess.run(["git", *args], cwd=ROOT, check=check, capture_output=True)


def tracked_files() -> list[Path]:
    result = run_git("ls-files", "-z")
    files: list[Path] = []
    for raw in result.stdout.split(b"\0"):
        if not raw:
            continue
        rel = raw.decode("utf-8", errors="replace")
        if rel in EXCLUDED_FILES or rel.startswith(EXCLUDED_PREFIXES):
            continue
        path = ROOT / rel
        if path.suffix.lower() in EXECUTABLE_SUFFIXES and path.is_file():
            files.append(path)
    return files


def base_text(rel: str) -> str | None:
    result = run_git("show", f"{BASE_REF}:{rel}", check=False)
    if result.returncode != 0:
        return None
    return result.stdout.decode("utf-8", errors="replace")


def executable_text(text: str) -> str:
    """Remove prose-only comment lines while preserving line numbering."""
    cleaned: list[str] = []
    for line in text.splitlines(keepends=True):
        stripped = line.lstrip()
        if stripped.startswith("#") or stripped.startswith("//"):
            cleaned.append("\n" if line.endswith("\n") else "")
        else:
            cleaned.append(line)
    return "".join(cleaned)


def line_for(text: str, offset: int) -> int:
    return text.count("\n", 0, offset) + 1


def workflow_is_active(text: str) -> bool:
    if not re.search(r"(?m)^on:\s*(?:$|\{)", text):
        return False
    events = set(re.findall(r"(?m)^\s{2}(push|pull_request|schedule|issues|workflow_run|repository_dispatch):", text))
    return bool(events)


def active_surfaces(files: list[Path]) -> set[str]:
    active: set[str] = set()
    for path in files:
        rel = path.relative_to(ROOT).as_posix()
        if not rel.startswith(".github/workflows/"):
            continue
        text = path.read_text(encoding="utf-8", errors="replace")
        if not workflow_is_active(text):
            continue
        active.add(rel)
        for match in PATH_REFERENCE.finditer(text):
            candidate = match.group(1).rstrip("'\"),]")
            if (ROOT / candidate).is_file():
                active.add(candidate)
    return active


def fingerprint(rule: str, rel: str, match_text: str) -> str:
    normalized = re.sub(r"\s+", " ", match_text.strip().lower())
    return f"{rule}|{rel}|{normalized}"


def audit_text(rel: str, original: str, active: bool) -> list[Finding]:
    text = executable_text(original)
    findings: list[Finding] = []
    simulation_hits = [m for p in SIMULATION_PATTERNS for m in p.finditer(text)]
    completion_hits = [m for p in COMPLETION_PATTERNS for m in p.finditer(text)]
    evidence_hits = [m for p in EVIDENCE_PATTERNS for m in p.finditer(text)]

    def severity(blocking: str) -> str:
        return blocking if active else "medium"

    if simulation_hits and completion_hits:
        first = min(simulation_hits + completion_hits, key=lambda m: m.start())
        fp = fingerprint("simulated_completion", rel, first.group(0))
        findings.append(Finding(severity("critical"), "simulated_completion", rel,
            "Rotina combina sinais de simulação com registro de conclusão/sucesso.",
            line_for(text, first.start()), active, False, fp))

    if completion_hits and not evidence_hits and ("agent" in rel.lower() or "task" in rel.lower()):
        first = min(completion_hits, key=lambda m: m.start())
        fp = fingerprint("completion_without_evidence", rel, first.group(0))
        findings.append(Finding(severity("high"), "completion_without_evidence", rel,
            "Rotina registra conclusão sem exigir commit, PR, testes ou artefato.",
            line_for(text, first.start()), active, False, fp))

    for rule, pattern in DANGEROUS_PATTERNS:
        for match in pattern.finditer(text):
            blocking = "critical" if rule in {"self_auto_merge", "destructive_reset"} else "high"
            fp = fingerprint(rule, rel, match.group(0))
            findings.append(Finding(severity(blocking), rule, rel,
                "Operação automática incompatível com revisão humana e evidência auditável.",
                line_for(text, match.start()), active, False, fp))
    return findings


def baseline_fingerprints(files: list[Path], active: set[str]) -> set[str]:
    fingerprints: set[str] = set()
    for path in files:
        rel = path.relative_to(ROOT).as_posix()
        text = base_text(rel)
        if text is None:
            continue
        for finding in audit_text(rel, text, rel in active):
            fingerprints.add(finding.fingerprint)
    return fingerprints


def main() -> int:
    files = tracked_files()
    active = active_surfaces(files)
    baseline = baseline_fingerprints(files, active)
    findings: list[Finding] = []

    for path in files:
        rel = path.relative_to(ROOT).as_posix()
        current = audit_text(rel, path.read_text(encoding="utf-8", errors="replace"), rel in active)
        for finding in current:
            if finding.active and finding.severity in {"critical", "high"} and finding.fingerprint in baseline:
                finding.preexisting = True
                finding.severity = "medium"
                finding.message += " Achado já existente na branch base; permanece como dívida de saneamento."
            findings.append(finding)

    order = {"critical": 0, "high": 1, "medium": 2, "low": 3}
    findings.sort(key=lambda f: (order.get(f.severity, 9), f.path, f.line or 0))
    generated_at = datetime.now(timezone.utc).isoformat()
    payload = {
        "generated_at": generated_at,
        "base_ref": BASE_REF,
        "files_scanned": len(files),
        "active_surfaces": sorted(active),
        "finding_count": len(findings),
        "critical_new": sum(f.severity == "critical" for f in findings),
        "high_new": sum(f.severity == "high" for f in findings),
        "preexisting_debt": sum(f.preexisting for f in findings),
        "medium": sum(f.severity == "medium" for f in findings),
        "findings": [asdict(f) for f in findings],
    }
    REPORT_DIR.mkdir(parents=True, exist_ok=True)
    REPORT_JSON.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

    lines = [
        "# Auditoria profunda de agentes", "", f"- Gerada em: `{generated_at}`",
        f"- Base comparada: `{BASE_REF}`", f"- Arquivos analisados: **{len(files)}**",
        f"- Superfícies ativas: **{len(active)}**",
        f"- Novos achados críticos: **{payload['critical_new']}**",
        f"- Novos achados altos: **{payload['high_new']}**",
        f"- Dívida preexistente reportada: **{payload['preexisting_debt']}**", "",
    ]
    if findings:
        lines += ["## Achados", ""]
        for finding in findings:
            location = f"{finding.path}:{finding.line}" if finding.line else finding.path
            if finding.preexisting:
                scope = "PREEXISTENTE NA BASE"
            else:
                scope = "ATIVO" if finding.active else "LEGADO/NAO REFERENCIADO"
            lines.append(f"- **{finding.severity.upper()} — {finding.rule} — {scope}** em `{location}`: {finding.message}")
    else:
        lines.append("Nenhum achado detectado.")
    REPORT_MD.write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(json.dumps(payload, indent=2, ensure_ascii=False))
    return 1 if payload["critical_new"] or payload["high_new"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
