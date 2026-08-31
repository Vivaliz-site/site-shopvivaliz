#!/usr/bin/env python3
"""Audit every active GitHub Actions workflow for unsafe control flow."""
from __future__ import annotations

import json
import re
from dataclasses import asdict, dataclass
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
WORKFLOWS = ROOT / ".github" / "workflows"
REPORT_DIR = ROOT / "artifacts" / "workflow-policy"


def joined(*parts: str) -> str:
    return "".join(parts)


RULES: tuple[tuple[str, str, re.Pattern[str], str], ...] = (
    ("critical", "auto_merge", re.compile(joined(r"gh\s+pr\s+mer", r"ge\b[^\n]*--auto"), re.I), "Active workflow enables automatic pull-request merge."),
    ("critical", "destructive_git", re.compile(joined(r"git\s+(?:reset\s+--ha", r"rd|clean\s+-[a-z]*f[a-z]*d)"), re.I), "Active workflow contains destructive Git cleanup."),
    ("high", "broad_stage", re.compile(joined(r"(?m)^\s*git\s+ad", r"d\s+(?:-A|\.)\s*$"), re.I), "Active workflow stages unrelated repository changes."),
    ("high", "workflow_push", re.compile(joined(r"(?m)^.*\bgit\s+pu", r"sh\b.*$"), re.I), "Active workflow publishes Git refs directly."),
    ("high", "continue_on_error", re.compile(joined(r"continue-on-", r"error\s*:\s*true"), re.I), "Active workflow suppresses a step failure."),
    ("high", "set_plus_e", re.compile(joined(r"(?m)^\s*set\s+", r"\+e\s*$"), re.I), "Active workflow disables shell fail-fast behavior."),
    ("high", "optional_evidence", re.compile(joined(r"if-no-files-", r"found\s*:\s*warn"), re.I), "Workflow evidence is optional instead of required."),
)

# `set +e` is not failure suppression when a workflow deliberately captures the
# command status, restores fail-fast immediately, creates mandatory evidence and
# exits with exactly the captured status. This form is needed for diagnostics
# that must be uploaded even when the audited command fails.
CAPTURED_STATUS_BLOCK = re.compile(
    r"(?ms)^(?P<indent>[ \t]*)set[ \t]+\+e[ \t]*$\n"
    r"(?P<body>.*?)"
    r"^(?P=indent)(?P<status>[A-Za-z_][A-Za-z0-9_]*)=\$\?[ \t]*$\n"
    r"^(?P=indent)set[ \t]+-e[ \t]*$\n"
    r"(?P<tail>.*?)"
    r"^(?P=indent)exit[ \t]+[\"']?\$\{?(?P=status)\}?[\"']?[ \t]*$"
)

WRITE_PERMISSION = re.compile(r"(?m)^\s{2}(contents|issues|pull-requests|actions)\s*:\s*write\s*$", re.I)
AUTOMATIC_TRIGGERS = {"push", "schedule", "issues", "workflow_run", "repository_dispatch"}
MUTATION = re.compile(joined(r"git\s+pu", r"sh|gh\s+(?:pr\s+merge|issue\s+(?:create|edit|close|comment))"), re.I)
PRODUCTION_NAME = re.compile(r"production|deploy|publish|apply", re.I)
ON_LINE = re.compile(r"(?m)^on:[ \t]*(?P<inline>[^\n#]*)(?:#.*)?$")


@dataclass(frozen=True)
class Finding:
    severity: str
    rule: str
    path: str
    line: int | None
    message: str
    excerpt: str


def line_number(text: str, offset: int) -> int:
    return text.count("\n", 0, offset) + 1


def excerpt(value: str) -> str:
    return " ".join(value.strip().split())[:200]


def flow_mapping_keys(value: str) -> set[str]:
    inner = value.strip()[1:-1]
    items: list[str] = []
    start = 0
    depth = 0
    quote: str | None = None
    escaped = False
    for index, char in enumerate(inner):
        if quote is not None:
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == quote:
                quote = None
            continue
        if char in ("'", '"'):
            quote = char
        elif char in "[{(":
            depth += 1
        elif char in "]})":
            depth = max(0, depth - 1)
        elif char == "," and depth == 0:
            items.append(inner[start:index])
            start = index + 1
    items.append(inner[start:])

    names: set[str] = set()
    for item in items:
        name = item.split(":", 1)[0].strip().strip("'\"")
        if re.fullmatch(r"[A-Za-z0-9_-]+", name):
            names.add(name)
    return names


