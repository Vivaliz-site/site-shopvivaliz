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
ROOT_AUTOMATION_FILES = {"SETUP-COMPLETE.ps1", "RUN-ORCHESTRATOR.ps1", "tasks-queue.json"}
ACTIVE_EVENT = re.compile(
    r"(?m)^\s{2}(?:push|pull_request|pull_request_review|schedule|issues|workflow_run|"
    r"repository_dispatch|workflow_dispatch|workflow_call):"
)
PATH_REFERENCE = re.compile(
    r"(?<![A-Za-z0-9_.-])((?:scripts|agent-bridge|ai-system|agents|api/agent|admin|includes|deploy/systemd)/"
    r"[A-Za-z0-9_./-]+(?:\.(?:py|js|ts|php|sh|bash|ps1|service|timer))?)"
)

EVIDENCE = re.compile(
    r"(?:evidence|commit_sha|pull_request|pr_url|artifact|test_(?:report|result)|"
    r"tests_passed|verification|read[-_ ]?back|run_id)",
    re.I,
)
COMPLETION = re.compile(
    r"(?:status|state)\s*[:=]\s*[\"'](?:completed|success)[\"']|"
    r"[\"']status[\"']\s*:\s*[\"']ok[\"']|"
    r"[\"'](?:success|ok)[\"']\s*:\s*(?:true|True)",
    re.I,
)
SIMULATED_SUCCESS = re.compile(
    r"[\"']simulated[\"']\s*:\s*(?:true|True)|"
    r"(?:status|state)[\"']?\s*[:=]\s*[\"'](?:simulated|mock|fake)[\"']|"
    r"\b(?:fake|mock)\s+(?:success|execution|result)\b",
    re.I,
)
QUEUE_MUTATION = re.compile(
    r"\[\s*[\"']status[\"']\s*\]\s*=\s*[\"'](?:in_progress|processing|completed|done|success)[\"']|"
    r"\[\s*[\"']assigned_to[\"']\s*\]\s*=",
    re.I,
)
PRINTED_EXECUTION = re.compile(r"(?:Executando comando|Executing command|comando de teste)", re.I)
EXECUTION_CALL = re.compile(r"(?:subprocess\.(?:run|Popen|check_output)|run_shell\(|exec\(|shell_exec\(|proc_open\()", re.I)
MASKED_FAILURE = re.compile(
    r"(?m)(?:\|\|\s*(?:true|log\b|echo\b)|continue-on-error\s*:\s*true|^\s*set\s+\+e\s*$)",
    re.I,
)
CHECK_FALSE = re.compile(r"check\s*=\s*False", re.I)
RETURN_CODE_CHECK = re.compile(r"(?:returncode|return_code|exit_code|\$LASTEXITCODE)", re.I)
UNCONDITIONAL_RETURN_ZERO = re.compile(r"(?m)^\s*return\s+0\s*$")

DANGEROUS: tuple[tuple[str, str, re.Pattern[str], str], ...] = (
    ("broad_git_add", "high", re.compile(r"(?m)^\s*git\s+add\s+(?:-A|\.)\s*$", re.I), "Broad staging can publish unrelated changes."),
    (
        "protected_or_force_push", "critical",
        re.compile(r"(?m)^\s*git\s+push\b[^\n]*(?:--force(?:-with-lease)?|(?:HEAD:|refs/heads/)?(?:main|master)(?:\s|$))", re.I),
        "Protected-branch or force push is forbidden.",
    ),
    ("self_auto_merge", "critical", re.compile(r"(?m)^\s*(?:gh\s+pr\s+merge\b[^\n]*--auto|.*enable_auto_merge\s*\()", re.I), "Automation must not merge itself."),
    ("destructive_reset", "critical", re.compile(r"(?m)^\s*(?:git\s+reset\s+--hard\b|git\s+clean\s+-[a-z]*f[a-z]*d|rm\s+-rf\s+/\s*$)", re.I), "Destructive reset or cleanup is forbidden."),
)

