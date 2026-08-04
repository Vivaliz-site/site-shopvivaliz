#!/usr/bin/env python3
"""ShopVivaliz mobile agent bridge with fail-closed task completion.

The bridge may create an issue, read an allow-listed file, run the canonical
read-only audit, or apply an allow-listed patch on a new branch and open a PR.
It never merges a PR or pushes a protected branch. A queue item is moved to
``.done`` only after every required command succeeded and the result contains
verifiable evidence.
"""
from __future__ import annotations

import argparse
import fnmatch
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import time
from dataclasses import asdict, dataclass
from pathlib import Path
from typing import Any


class BridgeError(RuntimeError):
    pass


@dataclass
class CmdResult:
    cmd: str
    code: int
    stdout: str
    stderr: str


def load_json(path: Path) -> dict[str, Any]:
    with path.open("r", encoding="utf-8") as handle:
        data = json.load(handle)
    if not isinstance(data, dict):
        raise BridgeError(f"JSON object required: {path}")
    return data


def write_json(path: Path, data: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temp = path.with_suffix(path.suffix + ".tmp")
    temp.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    os.replace(temp, path)


def command_text(cmd: list[str]) -> str:
    return " ".join(cmd)


def assert_command_safe(text: str) -> None:
    patterns = (
        r"\bgit\s+reset\s+--hard\b",
        r"\bgit\s+clean\b",
        r"\brm\s+-rf\b",
        r"\bsudo\s+rm\b",
        r"\bgh\s+pr\s+merge\b",
        r"\bgit\s+push\b[^\n]*(?:--force(?:-with-lease)?|(?:HEAD:|refs/heads/)?(?:main|master)\b)",
    )
    if any(re.search(pattern, text, re.IGNORECASE) for pattern in patterns):
        raise BridgeError(f"Unsafe command blocked: {text}")


def run(cmd: list[str], cwd: Path, timeout: int = 120) -> CmdResult:
    joined = command_text(cmd)
    assert_command_safe(joined)
    proc = subprocess.run(cmd, cwd=cwd, text=True, capture_output=True, timeout=timeout, check=False)
    result = CmdResult(joined, proc.returncode, proc.stdout, proc.stderr)
    if proc.returncode != 0:
        raise BridgeError(
            f"Command failed ({proc.returncode}): {joined}\nSTDOUT:\n{proc.stdout}\nSTDERR:\n{proc.stderr}"
        )
    return result


def run_shell(command: str, cwd: Path, timeout: int = 180) -> CmdResult:
    assert_command_safe(command)
    proc = subprocess.run(
        ["bash", "-Eeuo", "pipefail", "-c", command],
        cwd=cwd,
        text=True,
        capture_output=True,
        timeout=timeout,
        check=False,
    )
    result = CmdResult(command, proc.returncode, proc.stdout, proc.stderr)
    if proc.returncode != 0:
        raise BridgeError(
            f"Shell command failed ({proc.returncode}): {command}\nSTDOUT:\n{proc.stdout}\nSTDERR:\n{proc.stderr}"
        )
    return result


def assert_repo_clean(repo: Path) -> None:
    lines = run(["git", "status", "--porcelain"], repo).stdout.splitlines()
    relevant: list[str] = []
    for line in lines:
        path = line[3:].split(" -> ", 1)[-1].replace("\\", "/") if len(line) >= 4 else ""
        if path.startswith("agent-bridge/") or path.startswith(".agent-bridge"):
            continue
        relevant.append(line)
    if relevant:
        raise BridgeError("Repository has unrelated local changes:\n" + "\n".join(relevant))


def safe_branch_name(prefix: str, title: str) -> str:
    slug = re.sub(r"[^a-z0-9]+", "-", title.lower()).strip("-")[:60] or "task"
    return f"{prefix}{slug}-{int(time.time())}"


def is_blocked_path(path: str, config: dict[str, Any]) -> bool:
    normalized = path.replace("\\", "/")
    return any(
        fnmatch.fnmatch(normalized, pattern) or fnmatch.fnmatch(Path(normalized).name, pattern)
        for pattern in config.get("blocked_file_patterns", [])
    )


def is_allowed_path(path: str, config: dict[str, Any]) -> bool:
    normalized = path.replace("\\", "/")
    if is_blocked_path(normalized, config):
        return False
    return any(normalized.startswith(prefix) for prefix in config.get("allowed_file_prefixes", []))


def extract_patch_paths(patch_text: str) -> list[str]:
    paths: set[str] = set()
    for line in patch_text.splitlines():
        if line.startswith("diff --git "):
            fields = line.split()
            if len(fields) >= 4 and fields[3].startswith("b/"):
                paths.add(fields[3][2:])
        elif line.startswith("+++ b/"):
            paths.add(line[6:])
    return sorted(paths)


def validate_patch(patch_text: str, config: dict[str, Any]) -> list[str]:
    if len(patch_text.encode("utf-8")) > int(config.get("max_patch_bytes", 200000)):
        raise BridgeError("Patch exceeds max_patch_bytes")
    paths = extract_patch_paths(patch_text)
    if not paths:
        raise BridgeError("Patch contains no detectable paths")
    blocked = [path for path in paths if not is_allowed_path(path, config)]
    if blocked:
        raise BridgeError("Patch contains blocked paths: " + ", ".join(blocked))
    secret_pattern = re.compile(
        r"(?i)(?:github_pat_|gh[pousr]_|-----BEGIN .*PRIVATE KEY-----|"
        r"(?:password|client_secret|access_token|refresh_token|api_key)\s*[:=]\s*['\"][^'\"]{8,})"
    )
    if secret_pattern.search(patch_text):
        raise BridgeError("Patch contains a credential-like value")
    return paths


def create_issue(task: dict[str, Any], config: dict[str, Any], repo: Path) -> dict[str, Any]:
    title = task.get("title") or task.get("issue", {}).get("title")
    body = task.get("body") or task.get("issue", {}).get("body")
    if not title or not body:
        raise BridgeError("create_issue requires title and body")
    with tempfile.NamedTemporaryFile("w", encoding="utf-8", suffix=".md", delete=False, dir=repo) as handle:
        handle.write(str(body))
        body_path = Path(handle.name)
    try:
        args = ["gh", "issue", "create", "--repo", config["repo_full_name"], "--title", str(title), "--body-file", str(body_path)]
        for label in config.get("github_labels", []):
            args.extend(["--label", str(label)])
        result = run(args, repo)
    finally:
        body_path.unlink(missing_ok=True)
    url = result.stdout.strip()
    if not re.match(r"^https://github\.com/.+/issues/\d+$", url):
        raise BridgeError("Issue creation returned no verifiable URL")
    return {"status": "OK", "action": "create_issue", "issue_url": url, "evidence": {"command": asdict(result)}}


def apply_patch_pr(task: dict[str, Any], config: dict[str, Any], repo: Path) -> dict[str, Any]:
    title = str(task.get("title") or "Agent patch")
    patch_text = task.get("patch")
    if not isinstance(patch_text, str) or not patch_text:
        raise BridgeError("apply_patch_pr requires patch")
    changed_paths = validate_patch(patch_text, config)
    assert_repo_clean(repo)

    base = str(task.get("base_branch") or config.get("default_base_branch", "main"))
    if base not in set(config.get("allowed_base_branches", ["main"])):
        raise BridgeError(f"Base branch is not allowed: {base}")
    prefix = str(config.get("branch_prefix", "agent/"))
    branch = str(task.get("branch") or safe_branch_name(prefix, title))
    if not branch.startswith(prefix) or branch in {"main", "master"}:
        raise BridgeError("Head branch must use the configured non-protected prefix")

    evidence: dict[str, Any] = {"changed_paths": changed_paths, "commands": [], "validations": []}
    for command in (["git", "fetch", "origin", base], ["git", "checkout", base], ["git", "merge", "--ff-only", f"origin/{base}"], ["git", "checkout", "-b", branch]):
        evidence["commands"].append(asdict(run(command, repo)))

    patch_file = repo / ".agent-bridge.patch"
    patch_file.write_text(patch_text, encoding="utf-8")
    try:
        evidence["commands"].append(asdict(run(["git", "apply", "--check", str(patch_file)], repo)))
        evidence["commands"].append(asdict(run(["git", "apply", str(patch_file)], repo)))
    finally:
        patch_file.unlink(missing_ok=True)

    for command in config.get("validations", []):
        evidence["validations"].append(asdict(run_shell(str(command), repo)))

    status = run(["git", "status", "--porcelain"], repo).stdout.strip()
    if not status:
        raise BridgeError("Patch generated no real change")
    evidence["diffstat"] = run(["git", "diff", "--stat"], repo).stdout

    evidence["commands"].append(asdict(run(["git", "add", "--", *changed_paths], repo)))
    evidence["commands"].append(asdict(run(["git", "commit", "-m", str(task.get("commit_message") or title)], repo)))
    commit_sha = run(["git", "rev-parse", "HEAD"], repo).stdout.strip()
    if re.fullmatch(r"[0-9a-f]{40}", commit_sha) is None:
        raise BridgeError("Commit SHA could not be verified")
    evidence["commit_sha"] = commit_sha
    evidence["commands"].append(asdict(run(["git", "push", "-u", "origin", branch], repo)))

    body = str(task.get("body") or "PR created by the ShopVivaliz Mobile Agent Bridge.")
    body += "\n\n## Agent Bridge evidence\n\n```json\n" + json.dumps(evidence, ensure_ascii=False, indent=2) + "\n```\n"
    with tempfile.NamedTemporaryFile("w", encoding="utf-8", suffix=".md", delete=False, dir=repo) as handle:
        handle.write(body)
        body_path = Path(handle.name)
    try:
        pr = run([
            "gh", "pr", "create", "--repo", config["repo_full_name"], "--base", base,
            "--head", branch, "--title", title, "--body-file", str(body_path),
        ], repo)
    finally:
        body_path.unlink(missing_ok=True)
    pr_url = pr.stdout.strip()
    if not re.match(r"^https://github\.com/.+/pull/\d+$", pr_url):
        raise BridgeError("PR creation returned no verifiable URL")

    return {
        "status": "OK",
        "action": "apply_patch_pr",
        "branch": branch,
        "commit_sha": commit_sha,
        "pr_url": pr_url,
        "changed_paths": changed_paths,
        "validation_count": len(evidence["validations"]),
        "evidence": evidence,
        "merge_performed": False,
    }


def read_file(task: dict[str, Any], config: dict[str, Any], repo: Path) -> dict[str, Any]:
    path = str(task.get("path") or "")
    if not path or not is_allowed_path(path, config):
        raise BridgeError("read_file path is missing or blocked")
    full = (repo / path).resolve()
    if repo.resolve() not in full.parents and full != repo.resolve():
        raise BridgeError("Path traversal blocked")
    text = full.read_text(encoding="utf-8", errors="replace")
    return {"status": "OK", "action": "read_file", "path": path, "content": text[:20000], "truncated": len(text) > 20000}


def run_readonly_audit(task: dict[str, Any], config: dict[str, Any], repo: Path) -> dict[str, Any]:
    commands = [
        ["git", "status", "--short"],
        ["git", "log", "--oneline", "-n", "15"],
        [sys.executable, "scripts/audit-agents-real-work.py"],
        [sys.executable, "scripts/maintenance/system_health_check.py"],
    ]
    outputs = [asdict(run(command, repo, timeout=300)) for command in commands]
    return {
        "status": "OK",
        "action": "run_readonly_audit",
        "all_commands_succeeded": True,
        "command_count": len(outputs),
        "outputs": outputs,
    }


def process_task(task_path: Path, config: dict[str, Any]) -> dict[str, Any]:
    repo = Path(config["repo_path"]).resolve()
    task = load_json(task_path)
    handlers = {
        "create_issue": create_issue,
        "apply_patch_pr": apply_patch_pr,
        "read_file": read_file,
        "run_readonly_audit": run_readonly_audit,
    }
    action = str(task.get("action") or "")
    if action not in handlers:
        raise BridgeError(f"Action is not allowed: {action}")
    result = handlers[action](task, config, repo)
    if result.get("status") != "OK":
        raise BridgeError(f"Action did not return verified OK status: {action}")
    if action == "apply_patch_pr" and not all(result.get(key) for key in ("commit_sha", "pr_url", "evidence")):
        raise BridgeError("Mutating action lacks commit/PR/evidence")
    if action == "run_readonly_audit" and result.get("all_commands_succeeded") is not True:
        raise BridgeError("Read-only audit did not validate all commands")
    return result


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", default="agent-bridge/config.json")
    parser.add_argument("--once", action="store_true")
    parser.add_argument("--sleep", type=int, default=30)
    args = parser.parse_args()

    config_path = Path(args.config)
    if not config_path.exists():
        raise SystemExit(f"Config not found: {config_path}")
    config = load_json(config_path)
    repo = Path(config["repo_path"]).resolve()
    inbox = repo / config.get("inbox_dir", "agent-bridge/inbox")
    outbox = repo / config.get("outbox_dir", "agent-bridge/outbox")
    logs = repo / config.get("log_dir", "agent-bridge/logs")
    for directory in (inbox, outbox, logs):
        directory.mkdir(parents=True, exist_ok=True)

    while True:
        for task_path in sorted(inbox.glob("*.json")):
            stamp = int(time.time())
            result_path = outbox / f"{task_path.stem}.{stamp}.result.json"
            try:
                result = process_task(task_path, config)
                write_json(result_path, result)
                shutil.move(str(task_path), str(task_path.with_suffix(".json.done")))
            except Exception as exc:
                error = {"status": "ERROR", "task": task_path.name, "error": str(exc), "completed": False}
                write_json(result_path, error)
                shutil.move(str(task_path), str(task_path.with_suffix(".json.failed")))
        if args.once:
            return 0
        time.sleep(max(5, args.sleep))


if __name__ == "__main__":
    raise SystemExit(main())
