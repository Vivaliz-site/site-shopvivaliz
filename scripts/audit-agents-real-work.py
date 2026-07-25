#!/usr/bin/env python3
"""Evidence-based audit for autonomous-agent routines.

The audit never executes agent tasks and never marks work as completed. It inspects
tracked executable/configuration files and fails when it finds patterns that can
report success without verifiable work, silently mutate the task queue, self-merge,
or push broad changes without review.
"""

from __future__ import annotations

import json
import re
import subprocess
import sys
from dataclasses import asdict, dataclass
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPORT_DIR = ROOT / "artifacts" / "agents-audit"
REPORT_JSON = REPORT_DIR / "report.json"
REPORT_MD = REPORT_DIR / "report.md"

EXECUTABLE_SUFFIXES = {".py", ".js", ".ts", ".php", ".sh", ".yml", ".yaml", ".json"}
EXCLUDED_PREFIXES = (
    ".git/",
    "node_modules/",
    "vendor/",
    "artifacts/",
    "storage/",
    "logs/",
    "docs/",
    "release-notes/",
    "tests/fixtures/",
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
    re.compile(r"evidence", re.I),
    re.compile(r"commit_sha", re.I),
    re.compile(r"pull_request|pr_url", re.I),
    re.compile(r"artifact", re.I),
    re.compile(r"test_(?:report|result)|tests_passed", re.I),
]

DANGEROUS_PATTERNS = [
    ("broad_git_add", re.compile(r"git[\"',\s]+add[\"',\s]+(?:-A|\.)", re.I)),
    ("direct_git_push", re.compile(r"git[\"',\s]+push\b", re.I)),
    ("self_auto_merge", re.compile(r"gh\s+pr\s+merge.*--auto|enable_auto_merge|auto-merge", re.I)),
    ("destructive_reset", re.compile(r"git\s+reset\s+--hard|push\s+--force|rm\s+-rf\s+/", re.I)),
]


@dataclass
class Finding:
    severity: str
    rule: str
    path: str
    message: str
    line: int | None = None


def tracked_files() -> list[Path]:
    result = subprocess.run(
        ["git", "ls-files", "-z"], cwd=ROOT, check=True, capture_output=True
    )
    files: list[Path] = []
    for raw in result.stdout.split(b"\0"):
        if not raw:
            continue
        rel = raw.decode("utf-8", errors="replace")
        if rel.startswith(EXCLUDED_PREFIXES):
            continue
        path = ROOT / rel
        if path.suffix.lower() in EXECUTABLE_SUFFIXES and path.is_file():
            files.append(path)
    return files


def line_for(text: str, offset: int) -> int:
    return text.count("\n", 0, offset) + 1


def audit_file(path: Path) -> list[Finding]:
    rel = path.relative_to(ROOT).as_posix()
    text = path.read_text(encoding="utf-8", errors="replace")
    findings: list[Finding] = []

    simulation_hits = [m for p in SIMULATION_PATTERNS for m in p.finditer(text)]
    completion_hits = [m for p in COMPLETION_PATTERNS for m in p.finditer(text)]
    evidence_hits = [m for p in EVIDENCE_PATTERNS for m in p.finditer(text)]

    if simulation_hits and completion_hits:
        first = min(simulation_hits + completion_hits, key=lambda m: m.start())
        findings.append(
            Finding(
                "critical",
                "simulated_completion",
                rel,
                "Arquivo contém sinais de simulação e também registra conclusão/sucesso.",
                line_for(text, first.start()),
            )
        )

    if completion_hits and not evidence_hits and ("agent" in rel.lower() or "task" in rel.lower()):
        first = min(completion_hits, key=lambda m: m.start())
        findings.append(
            Finding(
                "high",
                "completion_without_evidence",
                rel,
                "Rotina de agente/tarefa registra conclusão sem exigir commit, PR, testes ou artefato.",
                line_for(text, first.start()),
            )
        )

    for rule, pattern in DANGEROUS_PATTERNS:
        for match in pattern.finditer(text):
            severity = "critical" if rule in {"self_auto_merge", "destructive_reset"} else "high"
            findings.append(
                Finding(
                    severity,
                    rule,
                    rel,
                    "Operação automática incompatível com revisão humana e evidência auditável.",
                    line_for(text, match.start()),
                )
            )

    return findings


def main() -> int:
    findings: list[Finding] = []
    files = tracked_files()
    for path in files:
        findings.extend(audit_file(path))

    severity_order = {"critical": 0, "high": 1, "medium": 2, "low": 3}
    findings.sort(key=lambda f: (severity_order.get(f.severity, 9), f.path, f.line or 0))

    generated_at = datetime.now(timezone.utc).isoformat()
    payload = {
        "generated_at": generated_at,
        "files_scanned": len(files),
        "finding_count": len(findings),
        "critical": sum(f.severity == "critical" for f in findings),
        "high": sum(f.severity == "high" for f in findings),
        "findings": [asdict(f) for f in findings],
    }

    REPORT_DIR.mkdir(parents=True, exist_ok=True)
    REPORT_JSON.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

    lines = [
        "# Auditoria profunda de agentes",
        "",
        f"- Gerada em: `{generated_at}`",
        f"- Arquivos analisados: **{len(files)}**",
        f"- Achados críticos: **{payload['critical']}**",
        f"- Achados altos: **{payload['high']}**",
        "",
    ]
    if findings:
        lines.append("## Achados")
        lines.append("")
        for finding in findings:
            location = f"{finding.path}:{finding.line}" if finding.line else finding.path
            lines.append(f"- **{finding.severity.upper()} — {finding.rule}** em `{location}`: {finding.message}")
    else:
        lines.append("Nenhuma rotina capaz de simular conclusão, autoaprovar ou alterar o repositório sem evidência foi detectada.")
    REPORT_MD.write_text("\n".join(lines) + "\n", encoding="utf-8")

    print(json.dumps(payload, indent=2, ensure_ascii=False))
    return 1 if payload["critical"] or payload["high"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
