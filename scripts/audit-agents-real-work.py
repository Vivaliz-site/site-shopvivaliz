#!/usr/bin/env python3
"""Fail-closed audit for agent routines and repository automation surfaces."""
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
    ".py", ".js", ".ts", ".php", ".sh", ".bash", ".ps1", ".yml", ".yaml",
    ".json", ".service", ".timer",
}
EXCLUDED_FILES = {"scripts/audit-agents-real-work.py"}
EXCLUDED_PREFIXES = (
    ".git/", "node_modules/", "vendor/", "artifacts/", "storage/", "logs/",
    "docs/", "release-notes/", "tests/", "test/", "playwright-report/", "archive/",
)
AUTOMATION_PREFIXES = (
    ".github/workflows/", ".githooks/", "scripts/", ".ai/", "agents/",
    "agent-bridge/", "ai-system/", "deploy/systemd/", "api/agent/", "admin/",
)
ROOT_AUTOMATION_FILES = {
    "SETUP-COMPLETE.ps1", "RUN-ORCHESTRATOR.ps1", "tasks-queue.json",
    "AGENTS.md", "REGRAS-AGENTES-CENTRALIZADAS.md",
}

ACTIVE_EVENT = re.compile(
    r"(?m)^\s{2}(?:push|pull_request|pull_request_review|schedule|issues|workflow_run|"
    r"repository_dispatch|workflow_dispatch|workflow_call):"
)
PATH_REFERENCE = re.compile(
    r"(?<![A-Za-z0-9_.-])((?:scripts|agent-bridge|ai-system|agents|api/agent|admin|includes|deploy/systemd)/"
    r"[A-Za-z0-9_./-]+(?:\.(?:py|js|ts|php|sh|bash|ps1|service|timer))?)"
)

SIMULATED_SUCCESS_PATTERNS = (
    re.compile(r"(?:status|state)[\"']?\s*[:=]\s*[\"'](?:simulated|mock|fake)[\"']", re.I),
    re.compile(r"[\"']simulated[\"']\s*:\s*(?:true|True)", re.I),
    re.compile(r"\bfake\s+(?:success|execution|result)\b", re.I),
    re.compile(r"\bmock\s+(?:success|execution|result)\b", re.I),
    re.compile(r"sleep\s*\(\s*[12](?:\.0)?\s*\).*simul", re.I | re.S),
)
COMPLETION_PATTERNS = (
    re.compile(r"(?:status|state)[\"']?\s*[:=]\s*[\"'](?:completed|concluido|concluído|success|ok)[\"']", re.I),
    re.compile(r"\bok\s*[\"']?\s*:\s*(?:true|True)", re.I),
    re.compile(r"\bsuccess\s*[\"']?\s*:\s*(?:true|True)", re.I),
    re.compile(r"mark_(?:task_)?complete", re.I),
)
EVIDENCE_PATTERNS = (
    re.compile(r"evidence", re.I), re.compile(r"commit_sha", re.I),
    re.compile(r"pull_request|pr_url", re.I), re.compile(r"artifact", re.I),
    re.compile(r"test_(?:report|result)|tests_passed", re.I),
    re.compile(r"verification|read[-_ ]?back", re.I),
)

DANGEROUS_PATTERNS: tuple[tuple[str, str, re.Pattern[str], str], ...] = (
    (
        "broad_git_add", "high",
        re.compile(r"(?m)^\s*git\s+add\s+(?:-A|\.)\s*$", re.I),
        "Broad staging can publish unrelated changes.",
    ),
    (
        "protected_or_force_push", "critical",
        re.compile(
            r"(?m)^\s*git\s+push\b[^\n]*(?:--force(?:-with-lease)?|"
            r"(?:HEAD:|refs/heads/)?(?:main|master)(?:\s|$))", re.I,
        ),
        "A protected-branch or force push is incompatible with reviewed publication.",
    ),
    (
        "self_auto_merge", "critical",
        re.compile(r"(?m)^\s*(?:gh\s+pr\s+merge\b[^\n]*--auto|.*enable_auto_merge\s*\()", re.I),
        "Automation must not enable or perform its own merge.",
    ),
    (
        "destructive_reset", "critical",
        re.compile(r"(?m)^\s*(?:git\s+reset\s+--hard\b|git\s+clean\s+-[a-z]*f[a-z]*d|rm\s+-rf\s+/\s*$)", re.I),
        "Destructive reset or cleanup is forbidden.",
    ),
)

