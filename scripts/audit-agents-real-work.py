#!/usr/bin/env python3
"""Evidence-based audit for autonomous-agent routines.

Current active automation is always fail-closed. Baseline comparison is retained
only to distinguish newly introduced findings from legacy, unreferenced debt; it
must never downgrade an active critical/high finding.
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

EXECUTABLE_SUFFIXES = {".py", ".js", ".ts", ".php", ".sh", ".bash", ".ps1", ".yml", ".yaml", ".json"}
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
PATH_REFERENCE = re.compile(r"(?<![A-Za-z0-9_.-])((?:scripts|\.ai|agents|claude/api/agent)/[A-Za-z0-9_./-]+\.(?:py|js|ts|php|sh|bash|ps1))")
ACTIVE_EVENT = re.compile(
    r"(?m)^\s{2}(?:push|pull_request|schedule|issues|workflow_run|repository_dispatch|workflow_dispatch|workflow_call):"
)


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
    return bool(re.search(r"(?m)^on:\s*(?:$|\{)", text) and ACTIVE_EVENT.search(text))


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
    simulation_hits = [match for pattern in SIMULATION_PATTERNS for match in pattern.finditer(text)]
    completion_hits = [match for pattern in COMPLETION_PATTERNS for match in pattern.finditer(text)]
    evidence_hits = [match for pattern in EVIDENCE_PATTERNS for match in pattern.finditer(text)]

    def severity(blocking: str) -> str:
        return blocking if active else "medium"

    if simulation_hits and completion_hits:
        first = min(simulation_hits + completion_hits, key=lambda match: match.start())
        findings.append(Finding(
            severity("critical"), "simulated_completion", rel,
            "Routine combines simulation signals with successful completion.",
            line_for(text, first.start()), active, False,
            fingerprint("simulated_completion", rel, first.group(0)),
        ))

    if completion_hits and not evidence_hits and ("agent" in rel.lower() or "task" in rel.lower()):
        first = min(completion_hits, key=lambda match: match.start())
        findings.append(Finding(
            severity("high"), "completion_without_evidence", rel,
            "Routine records completion without commit, PR, tests or artifact evidence.",
            line_for(text, first.start()), active, False,
            fingerprint("completion_without_evidence", rel, first.group(0)),
        ))

    for rule, pattern in DANGEROUS_PATTERNS:
        for match in pattern.finditer(text):
            blocking = "critical" if rule in {"self_auto_merge", "destructive_reset"} else "high"
            findings.append(Finding(
                severity(blocking), rule, rel,
                "Automatic operation is incompatible with reviewed, evidence-based execution.",
                line_for(text, match.start()), active, False,
                fingerprint(rule, rel, match.group(0)),
            ))
    return findings


def baseline_fingerprints(files: list[Path], active: set[str]) -> set[str]:
    fingerprints: set[str] = set()
    for path in files:
        rel = path.relative_to(ROOT).as_posix()
        text = base_text(rel)
        if text is None:
            continue
        fingerprints.update(item.fingerprint for item in audit_text(rel, text, rel in active))
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
            if finding.fingerprint in baseline:
                finding.preexisting = True
                # Legacy files not reachable from active workflows remain visible debt.
                # Active risk is never downgraded merely because it already exists on main.
                if not finding.active and finding.severity in {"critical", "high"}:
                    finding.severity = "medium"
                    finding.message += " Existing legacy debt; not referenced by an active workflow."
            findings.append(finding)

    order = {"critical": 0, "high": 1, "medium": 2, "low": 3}
    findings.sort(key=lambda item: (order.get(item.severity, 9), item.path, item.line or 0))
    active_blocking = [
        item for item in findings
        if item.active and item.severity in {"critical", "high"}
    ]
    new_blocking = [
        item for item in findings
        if not item.preexisting and item.severity in {"critical", "high"}
    ]
    blocking_fingerprints = {item.fingerprint for item in [*active_blocking, *new_blocking]}
    generated_at = datetime.now(timezone.utc).isoformat()
    payload = {
        "generated_at": generated_at,
        "base_ref": BASE_REF,
        "files_scanned": len(files),
        "active_surfaces": sorted(active),
        "finding_count": len(findings),
        "active_blocking": len(active_blocking),
        "new_blocking": len(new_blocking),
        "blocking_finding_count": len(blocking_fingerprints),
        "legacy_debt": sum(item.preexisting and not item.active for item in findings),
        "findings": [asdict(item) for item in findings],
    }
    REPORT_DIR.mkdir(parents=True, exist_ok=True)
    REPORT_JSON.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

    lines = [
        "# Deep agent audit", "", f"- Generated: `{generated_at}`",
        f"- Base compared: `{BASE_REF}`", f"- Files scanned: **{len(files)}**",
        f"- Active surfaces: **{len(active)}**",
        f"- Active blocking findings: **{payload['active_blocking']}**",
        f"- New blocking findings: **{payload['new_blocking']}**",
        f"- Legacy unreferenced debt: **{payload['legacy_debt']}**", "",
    ]
    if findings:
        lines.extend(["## Findings", ""])
        for finding in findings:
            location = f"{finding.path}:{finding.line}" if finding.line else finding.path
            scope = "ACTIVE" if finding.active else "LEGACY/UNREFERENCED"
            baseline_scope = "PREEXISTING" if finding.preexisting else "NEW"
            lines.append(
                f"- **{finding.severity.upper()} / {finding.rule} / {scope} / {baseline_scope}** "
                f"`{location}` — {finding.message}"
            )
    else:
        lines.append("No finding detected.")
    REPORT_MD.write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(json.dumps(payload, indent=2, ensure_ascii=False))
    return 1 if payload["blocking_finding_count"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
