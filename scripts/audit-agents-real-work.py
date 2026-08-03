#!/usr/bin/env python3
"""Fail-closed audit for autonomous-agent routines and repository automations."""
from __future__ import annotations

import json
import os
import re
import subprocess
from dataclasses import asdict, dataclass
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPORT_DIR = ROOT / "artifacts" / "agents-audit"
REPORT_JSON = REPORT_DIR / "report.json"
REPORT_MD = REPORT_DIR / "report.md"
BASE_REF = os.getenv("AUDIT_BASE_REF", "").strip()

EXECUTABLE_SUFFIXES = {
    ".py", ".js", ".ts", ".php", ".sh", ".bash", ".ps1", ".yml", ".yaml", ".json"
}
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
    re.compile(r"evidence", re.I),
    re.compile(r"commit_sha", re.I),
    re.compile(r"pull_request|pr_url", re.I),
    re.compile(r"artifact", re.I),
    re.compile(r"test_(?:report|result)|tests_passed", re.I),
]
DANGEROUS_PATTERNS = [
    ("broad_git_add", "high", re.compile(r"(?m)^\s*git\s+add\s+(?:-A|\.)\s*$", re.I)),
    (
        "protected_or_force_push",
        "high",
        re.compile(
            r"(?m)^\s*git\s+push\b[^\n]*(?:--force(?:-with-lease)?|(?:^|\s)(?:main|master)(?:\s|$))",
            re.I,
        ),
    ),
    (
        "self_auto_merge",
        "critical",
        re.compile(r"(?m)^\s*(?:gh\s+pr\s+merge\b[^\n]*--auto|.*enable_auto_merge\s*\()", re.I),
    ),
    (
        "destructive_reset",
        "critical",
        re.compile(r"(?m)^\s*(?:git\s+reset\s+--hard\b|rm\s+-rf\s+/\s*$)", re.I),
    ),
]
PATH_REFERENCE = re.compile(
    r"(?<![A-Za-z0-9_.-])((?:scripts|\.ai|agents|claude/api/agent)/"
    r"[A-Za-z0-9_./-]+\.(?:py|js|ts|php|sh|bash|ps1))"
)
ACTIVE_EVENT = re.compile(
    r"(?m)^\s{2}(?:push|pull_request|schedule|issues|workflow_run|repository_dispatch|"
    r"workflow_dispatch|workflow_call):"
)
REMOTE_EXEC_MARKERS = (
    "shell=true",
    "subprocess.check_output(",
    "subprocess.run(",
)
AUTH_MARKERS = (
    "authorization",
    "bearer ",
    "x-api-key",
    "hmac",
    "compare_digest",
    "authenticate",
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


def is_executable_candidate(rel: str, path: Path) -> bool:
    """Include known source types, Git hooks and extensionless shebang scripts."""
    if not path.is_file():
        return False
    if path.suffix.lower() in EXECUTABLE_SUFFIXES or rel.startswith(".githooks/"):
        return True
    if path.suffix:
        return False
    try:
        with path.open("rb") as handle:
            return handle.read(2) == b"#!"
    except OSError:
        return False


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
        if is_executable_candidate(rel, path):
            files.append(path)
    return files


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


def add_finding(
    findings: list[Finding],
    *,
    severity: str,
    rule: str,
    rel: str,
    text: str,
    offset: int,
    match_text: str,
    active: bool,
    message: str,
) -> None:
    findings.append(
        Finding(
            severity=severity,
            rule=rule,
            path=rel,
            message=message,
            line=line_for(text, offset),
            active=active,
            fingerprint=fingerprint(rule, rel, match_text),
        )
    )


def audit_text(rel: str, original: str, active: bool) -> list[Finding]:
    text = executable_text(original)
    lowered = text.lower()
    findings: list[Finding] = []
    simulation_hits = [match for pattern in SIMULATION_PATTERNS for match in pattern.finditer(text)]
    completion_hits = [match for pattern in COMPLETION_PATTERNS for match in pattern.finditer(text)]
    evidence_hits = [match for pattern in EVIDENCE_PATTERNS for match in pattern.finditer(text)]

    if simulation_hits and completion_hits:
        first = min(simulation_hits + completion_hits, key=lambda match: match.start())
        add_finding(
            findings,
            severity="critical",
            rule="simulated_completion",
            rel=rel,
            text=text,
            offset=first.start(),
            match_text=first.group(0),
            active=active,
            message="Routine combines simulation signals with successful completion.",
        )

    if completion_hits and not evidence_hits and ("agent" in rel.lower() or "task" in rel.lower()):
        first = min(completion_hits, key=lambda match: match.start())
        add_finding(
            findings,
            severity="high",
            rule="completion_without_evidence",
            rel=rel,
            text=text,
            offset=first.start(),
            match_text=first.group(0),
            active=active,
            message="Routine records completion without commit, PR, tests or artifact evidence.",
        )

    for rule, severity, pattern in DANGEROUS_PATTERNS:
        for match in pattern.finditer(text):
            add_finding(
                findings,
                severity=severity,
                rule=rule,
                rel=rel,
                text=text,
                offset=match.start(),
                match_text=match.group(0),
                active=active,
                message="Automatic operation is incompatible with reviewed, evidence-based execution.",
            )

    is_agent_server = "agent" in rel.lower() and any(marker in lowered for marker in REMOTE_EXEC_MARKERS)
    binds_publicly = "0.0.0.0" in lowered
    has_auth = any(marker in lowered for marker in AUTH_MARKERS)
    if is_agent_server and binds_publicly and not has_auth:
        offset = lowered.find("0.0.0.0")
        add_finding(
            findings,
            severity="critical",
            rule="unauthenticated_remote_execution",
            rel=rel,
            text=text,
            offset=max(offset, 0),
            match_text="0.0.0.0 remote execution without authentication",
            active=active,
            message="Agent server exposes command-capable handlers on a public bind address without authentication.",
        )

    return findings


def valid_baseline_ref() -> str | None:
    if not BASE_REF:
        return None
    result = run_git("rev-parse", "--verify", f"{BASE_REF}^{{commit}}", check=False)
    if result.returncode != 0:
        raise RuntimeError(f"AUDIT_BASE_REF does not resolve to a commit: {BASE_REF}")
    baseline_sha = result.stdout.decode().strip()
    current_sha = run_git("rev-parse", "HEAD").stdout.decode().strip()
    return None if baseline_sha == current_sha else BASE_REF


def baseline_fingerprints(files: list[Path], active: set[str], baseline_ref: str | None) -> set[str]:
    if baseline_ref is None:
        return set()
    fingerprints: set[str] = set()
    for path in files:
        rel = path.relative_to(ROOT).as_posix()
        result = run_git("show", f"{baseline_ref}:{rel}", check=False)
        if result.returncode != 0:
            continue
        text = result.stdout.decode("utf-8", errors="replace")
        fingerprints.update(item.fingerprint for item in audit_text(rel, text, rel in active))
    return fingerprints


def main() -> int:
    files = tracked_files()
    active = active_surfaces(files)
    baseline_ref = valid_baseline_ref()
    baseline = baseline_fingerprints(files, active, baseline_ref)
    current_sha = run_git("rev-parse", "HEAD").stdout.decode().strip()
    scanned_git_hooks = sorted(
        path.relative_to(ROOT).as_posix()
        for path in files
        if path.relative_to(ROOT).as_posix().startswith(".githooks/")
    )
    findings: list[Finding] = []

    for path in files:
        rel = path.relative_to(ROOT).as_posix()
        current = audit_text(rel, path.read_text(encoding="utf-8", errors="replace"), rel in active)
        for finding in current:
            finding.preexisting = finding.fingerprint in baseline
            findings.append(finding)

    order = {"critical": 0, "high": 1, "medium": 2, "low": 3}
    findings.sort(key=lambda item: (order.get(item.severity, 9), item.path, item.line or 0))
    blocking = [item for item in findings if item.severity in {"critical", "high"}]
    active_blocking = [item for item in blocking if item.active]
    new_blocking = [item for item in blocking if not item.preexisting]
    generated_at = datetime.now(timezone.utc).isoformat()
    payload = {
        "generated_at": generated_at,
        "current_sha": current_sha,
        "base_ref": baseline_ref,
        "files_scanned": len(files),
        "scanned_git_hooks": scanned_git_hooks,
        "active_surfaces": sorted(active),
        "finding_count": len(findings),
        "active_blocking": len(active_blocking),
        "new_blocking": len(new_blocking),
        "blocking_finding_count": len(blocking),
        "legacy_debt": sum(item.preexisting and not item.active for item in findings),
        "fail_closed": True,
        "findings": [asdict(item) for item in findings],
    }

    REPORT_DIR.mkdir(parents=True, exist_ok=True)
    REPORT_JSON.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

    lines = [
        "# Deep agent audit",
        "",
        f"- Generated: `{generated_at}`",
        f"- Current SHA: `{current_sha}`",
        f"- Base compared: `{baseline_ref or 'none (current-state fail-closed)'}`",
        f"- Files scanned: **{len(files)}**",
        f"- Git hooks scanned: **{len(scanned_git_hooks)}**",
        f"- Active surfaces: **{len(active)}**",
        f"- Blocking findings: **{len(blocking)}**",
        f"- Active blocking findings: **{len(active_blocking)}**",
        f"- New blocking findings: **{len(new_blocking)}**",
        "",
    ]
    if findings:
        lines.extend(["## Findings", ""])
        for finding in findings:
            location = f"{finding.path}:{finding.line}" if finding.line else finding.path
            scope = "ACTIVE" if finding.active else "UNREFERENCED"
            baseline_scope = "PREEXISTING" if finding.preexisting else "NEW/CURRENT"
            lines.append(
                f"- **{finding.severity.upper()} / {finding.rule} / {scope} / {baseline_scope}** "
                f"`{location}` — {finding.message}"
            )
    else:
        lines.append("No finding detected.")
    REPORT_MD.write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(json.dumps(payload, indent=2, ensure_ascii=False))
    return 1 if blocking else 0


if __name__ == "__main__":
    raise SystemExit(main())