QUEUE_MUTATION = re.compile(
    r"(?:task|tasks|queue|fila)[^\n]{0,80}(?:\[\s*[\"']status[\"']\s*\]\s*=|"
    r"[\"']status[\"']\s*:\s*)[\"'](?:in_progress|processing|completed|done|success)[\"']|"
    r"\[\s*[\"']assigned_to[\"']\s*\]\s*=",
    re.I,
)
MASKED_FAILURE = re.compile(
    r"(?m)(?:\|\|\s*(?:true|log\b|echo\b|Write-Host\b)|check\s*=\s*False|"
    r"continue-on-error\s*:\s*true|@(?:exec|shell_exec|file_get_contents|mkdir)\b)",
    re.I,
)
PRINTED_EXECUTION = re.compile(r"(?:Executando comando|Executing command|comando de teste)", re.I)
EXECUTION_CALL = re.compile(r"(?:subprocess\.(?:run|check_output|Popen)|run_shell\(|exec\(|shell_exec\(|proc_open\()", re.I)
UNCONDITIONAL_SUCCESS_RETURN = re.compile(r"(?m)^\s*return\s+0\s*$")
SUCCESS_TEXT = re.compile(r"(?:cycle finished|runtime operacional|setup completo|status[\"']?\s*[:=]\s*[\"']?ok)", re.I)

WEB_MUTATION_MARKERS = (
    "raw.githubusercontent.com", "file_put_contents(", "git fetch", "git merge --ff-only",
    "opcache_reset", "shell_exec(", "exec(",
)
REMOTE_EXEC_MARKERS = ("shell=true", "subprocess.check_output(", "subprocess.run(", "shell_exec(", "exec(")
AUTH_MARKERS = ("authorization", "bearer ", "x-api-key", "hmac", "compare_digest", "authenticate", "admin-guard")

UNSAFE_PRIVATE_PERMISSION = re.compile(
    r"(?:chmod\s+0?770|chmod\s+0?777|\b-m\s+2?770\b|\b-m\s+0?777\b|"
    r"install\s+-d[^\n]*-g\s+www-data[^\n]*-m\s+2?770)",
    re.I,
)
PRIVATE_PATH_WORD = re.compile(r"(?:private|token|secret|credential|\.env)", re.I)


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
    if not path.is_file():
        return False
    if path.suffix.lower() in EXECUTABLE_SUFFIXES or rel.startswith(".githooks/"):
        return True
    if rel in ROOT_AUTOMATION_FILES:
        return True
    if path.suffix:
        return False
    try:
        return path.open("rb").read(2) == b"#!"
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
    """Remove prose-only line comments while preserving line numbering."""
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
    texts: list[tuple[str, str]] = []
    for path in files:
        rel = path.relative_to(ROOT).as_posix()
        text = path.read_text(encoding="utf-8", errors="replace")
        if rel.startswith(".github/workflows/") and workflow_is_active(text):
            active.add(rel)
            texts.append((rel, text))
        elif rel.startswith("deploy/systemd/") and path.suffix.lower() in {".service", ".timer"}:
            active.add(rel)
            texts.append((rel, text))

    # Resolve direct references from workflows and units, then one additional
    # level from active scripts to include wrappers and canonical entrypoints.
    for _ in range(2):
        discovered: list[tuple[str, str]] = []
        for _, text in texts:
            for match in PATH_REFERENCE.finditer(text):
                candidate = match.group(1).rstrip("'\"),];")
                path = ROOT / candidate
                if path.is_file() and candidate not in active:
                    active.add(candidate)
                    discovered.append((candidate, path.read_text(encoding="utf-8", errors="replace")))
        texts.extend(discovered)
        if not discovered:
            break
    return active


def fingerprint(rule: str, rel: str, match_text: str) -> str:
    normalized = re.sub(r"\s+", " ", match_text.strip().lower())
    return f"{rule}|{rel}|{normalized}"


def add_finding(
    findings: list[Finding], *, severity: str, rule: str, rel: str, text: str,
    offset: int, match_text: str, active: bool, message: str,
) -> None:
    findings.append(Finding(
        severity=severity,
        rule=rule,
        path=rel,
        message=message,
        line=line_for(text, max(offset, 0)),
        active=active,
        fingerprint=fingerprint(rule, rel, match_text),
    ))