WEB_MUTATION_MARKERS = ("raw.githubusercontent.com", "file_put_contents(", "git fetch", "git merge --ff-only", "opcache_reset", "shell_exec(", "exec(")
UNSAFE_PRIVATE_PERMISSION = re.compile(
    r"(?:chmod\s+0?77[07]|\b-m\s+2?770\b|\b-m\s+0?777\b|install\s+-d[^\n]*-g\s+www-data[^\n]*-m\s+2?770)",
    re.I,
)
PRIVATE_WORD = re.compile(r"(?:private|token|secret|credential|\.env)", re.I)
REMOTE_EXEC = ("shell=true", "subprocess.check_output(", "subprocess.run(", "shell_exec(", "exec(")
AUTH = ("authorization", "bearer ", "x-api-key", "hmac", "compare_digest", "authenticate", "admin-guard")


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


def candidate(rel: str, path: Path) -> bool:
    if not path.is_file():
        return False
    if path.suffix.lower() in EXECUTABLE_SUFFIXES or rel.startswith(".githooks/") or rel in ROOT_AUTOMATION_FILES:
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
        if candidate(rel, path):
            files.append(path)
    return files


def code_text(text: str) -> str:
    cleaned: list[str] = []
    for line in text.splitlines(keepends=True):
        stripped = line.lstrip()
        if stripped.startswith("#") or stripped.startswith("//"):
            cleaned.append("\n" if line.endswith("\n") else "")
        else:
            cleaned.append(line)
    return "".join(cleaned)


def line_for(text: str, offset: int) -> int:
    return text.count("\n", 0, max(offset, 0)) + 1


def workflow_active(text: str) -> bool:
    return bool(re.search(r"(?m)^on:\s*(?:$|\{)", text) and ACTIVE_EVENT.search(text))


def active_surfaces(files: list[Path]) -> set[str]:
    active: set[str] = set()
    texts: list[str] = []
    for path in files:
        rel = path.relative_to(ROOT).as_posix()
        text = path.read_text(encoding="utf-8", errors="replace")
        if rel.startswith(".github/workflows/") and workflow_active(text):
            active.add(rel)
            texts.append(text)
        elif rel.startswith("deploy/systemd/") and path.suffix.lower() in {".service", ".timer"}:
            active.add(rel)
            texts.append(text)
    for _ in range(2):
        discovered: list[str] = []
        for text in texts:
            for match in PATH_REFERENCE.finditer(text):
                rel = match.group(1).rstrip("'\"),];")
                path = ROOT / rel
                if path.is_file() and rel not in active:
                    active.add(rel)
                    discovered.append(path.read_text(encoding="utf-8", errors="replace"))
        texts.extend(discovered)
        if not discovered:
            break
    return active


def fp(rule: str, rel: str, excerpt: str) -> str:
    normalized = re.sub(r"\s+", " ", excerpt.strip().lower())
    return f"{rule}|{rel}|{normalized}"


def add(findings: list[Finding], severity: str, rule: str, rel: str, text: str, match: re.Match[str] | None, active: bool, message: str, excerpt: str | None = None) -> None:
    value = excerpt if excerpt is not None else (match.group(0) if match else rule)
    findings.append(Finding(
        severity=severity,
        rule=rule,
        path=rel,
        message=message,
        line=line_for(text, match.start() if match else 0),
        active=active,
        fingerprint=fp(rule, rel, value),
    ))


def automation_path(rel: str) -> bool:
    return rel.startswith(AUTOMATION_PREFIXES) or rel in ROOT_AUTOMATION_FILES


