#!/usr/bin/env python3
"""Fail-closed mobile bridge for reviewed ShopVivaliz work.

The bridge supports read-only inspection, issue creation and opening a pull
request from an allow-listed patch. It never merges, deploys or writes a
protected branch. Every successful action contains verifiable evidence and a
queue item is moved to ``.done`` only after all mandatory commands succeeded.
"""
from __future__ import annotations

import argparse
import fnmatch
import hashlib
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
from typing import Any, Callable


class BridgeError(RuntimeError):
    """A bridge action failed and must not be acknowledged as complete."""


@dataclass(frozen=True)
class CmdResult:
    command: list[str]
    returncode: int
    stdout: str
    stderr: str


def load_json(path: Path) -> dict[str, Any]:
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise BridgeError(f"JSON object required: {path}")
    return data


def write_json(path: Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_suffix(path.suffix + ".tmp")
    temporary.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    os.replace(temporary, path)


def assert_command_safe(command: list[str]) -> None:
    text = " ".join(command)
    blocked = (
        r"\bgit\s+reset\s+--hard\b",
        r"\bgit\s+clean\b",
        r"\brm\s+-rf\b",
        r"\bsudo\s+rm\b",
        r"\bgh\s+pr\s+merge\b",
        r"\bgit\s+push\b[^\n]*(?:--force(?:-with-lease)?|(?:HEAD:|refs/heads/)?(?:main|master)\b)",
    )
    if any(re.search(pattern, text, re.IGNORECASE) for pattern in blocked):
        raise BridgeError(f"Unsafe command blocked: {text}")


def run(command: list[str], cwd: Path, timeout: int = 180) -> CmdResult:
    assert_command_safe(command)
    completed = subprocess.run(
        command,
        cwd=cwd,
        text=True,
        capture_output=True,
        timeout=timeout,
        check=False,
    )
    result = CmdResult(command, completed.returncode, completed.stdout, completed.stderr)
    if result.returncode != 0:
        raise BridgeError(
            f"Command failed ({result.returncode}): {' '.join(command)}\n"
            f"STDOUT:\n{result.stdout}\nSTDERR:\n{result.stderr}"
        )
    return result


def run_shell(command: str, cwd: Path, timeout: int = 300) -> CmdResult:
    return run(["bash", "-Eeu", "-o", "pipefail", "-c", command], cwd, timeout)


def normalized(path: str) -> str:
    return path.replace("\\", "/").lstrip("./")


def allowed_path(path: str, config: dict[str, Any]) -> bool:
    value = normalized(path)
    blocked_patterns = [str(item) for item in config.get("blocked_file_patterns", [])]
    if any(fnmatch.fnmatch(value, pattern) or fnmatch.fnmatch(Path(value).name, pattern) for pattern in blocked_patterns):
        return False
    return any(value.startswith(str(prefix)) for prefix in config.get("allowed_file_prefixes", []))


def patch_paths(patch: str) -> list[str]:
    paths: set[str] = set()
    for line in patch.splitlines():
        if line.startswith("diff --git "):
            parts = line.split()
            if len(parts) >= 4 and parts[3].startswith("b/"):
                paths.add(normalized(parts[3][2:]))
        elif line.startswith("+++ b/"):
            paths.add(normalized(line[6:]))
    return sorted(paths)


def validate_patch(patch: str, config: dict[str, Any]) -> list[str]:
    if not patch or len(patch.encode("utf-8")) > int(config.get("max_patch_bytes", 200_000)):
        raise BridgeError("Patch is empty or exceeds max_patch_bytes")
    paths = patch_paths(patch)
    if not paths:
        raise BridgeError("Patch contains no detectable file path")
    denied = [path for path in paths if not allowed_path(path, config)]
    if denied:
        raise BridgeError("Patch contains blocked paths: " + ", ".join(denied))
    credential = re.compile(
        r"(?i)(?:github_pat_|gh[pousr]_|-----BEGIN .*PRIVATE KEY-----|"
        r"(?:password|client_secret|access_token|refresh_token|api_key)\s*[:=]\s*['\"][^'\"]{8,})"
    )
    if credential.search(patch):
        raise BridgeError("Patch contains a credential-like value")
    return paths


def branch_name(prefix: str, title: str) -> str:
    slug = re.sub(r"[^a-z0-9]+", "-", title.lower()).strip("-")[:50] or "task"
    return f"{prefix}{slug}-{int(time.time())}"


def verified(payload: dict[str, Any]) -> dict[str, Any]:
    payload["status"] = "VERIFIED"
    payload["verified_at"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
    return payload


def create_issue(task: dict[str, Any], config: dict[str, Any], repo: Path) -> dict[str, Any]:
    title = str(task.get("title") or task.get("issue", {}).get("title") or "").strip()
    body = str(task.get("body") or task.get("issue", {}).get("body") or "").strip()
    if not title or not body:
        raise BridgeError("create_issue requires title and body")
    with tempfile.NamedTemporaryFile("w", encoding="utf-8", suffix=".md", delete=False) as handle:
        handle.write(body)
        body_path = Path(handle.name)
    try:
        command = ["gh", "issue", "create", "--repo", str(config["repo_full_name"]), "--title", title, "--body-file", str(body_path)]
        for label in config.get("github_labels", []):
            command.extend(["--label", str(label)])
        result = run(command, repo)
    finally:
        body_path.unlink(missing_ok=True)
    url = result.stdout.strip()
    if re.fullmatch(r"https://github\.com/[^/]+/[^/]+/issues/\d+", url) is None:
        raise BridgeError("Issue creation returned no verifiable URL")
    return verified({"action": "create_issue", "issue_url": url, "evidence": asdict(result)})


def read_file(task: dict[str, Any], config: dict[str, Any], repo: Path) -> dict[str, Any]:
    relative = normalized(str(task.get("path") or ""))
    if not relative or not allowed_path(relative, config):
        raise BridgeError("read_file path is missing or blocked")
    full = (repo / relative).resolve()
    if repo.resolve() not in full.parents:
        raise BridgeError("Path traversal blocked")
    content = full.read_bytes()
    return verified({
        "action": "read_file",
        "path": relative,
        "content": content[:20_000].decode("utf-8", errors="replace"),
        "truncated": len(content) > 20_000,
        "evidence": {"bytes": len(content), "sha256": hashlib.sha256(content).hexdigest()},
    })


def read_only_audit(task: dict[str, Any], config: dict[str, Any], repo: Path) -> dict[str, Any]:
    del task, config
    commands = [
        ["git", "status", "--short"],
        ["git", "log", "--oneline", "-n", "15"],
        [sys.executable, "scripts/audit-agents-real-work.py"],
        [sys.executable, "scripts/maintenance/system_health_check.py"],
    ]
    outputs = [asdict(run(command, repo, timeout=300)) for command in commands]
    digest = hashlib.sha256(json.dumps(outputs, sort_keys=True).encode("utf-8")).hexdigest()
    return verified({
        "action": "run_readonly_audit",
        "all_commands_succeeded": True,
        "command_count": len(outputs),
        "outputs": outputs,
        "evidence": {"outputs_sha256": digest},
    })


def apply_patch_pr(task: dict[str, Any], config: dict[str, Any], repo: Path) -> dict[str, Any]:
    title = str(task.get("title") or "Agent patch").strip()
    patch = str(task.get("patch") or "")
    paths = validate_patch(patch, config)
    base = str(task.get("base_branch") or config.get("default_base_branch", "main"))
    if base not in {str(item) for item in config.get("allowed_base_branches", ["main"])}:
        raise BridgeError(f"Base branch is not allowed: {base}")
    prefix = str(config.get("branch_prefix", "agent/"))
    head = str(task.get("branch") or branch_name(prefix, title))
    if not head.startswith(prefix) or head in {"main", "master"}:
        raise BridgeError("Head branch must use the configured non-protected prefix")

    run(["git", "fetch", "origin", base], repo)
    evidence: dict[str, Any] = {"changed_paths": paths, "commands": [], "validations": []}
    with tempfile.TemporaryDirectory(prefix="shopvivaliz-agent-worktree-") as temporary:
        worktree = Path(temporary)
        evidence["commands"].append(asdict(run(["git", "worktree", "add", "-b", head, str(worktree), f"origin/{base}"], repo)))
        try:
            patch_file = worktree / ".agent-bridge.patch"
            patch_file.write_text(patch, encoding="utf-8")
            evidence["commands"].append(asdict(run(["git", "apply", "--check", str(patch_file)], worktree)))
            evidence["commands"].append(asdict(run(["git", "apply", str(patch_file)], worktree)))
            patch_file.unlink(missing_ok=True)
            for validation in config.get("validations", []):
                evidence["validations"].append(asdict(run_shell(str(validation), worktree)))
            if not run(["git", "status", "--porcelain"], worktree).stdout.strip():
                raise BridgeError("Patch generated no real change")
            evidence["diffstat"] = run(["git", "diff", "--stat"], worktree).stdout
            evidence["commands"].append(asdict(run(["git", "add", "--", *paths], worktree)))
            evidence["commands"].append(asdict(run(["git", "commit", "-m", str(task.get("commit_message") or title)], worktree)))
            commit_sha = run(["git", "rev-parse", "HEAD"], worktree).stdout.strip()
            if re.fullmatch(r"[0-9a-f]{40}", commit_sha) is None:
                raise BridgeError("Commit SHA could not be verified")
            evidence["commit_sha"] = commit_sha
            evidence["commands"].append(asdict(run(["git", "push", "-u", "origin", head], worktree)))
        finally:
            run(["git", "worktree", "remove", str(worktree)], repo)

    body = str(task.get("body") or "PR created by the ShopVivaliz Agent Bridge.")
    body += "\n\n## Bridge evidence\n\n```json\n" + json.dumps(evidence, ensure_ascii=False, indent=2) + "\n```\n"
    with tempfile.NamedTemporaryFile("w", encoding="utf-8", suffix=".md", delete=False) as handle:
        handle.write(body)
        body_path = Path(handle.name)
    try:
        result = run([
            "gh", "pr", "create", "--repo", str(config["repo_full_name"]),
            "--base", base, "--head", head, "--title", title, "--body-file", str(body_path),
        ], repo)
    finally:
        body_path.unlink(missing_ok=True)
    pr_url = result.stdout.strip()
    if re.fullmatch(r"https://github\.com/[^/]+/[^/]+/pull/\d+", pr_url) is None:
        raise BridgeError("PR creation returned no verifiable URL")
    return verified({
        "action": "apply_patch_pr", "branch": head, "commit_sha": evidence["commit_sha"],
        "pr_url": pr_url, "changed_paths": paths, "merge_performed": False, "evidence": evidence,
    })


def process(task_path: Path, config: dict[str, Any]) -> dict[str, Any]:
    repo = Path(str(config["repo_path"])).resolve()
    task = load_json(task_path)
    handlers: dict[str, Callable[[dict[str, Any], dict[str, Any], Path], dict[str, Any]]] = {
        "create_issue": create_issue,
        "read_file": read_file,
        "run_readonly_audit": read_only_audit,
        "apply_patch_pr": apply_patch_pr,
    }
    action = str(task.get("action") or "")
    if action not in handlers:
        raise BridgeError(f"Action is not allowed: {action}")
    result = handlers[action](task, config, repo)
    if result.get("status") != "VERIFIED" or not result.get("evidence"):
        raise BridgeError("Action lacks verified evidence")
    if action == "apply_patch_pr" and not all(result.get(key) for key in ("commit_sha", "pr_url")):
        raise BridgeError("Mutating action lacks commit or PR")
    return result


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", default="agent-bridge/config.json")
    parser.add_argument("--once", action="store_true")
    parser.add_argument("--sleep", type=int, default=30)
    args = parser.parse_args()
    config_path = Path(args.config)
    if not config_path.is_file():
        raise SystemExit(f"Config not found: {config_path}")
    config = load_json(config_path)
    repo = Path(str(config["repo_path"])).resolve()
    inbox = repo / str(config.get("inbox_dir", "agent-bridge/inbox"))
    outbox = repo / str(config.get("outbox_dir", "agent-bridge/outbox"))
    logs = repo / str(config.get("log_dir", "agent-bridge/logs"))
    for directory in (inbox, outbox, logs):
        directory.mkdir(parents=True, exist_ok=True)

    while True:
        for task_path in sorted(inbox.glob("*.json")):
            result_path = outbox / f"{task_path.stem}.{int(time.time())}.result.json"
            try:
                result = process(task_path, config)
                write_json(result_path, result)
                shutil.move(str(task_path), str(task_path.with_suffix(".json.done")))
            except Exception as exc:
                write_json(result_path, {
                    "status": "ERROR", "task": task_path.name, "completed": False,
                    "error": f"{type(exc).__name__}: {exc}",
                })
                shutil.move(str(task_path), str(task_path.with_suffix(".json.failed")))
        if args.once:
            return 0
        time.sleep(max(5, args.sleep))


if __name__ == "__main__":
    raise SystemExit(main())