def is_automation_path(rel: str) -> bool:
    return rel.startswith(AUTOMATION_PREFIXES) or rel in ROOT_AUTOMATION_FILES


def audit_text(rel: str, original: str, active: bool) -> list[Finding]:
    text = executable_text(original)
    lowered = text.lower()
    findings: list[Finding] = []
    if not is_automation_path(rel):
        return findings

    simulation_hits = [match for pattern in SIMULATED_SUCCESS_PATTERNS for match in pattern.finditer(text)]
    completion_hits = [match for pattern in COMPLETION_PATTERNS for match in pattern.finditer(text)]
    evidence_hits = [match for pattern in EVIDENCE_PATTERNS for match in pattern.finditer(text)]

    if simulation_hits and completion_hits:
        first = min(simulation_hits + completion_hits, key=lambda match: match.start())
        add_finding(
            findings, severity="critical", rule="simulated_completion", rel=rel,
            text=text, offset=first.start(), match_text=first.group(0), active=active,
            message="Routine combines simulated state with successful completion.",
        )

    if completion_hits and not evidence_hits and any(token in rel.lower() for token in ("agent", "task", "audit", "guardian", "orchestrator")):
        first = min(completion_hits, key=lambda match: match.start())
        add_finding(
            findings, severity="high", rule="completion_without_evidence", rel=rel,
            text=text, offset=first.start(), match_text=first.group(0), active=active,
            message="Routine records successful completion without commit, tests, verification or artifact evidence.",
        )

    for rule, severity, pattern, message in DANGEROUS_PATTERNS:
        for match in pattern.finditer(text):
            add_finding(
                findings, severity=severity, rule=rule, rel=rel, text=text,
                offset=match.start(), match_text=match.group(0), active=active, message=message,
            )

    queue_mutation = QUEUE_MUTATION.search(text)
    if queue_mutation and not evidence_hits:
        add_finding(
            findings, severity="critical", rule="queue_mutation_without_evidence", rel=rel,
            text=text, offset=queue_mutation.start(), match_text=queue_mutation.group(0), active=active,
            message="Queue state or assignment changes before verifiable work evidence exists.",
        )

    masked = MASKED_FAILURE.search(text)
    success = SUCCESS_TEXT.search(text) or (completion_hits[0] if completion_hits else None)
    if masked and success and any(token in rel.lower() for token in ("agent", "audit", "guardian", "orchestrator", "workflow")):
        add_finding(
            findings, severity="high", rule="masked_failure_with_success", rel=rel,
            text=text, offset=masked.start(), match_text=masked.group(0), active=active,
            message="A failure is ignored while the same routine can still report success.",
        )

    printed = PRINTED_EXECUTION.search(text)
    if printed and not EXECUTION_CALL.search(text):
        add_finding(
            findings, severity="high", rule="printed_execution_without_execution", rel=rel,
            text=text, offset=printed.start(), match_text=printed.group(0), active=active,
            message="Routine logs a command as executing without invoking a command runner or API.",
        )

    unconditional = UNCONDITIONAL_SUCCESS_RETURN.search(text)
    failure_capable = MASKED_FAILURE.search(text) or "returncode" in lowered or "exit_code" in lowered
    if unconditional and failure_capable and any(token in rel.lower() for token in ("agent", "audit", "guardian", "orchestrator")):
        add_finding(
            findings, severity="high", rule="unconditional_success_after_failure", rel=rel,
            text=text, offset=unconditional.start(), match_text=unconditional.group(0), active=active,
            message="Failure-capable automation returns success unconditionally.",
        )

    if rel.startswith("admin/") or rel.startswith("api/"):
        marker_count = sum(marker in lowered for marker in WEB_MUTATION_MARKERS)
        mutates_code = "file_put_contents(" in lowered and ("raw.githubusercontent.com" in lowered or "git " in lowered)
        mutates_git = ("git fetch" in lowered or "git merge --ff-only" in lowered) and ("exec(" in lowered or "shell_exec(" in lowered)
        if marker_count >= 3 and (mutates_code or mutates_git):
            offset = min((lowered.find(marker) for marker in WEB_MUTATION_MARKERS if marker in lowered), default=0)
            add_finding(
                findings, severity="critical", rule="web_triggered_code_mutation", rel=rel,
                text=text, offset=offset, match_text="web-triggered code mutation", active=active,
                message="Web endpoint can download, overwrite or fast-forward executable production code.",
            )

    permission = UNSAFE_PRIVATE_PERMISSION.search(text)
    if permission and PRIVATE_PATH_WORD.search(text):
        add_finding(
            findings, severity="critical", rule="unsafe_private_directory_permission", rel=rel,
            text=text, offset=permission.start(), match_text=permission.group(0), active=active,
            message="Automation grants broad write access on a path that can contain credentials or private state.",
        )

    is_agent_server = "agent" in rel.lower() and any(marker in lowered for marker in REMOTE_EXEC_MARKERS)
    binds_publicly = "0.0.0.0" in lowered
    has_auth = any(marker in lowered for marker in AUTH_MARKERS)
    if is_agent_server and binds_publicly and not has_auth:
        offset = lowered.find("0.0.0.0")
        add_finding(
            findings, severity="critical", rule="unauthenticated_remote_execution", rel=rel,
            text=text, offset=offset, match_text="0.0.0.0 remote execution without authentication",
            active=active,
            message="Agent server exposes command-capable handlers publicly without authentication.",
        )

    if rel.startswith("deploy/systemd/") and "execstart=" in lowered:
        retired_markers = ("autonomous-continuous-cycle.py", "agent-operations-worker.py", "scripts/autonomous-executor.py")
        marker = next((value for value in retired_markers if value in lowered), None)
        if marker:
            add_finding(
                findings, severity="critical", rule="systemd_runs_retired_executor", rel=rel,
                text=text, offset=lowered.find(marker), match_text=marker, active=True,
                message="A systemd unit directly executes a retired or non-evidence-based agent path.",
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
        old_text = result.stdout.decode("utf-8", errors="replace")
        fingerprints.update(item.fingerprint for item in audit_text(rel, old_text, rel in active))
    return fingerprints


def main() -> int:
    files = tracked_files()
    active = active_surfaces(files)
    baseline_ref = valid_baseline_ref()
    baseline = baseline_fingerprints(files, active, baseline_ref)
    current_sha = run_git("rev-parse", "HEAD").stdout.decode().strip()
    scanned_git_hooks = sorted(
        path.relative_to(ROOT).as_posix() for path in files
        if path.relative_to(ROOT).as_posix().startswith(".githooks/")
    )
    scanned_systemd_units = sorted(
        path.relative_to(ROOT).as_posix() for path in files
        if path.relative_to(ROOT).as_posix().startswith("deploy/systemd/")
    )

    findings: list[Finding] = []
    for path in files:
        rel = path.relative_to(ROOT).as_posix()
        current = audit_text(rel, path.read_text(encoding="utf-8", errors="replace"), rel in active)
        for finding in current:
            finding.preexisting = finding.fingerprint in baseline
            findings.append(finding)

    order = {"critical": 0, "high": 1, "medium": 2, "low": 3}
    findings.sort(key=lambda item: (order.get(item.severity, 9), item.path, item.line or 0, item.rule))
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
        "scanned_systemd_units": scanned_systemd_units,
        "active_surfaces": sorted(active),
        "finding_count": len(findings),
        "active_blocking": len(active_blocking),
        "new_blocking": len(new_blocking),
        "blocking_finding_count": len(blocking),
        "legacy_debt": sum(item.preexisting and not item.active for item in findings),
        "fail_closed": True,
        "coverage": {
            "workflows": True,
            "git_hooks": True,
            "systemd": True,
            "agent_bridge": True,
            "ai_system": True,
            "admin_php": True,
            "root_powershell": True,
            "queue_mutation": True,
            "masked_failures": True,
            "private_permissions": True,
        },
        "findings": [asdict(item) for item in findings],
    }

    REPORT_DIR.mkdir(parents=True, exist_ok=True)
    REPORT_JSON.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    lines = [
        "# Deep agent audit", "", f"- Generated: `{generated_at}`",
        f"- Current SHA: `{current_sha}`",
        f"- Base compared: `{baseline_ref or 'none (current-state fail-closed)'}`",
        f"- Files scanned: **{len(files)}**",
        f"- Git hooks scanned: **{len(scanned_git_hooks)}**",
        f"- systemd units scanned: **{len(scanned_systemd_units)}**",
        f"- Active surfaces: **{len(active)}**",
        f"- Blocking findings: **{len(blocking)}**",
        f"- Active blocking findings: **{len(active_blocking)}**",
        f"- New blocking findings: **{len(new_blocking)}**", "",
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