def workflow_trigger_names(text: str) -> set[str]:
    match = ON_LINE.search(text)
    if not match:
        return set()

    inline = match.group("inline").strip()
    if inline:
        if inline.startswith("{") and inline.endswith("}"):
            return flow_mapping_keys(inline)
        if inline.startswith("[") and inline.endswith("]"):
            values = inline[1:-1].split(",")
        else:
            values = [inline]
        return {value.strip().strip("'\"") for value in values if value.strip()}

    block: list[str] = []
    for line in text[match.end():].splitlines():
        if not line.strip() or line.lstrip().startswith("#"):
            block.append(line)
            continue
        if line == line.lstrip():
            break
        block.append(line)

    indented = [line for line in block if line.strip() and not line.lstrip().startswith("#")]
    if not indented:
        return set()
    min_indent = min(len(line) - len(line.lstrip(" ")) for line in indented)
    event = re.compile(rf"^ {{{min_indent}}}(?P<name>[A-Za-z0-9_-]+)\s*:")
    return {m.group("name") for line in indented if (m := event.match(line))}


def safely_rethrown_set_plus_e_offsets(text: str) -> set[int]:
    offsets: set[int] = set()
    marker = re.compile(r"(?m)^[ \t]*set[ \t]+\+e[ \t]*$")
    for block in CAPTURED_STATUS_BLOCK.finditer(text):
        found = marker.search(block.group(0))
        if found is not None:
            offsets.add(block.start() + found.start())
    return offsets


def audit_workflow(path: Path) -> list[Finding]:
    relative = path.relative_to(ROOT).as_posix()
    text = path.read_text(encoding="utf-8", errors="replace")
    findings: list[Finding] = []
    safe_set_plus_e = safely_rethrown_set_plus_e_offsets(text)

    for severity, rule, pattern, message in RULES:
        for match in pattern.finditer(text):
            if rule == "set_plus_e" and match.start() in safe_set_plus_e:
                continue
            findings.append(Finding(severity, rule, relative, line_number(text, match.start()), message, excerpt(match.group(0))))

    write_permissions = list(WRITE_PERMISSION.finditer(text))
    triggers = workflow_trigger_names(text)
    automatic = bool(triggers & AUTOMATIC_TRIGGERS)
    mutation = MUTATION.search(text)
    if write_permissions and automatic and mutation:
        first = write_permissions[0]
        findings.append(Finding(
            "critical",
            "automatic_write_workflow",
            relative,
            line_number(text, first.start()),
            "Automatically triggered workflow combines write permissions and repository mutation.",
            excerpt(first.group(0)),
        ))

    if PRODUCTION_NAME.search(path.name) and "push" in triggers and "workflow_dispatch" in triggers:
        push_match = re.search(r"(?m)^on:[^\n]*\bpush\b", text) or re.search(r"(?m)^[ \t]+push\s*:", text)
        findings.append(Finding(
            "critical",
            "production_push_trigger",
            relative,
            line_number(text, push_match.start()) if push_match else None,
            "Production-like workflow can run from push instead of explicit manual authorization.",
            "push trigger",
        ))

    return findings


def write_report(files: list[Path], findings: list[Finding]) -> None:
    REPORT_DIR.mkdir(parents=True, exist_ok=True)
    payload = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "workflow_count": len(files),
        "blocking_finding_count": len(findings),
        "findings": [asdict(item) for item in findings],
    }
    (REPORT_DIR / "report.json").write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    lines = [
        "# Active workflow policy audit",
        "",
        f"- Workflows scanned: **{len(files)}**",
        f"- Blocking findings: **{len(findings)}**",
        "",
    ]
    if findings:
        lines.extend(["## Findings", ""])
        for item in findings:
            location = f"{item.path}:{item.line}" if item.line else item.path
            lines.append(f"- **{item.severity.upper()} / {item.rule}** `{location}` — {item.message} Evidence: `{item.excerpt}`")
    else:
        lines.append("No blocking active-workflow policy violation was detected.")
    (REPORT_DIR / "report.md").write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> int:
    files = sorted((*WORKFLOWS.glob("*.yml"), *WORKFLOWS.glob("*.yaml")))
    findings = [finding for path in files for finding in audit_workflow(path)]
    order = {"critical": 0, "high": 1}
    findings.sort(key=lambda item: (order.get(item.severity, 9), item.path, item.line or 0, item.rule))
    write_report(files, findings)
    print(json.dumps({
        "workflow_count": len(files),
        "blocking_finding_count": len(findings),
        "findings": [asdict(item) for item in findings],
    }, indent=2, ensure_ascii=False))
    return 1 if findings else 0


if __name__ == "__main__":
    raise SystemExit(main())