def audit_text(rel: str, original: str, active: bool) -> list[Finding]:
    if not automation_path(rel):
        return []
    text = code_text(original)
    lowered = text.lower()
    findings: list[Finding] = []
    evidence = bool(EVIDENCE.search(text))
    completion = COMPLETION.search(text)
    simulation = SIMULATED_SUCCESS.search(text)
    evidence_sensitive_path = any(
        token in rel.lower()
        for token in ("agent", "task", "audit", "guardian", "orchestrator", "bridge", "worker")
    )

    if simulation and completion:
        add(findings, "critical", "simulated_completion", rel, text, simulation, active, "Simulated state and successful completion coexist.")
    if completion and not evidence and evidence_sensitive_path:
        add(findings, "high", "completion_without_evidence", rel, text, completion, active, "Successful completion lacks commit, test, verification or artifact evidence.")

    for rule, severity, pattern, message in DANGEROUS:
        for match in pattern.finditer(text):
            add(findings, severity, rule, rel, text, match, active, message)

    queue = QUEUE_MUTATION.search(text)
    if queue and not evidence:
        add(findings, "critical", "queue_mutation_without_evidence", rel, text, queue, active, "Queue state or assignment changes before verifiable evidence exists.")

    masked = MASKED_FAILURE.search(text)
    if masked and evidence_sensitive_path and (completion or re.search(r"cycle finished|runtime operacional|setup completo", text, re.I)):
        add(findings, "high", "masked_failure_with_success", rel, text, masked, active, "Failure is ignored while the routine can report success.")

    unchecked = CHECK_FALSE.search(text)
    if unchecked and not RETURN_CODE_CHECK.search(text) and evidence_sensitive_path:
        add(findings, "high", "unchecked_process_returncode", rel, text, unchecked, active, "A subprocess disables automatic checking without inspecting its return code.")

    printed = PRINTED_EXECUTION.search(text)
    if printed and not EXECUTION_CALL.search(text):
        add(findings, "high", "printed_execution_without_execution", rel, text, printed, active, "A command is logged as executing without a command runner or API call.")

    unconditional = UNCONDITIONAL_RETURN_ZERO.search(text)
    if unconditional and any(token in rel.lower() for token in ("guardian", "orchestrator")) and RETURN_CODE_CHECK.search(text):
        add(findings, "high", "unconditional_success_after_failure", rel, text, unconditional, active, "Failure-capable automation returns success unconditionally.")

    if rel.startswith(("admin/", "api/")):
        marker_count = sum(marker in lowered for marker in WEB_MUTATION_MARKERS)
        mutation = (
            "file_put_contents(" in lowered and "raw.githubusercontent.com" in lowered
        ) or (
            ("git fetch" in lowered or "git merge --ff-only" in lowered)
            and ("exec(" in lowered or "shell_exec(" in lowered)
        )
        if mutation and marker_count >= 2:
            add(findings, "critical", "web_triggered_code_mutation", rel, text, None, active, "A web endpoint can download, overwrite or fast-forward executable production code.")

    permission = UNSAFE_PRIVATE_PERMISSION.search(text)
    if permission and PRIVATE_WORD.search(text):
        add(findings, "critical", "unsafe_private_directory_permission", rel, text, permission, active, "Broad write access is granted on private or credential state.")

    is_server = "agent" in rel.lower() and any(marker in lowered for marker in REMOTE_EXEC)
    if is_server and "0.0.0.0" in lowered and not any(marker in lowered for marker in AUTH):
        add(findings, "critical", "unauthenticated_remote_execution", rel, text, None, active, "Command-capable agent server binds publicly without authentication.")

    if rel.startswith("deploy/systemd/") and "execstart=" in lowered:
        marker = next((item for item in ("autonomous-continuous-cycle.py", "agent-operations-worker.py", "scripts/autonomous-executor.py") if item in lowered), None)
        if marker:
            add(findings, "critical", "systemd_runs_retired_executor", rel, text, None, True, "A systemd unit runs a retired or non-evidence-based executor.", marker)
    return findings


def valid_baseline() -> str | None:
    if not BASE_REF:
        return None
    result = run_git("rev-parse", "--verify", f"{BASE_REF}^{{commit}}", check=False)
    if result.returncode != 0:
        raise RuntimeError(f"AUDIT_BASE_REF does not resolve: {BASE_REF}")
    baseline_sha = result.stdout.decode().strip()
    current_sha = run_git("rev-parse", "HEAD").stdout.decode().strip()
    return None if baseline_sha == current_sha else BASE_REF


def baseline_fingerprints(files: list[Path], active: set[str], baseline: str | None) -> set[str]:
    if not baseline:
        return set()
    values: set[str] = set()
    for path in files:
        rel = path.relative_to(ROOT).as_posix()
        result = run_git("show", f"{baseline}:{rel}", check=False)
        if result.returncode == 0:
            values.update(item.fingerprint for item in audit_text(rel, result.stdout.decode("utf-8", errors="replace"), rel in active))
    return values


def main() -> int:
    files = tracked_files()
    active = active_surfaces(files)
    baseline = valid_baseline()
    previous = baseline_fingerprints(files, active, baseline)
    current_sha = run_git("rev-parse", "HEAD").stdout.decode().strip()
    hooks = sorted(path.relative_to(ROOT).as_posix() for path in files if path.relative_to(ROOT).as_posix().startswith(".githooks/"))
    units = sorted(path.relative_to(ROOT).as_posix() for path in files if path.relative_to(ROOT).as_posix().startswith("deploy/systemd/"))

    findings: list[Finding] = []
    for path in files:
        rel = path.relative_to(ROOT).as_posix()
        for finding in audit_text(rel, path.read_text(encoding="utf-8", errors="replace"), rel in active):
            finding.preexisting = finding.fingerprint in previous
            findings.append(finding)
    order = {"critical": 0, "high": 1, "medium": 2, "low": 3}
    findings.sort(key=lambda item: (order.get(item.severity, 9), item.path, item.line or 0, item.rule))
    blocking = [item for item in findings if item.severity in {"critical", "high"}]
    generated = datetime.now(timezone.utc).isoformat()
    payload = {
        "generated_at": generated,
        "current_sha": current_sha,
        "base_ref": baseline,
        "files_scanned": len(files),
        "scanned_git_hooks": hooks,
        "scanned_systemd_units": units,
        "active_surfaces": sorted(active),
        "finding_count": len(findings),
        "active_blocking": sum(item.active for item in blocking),
        "new_blocking": sum(not item.preexisting for item in blocking),
        "blocking_finding_count": len(blocking),
        "legacy_debt": sum(item.preexisting and not item.active for item in findings),
        "fail_closed": True,
        "coverage": {
            "workflows": True, "git_hooks": True, "systemd": True,
            "agent_bridge": True, "ai_system": True, "admin_php": True,
            "root_powershell": True, "queue_mutation": True,
            "masked_failures": True, "private_permissions": True,
        },
        "findings": [asdict(item) for item in findings],
    }
    REPORT_DIR.mkdir(parents=True, exist_ok=True)
    REPORT_JSON.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    lines = [
        "# Deep agent audit", "", f"- Generated: `{generated}`", f"- Current SHA: `{current_sha}`",
        f"- Base: `{baseline or 'current-state'}`", f"- Files scanned: **{len(files)}**",
        f"- Hooks scanned: **{len(hooks)}**", f"- systemd units scanned: **{len(units)}**",
        f"- Blocking findings: **{len(blocking)}**", "",
    ]
    if findings:
        lines.extend(["## Findings", ""])
        for item in findings:
            location = f"{item.path}:{item.line}" if item.line else item.path
            lines.append(f"- **{item.severity.upper()} / {item.rule}** `{location}` — {item.message}")
    else:
        lines.append("No finding detected.")
    REPORT_MD.write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(json.dumps(payload, indent=2, ensure_ascii=False))
    return 1 if blocking else 0


if __name__ == "__main__":
    raise SystemExit(main())
