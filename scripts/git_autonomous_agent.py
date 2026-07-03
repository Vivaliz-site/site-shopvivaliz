#!/usr/bin/env python3
"""Local Git guardian for ShopVivaliz repositories.

This script provides a safe first layer of automation for:
- Git Guardian
- Merge Resolver
- Workspace Sync
- Code Review
- Deploy Guardian preflight checks

It intentionally automates only low-risk behavior and blocks risky pushes.
"""

from __future__ import annotations

import argparse
import fnmatch
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
from datetime import datetime
from typing import Iterable


ZERO_SHA = "0" * 40
DEFAULT_MAX_FILE_BYTES = 1024 * 1024
DEFAULT_CONFIG = {
    "protected_branches": ["main", "master"],
    "blocked_new_paths": [
        ".env",
        ".env.*",
        ".tokens/**",
        ".claude/**",
        ".codex/**",
        "node_modules/**",
        "vendor/**",
        "uploads/**",
        "*.pem",
        "*.key",
        "*.p12",
    ],
    "warn_paths": ["logs/**", "storage/**"],
    "max_file_bytes": DEFAULT_MAX_FILE_BYTES,
}
SECRET_PATTERNS = [
    re.compile(r"(?i)(api[_-]?key|client[_-]?secret|secret|token|password|passwd|senha)\s*[:=]\s*['\"][^'\"]{8,}['\"]"),
    re.compile(r"(?i)\$(?:db_)?pass(?:word)?\s*=\s*['\"][^'\"]{6,}['\"]"),
    re.compile(r"(?i)\b(?:db|ftp|smtp|redis|mysql|pgsql)_(?:pass|password)\s*[:=]\s*['\"][^'\"]{6,}['\"]"),
    re.compile(r"(?i)-----BEGIN (?:RSA |OPENSSH |EC |DSA )?PRIVATE KEY-----"),
    re.compile(r"\bgh[pousr]_[A-Za-z0-9_]{20,}\b"),
    re.compile(r"\bsk-[A-Za-z0-9]{20,}\b"),
    re.compile(r"\bAIza[0-9A-Za-z\-_]{20,}\b"),
    re.compile(r"https://hooks\.slack\.com/services/[A-Za-z0-9/_-]+"),
]
CONFLICT_MARKER_PATTERN = re.compile(r"(?m)^(<<<<<<< .*$|=======\s*$|>>>>>>> .*$)")
TEXT_EXTENSIONS = {
    ".py",
    ".php",
    ".json",
    ".md",
    ".txt",
    ".yml",
    ".yaml",
    ".ps1",
    ".sh",
    ".ini",
    ".cfg",
    ".conf",
    ".csv",
    ".env",
}


class AgentError(RuntimeError):
    """Error that should be shown directly to the user."""


def run(
    args: list[str],
    *,
    cwd: Path | None = None,
    check: bool = True,
    capture_output: bool = True,
    input_text: str | None = None,
) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        args,
        cwd=str(cwd) if cwd else None,
        check=check,
        text=True,
        input=input_text,
        capture_output=capture_output,
    )


def git(
    repo: Path,
    *args: str,
    check: bool = True,
    capture_output: bool = True,
    input_text: str | None = None,
) -> subprocess.CompletedProcess[str]:
    return run(["git", *args], cwd=repo, check=check, capture_output=capture_output, input_text=input_text)


def repo_root() -> Path:
    result = git(Path.cwd(), "rev-parse", "--show-toplevel")
    return Path(result.stdout.strip())


def load_config(repo: Path) -> dict:
    config = dict(DEFAULT_CONFIG)
    config_path = repo / ".git-guardian.json"
    if config_path.is_file():
        payload = json.loads(config_path.read_text(encoding="utf-8"))
        if not isinstance(payload, dict):
            raise AgentError(f"Invalid config file: {config_path}")
        config.update(payload)
    return config


def normalize_path(value: str) -> str:
    return value.replace("\\", "/").lstrip("./")


def matches_any(path: str, patterns: Iterable[str]) -> bool:
    return any(fnmatch.fnmatchcase(path, pattern) for pattern in patterns)


def branch_name(repo: Path) -> str:
    result = git(repo, "branch", "--show-current", check=False)
    return result.stdout.strip()


def upstream_name(repo: Path) -> str:
    result = git(repo, "rev-parse", "--abbrev-ref", "--symbolic-full-name", "@{u}", check=False)
    if result.returncode != 0:
        return ""
    return result.stdout.strip()


def ahead_behind(repo: Path, upstream: str) -> tuple[int, int]:
    if not upstream:
        return 0, 0
    result = git(repo, "rev-list", "--left-right", "--count", f"HEAD...{upstream}")
    left, right = result.stdout.strip().split()
    return int(left), int(right)


def porcelain(repo: Path) -> list[str]:
    result = git(repo, "status", "--porcelain")
    return [line for line in result.stdout.splitlines() if line.strip()]


def unmerged_files(repo: Path) -> list[str]:
    result = git(repo, "diff", "--name-only", "--diff-filter=U", check=False)
    return [line.strip() for line in result.stdout.splitlines() if line.strip()]


def staged_name_status(repo: Path) -> list[tuple[str, str]]:
    result = git(repo, "diff", "--cached", "--name-status", "--find-renames", "--diff-filter=ACMRTUXB")
    entries: list[tuple[str, str]] = []
    for line in result.stdout.splitlines():
        if not line.strip():
            continue
        parts = line.split("\t")
        status = parts[0]
        path = parts[-1]
        entries.append((status, normalize_path(path)))
    return entries


def staged_paths(repo: Path) -> list[str]:
    result = git(repo, "diff", "--cached", "--name-only", "--diff-filter=ACMRTUXB")
    return [normalize_path(line) for line in result.stdout.splitlines() if line.strip()]


def changed_paths(repo: Path) -> list[str]:
    result = git(repo, "diff", "--name-only", "HEAD", "--")
    return [normalize_path(line) for line in result.stdout.splitlines() if line.strip()]


def is_text_path(path: str) -> bool:
    suffix = Path(path).suffix.lower()
    return suffix in TEXT_EXTENSIONS or Path(path).name.startswith(".env")


def staged_blob(repo: Path, path: str) -> bytes:
    result = git(repo, "show", f":{path}", capture_output=False, check=False)
    if result.returncode != 0:
        cp = subprocess.run(
            ["git", "show", f":{path}"],
            cwd=str(repo),
            check=False,
            capture_output=True,
        )
        return cp.stdout
    return b""


def read_staged_text(repo: Path, path: str, max_bytes: int) -> str:
    cp = subprocess.run(
        ["git", "show", f":{path}"],
        cwd=str(repo),
        check=False,
        capture_output=True,
    )
    data = cp.stdout
    if cp.returncode != 0 or not data or len(data) > max_bytes or b"\x00" in data:
        return ""
    return data.decode("utf-8", errors="ignore")


def inspect_review(repo: Path, config: dict) -> tuple[list[str], list[str]]:
    blocked: list[str] = []
    warnings: list[str] = []

    for status, path in staged_name_status(repo):
        is_added = status.startswith("A") or status.startswith("C") or status.startswith("R")
        if path in {".env.example"}:
            continue

        if is_added and matches_any(path, config.get("blocked_new_paths", [])):
            blocked.append(f"new blocked path staged: {path}")
        elif matches_any(path, config.get("warn_paths", [])):
            warnings.append(f"staged path needs review: {path}")

        if not is_text_path(path):
            continue

        text = read_staged_text(repo, path, int(config.get("max_file_bytes", DEFAULT_MAX_FILE_BYTES)))
        if not text:
            continue

        if CONFLICT_MARKER_PATTERN.search(text):
            blocked.append(f"merge conflict markers found in staged file: {path}")
            continue

        for pattern in SECRET_PATTERNS:
            if pattern.search(text):
                blocked.append(f"possible secret detected in staged file: {path}")
                break

    if not branch_name(repo):
        warnings.append("repository is in detached HEAD state")

    for path in unmerged_files(repo):
        blocked.append(f"unmerged file still present: {path}")

    return blocked, warnings


def command_review(repo: Path, config: dict) -> int:
    blocked, warnings = inspect_review(repo, config)
    if warnings:
        print("Warnings:")
        for item in warnings:
            print(f"  - {item}")
    if blocked:
        print("Blocked:")
        for item in blocked:
            print(f"  - {item}")
        return 1
    print("Review passed: no blocked staged changes found.")
    return 0


def command_status(repo: Path, fetch: bool) -> int:
    if fetch:
        git(repo, "fetch", "--prune", check=False)

    branch = branch_name(repo)
    upstream = upstream_name(repo)
    ahead, behind = ahead_behind(repo, upstream)
    pending = porcelain(repo)
    conflicts = unmerged_files(repo)

    print(f"Repository: {repo}")
    print(f"Branch: {branch or '(detached HEAD)'}")
    print(f"Upstream: {upstream or '(none)'}")
    print(f"Ahead: {ahead}")
    print(f"Behind: {behind}")
    print(f"Working tree changes: {len(pending)}")
    print(f"Unmerged files: {len(conflicts)}")

    if conflicts:
        for path in conflicts:
            print(f"  - conflict: {path}")
    if not branch:
        print("Attention: detached HEAD detected. Avoid pushing until you attach to a branch.")
        return 1
    return 0


def create_backup_branch(repo: Path) -> str:
    name = f"git-guardian-backup/{datetime.now().strftime('%Y%m%d-%H%M%S')}"
    git(repo, "branch", name, "HEAD")
    return name


def command_workspace_sync(repo: Path, apply_changes: bool, autostash: bool) -> int:
    git(repo, "fetch", "--prune", check=False)

    branch = branch_name(repo)
    if not branch:
        print("Workspace Sync blocked: repository is in detached HEAD state.")
        return 1

    upstream = upstream_name(repo)
    if not upstream:
        print("Workspace Sync: no upstream branch configured.")
        return 1

    ahead, behind = ahead_behind(repo, upstream)
    dirty = bool(porcelain(repo))

    print(f"Branch {branch} vs {upstream}: ahead={ahead}, behind={behind}, dirty={dirty}")

    if not apply_changes:
        if behind == 0:
            print("Workspace already synchronized or only ahead locally.")
            return 0
        print("Dry run only. Re-run with --apply to execute pull --rebase.")
        return 0

    if dirty and not autostash:
        print("Workspace Sync blocked: working tree is dirty. Use --autostash or commit/stash first.")
        return 1

    backup = create_backup_branch(repo)
    print(f"Backup branch created: {backup}")

    pull_args = ["pull", "--rebase"]
    if autostash:
        pull_args.append("--autostash")

    result = git(repo, *pull_args, check=False)
    if result.returncode != 0:
        print(result.stdout.strip())
        print(result.stderr.strip())
        print("Workspace Sync needs human review. Backup branch is available if you need to restore.")
        return 1

    print(result.stdout.strip())
    print("Workspace Sync completed successfully.")
    return 0


def backup_file(repo: Path, relative_path: str) -> Path:
    backup_root = repo / ".git" / "git-guardian-backups" / datetime.now().strftime("%Y%m%d-%H%M%S")
    destination = backup_root / relative_path
    destination.parent.mkdir(parents=True, exist_ok=True)
    source = repo / relative_path
    destination.write_bytes(source.read_bytes())
    return destination


def resolve_conflicts_safely(text: str) -> tuple[str, int, int]:
    lines = text.splitlines(keepends=True)
    output: list[str] = []
    safe_blocks = 0
    unresolved_blocks = 0
    i = 0

    while i < len(lines):
        if not lines[i].startswith("<<<<<<< "):
            output.append(lines[i])
            i += 1
            continue

        original_block = [lines[i]]
        i += 1
        ours: list[str] = []
        theirs: list[str] = []

        while i < len(lines) and not lines[i].startswith("======="):
            ours.append(lines[i])
            original_block.append(lines[i])
            i += 1

        if i >= len(lines):
            output.extend(original_block)
            unresolved_blocks += 1
            break

        original_block.append(lines[i])
        i += 1

        while i < len(lines) and not lines[i].startswith(">>>>>>> "):
            theirs.append(lines[i])
            original_block.append(lines[i])
            i += 1

        if i >= len(lines):
            output.extend(original_block)
            unresolved_blocks += 1
            break

        original_block.append(lines[i])
        i += 1

        ours_text = "".join(ours)
        theirs_text = "".join(theirs)
        if ours_text == theirs_text or ours_text.strip() == theirs_text.strip():
            output.append(ours_text)
            safe_blocks += 1
        else:
            output.extend(original_block)
            unresolved_blocks += 1

    return "".join(output), safe_blocks, unresolved_blocks


def validate_file(repo: Path, relative_path: str) -> tuple[bool, str]:
    path = repo / relative_path
    suffix = path.suffix.lower()

    if suffix == ".py":
        result = run([sys.executable, "-m", "py_compile", str(path)], cwd=repo, check=False)
        return result.returncode == 0, result.stderr.strip()

    if suffix == ".php" and shutil.which("php"):
        result = run(["php", "-l", str(path)], cwd=repo, check=False)
        detail = (result.stdout + "\n" + result.stderr).strip()
        return result.returncode == 0, detail

    if suffix == ".json":
        try:
            json.loads(path.read_text(encoding="utf-8"))
            return True, ""
        except json.JSONDecodeError as exc:
            return False, str(exc)

    return True, ""


def command_resolve_conflicts(repo: Path) -> int:
    paths = unmerged_files(repo)
    if not paths:
        print("No unmerged files found.")
        return 0

    unresolved: list[str] = []
    auto_resolved: list[str] = []

    for relative_path in paths:
        full_path = repo / relative_path
        try:
            text = full_path.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            unresolved.append(f"{relative_path} (binary or non-utf8)")
            continue

        resolved_text, safe_blocks, unresolved_blocks = resolve_conflicts_safely(text)
        if safe_blocks == 0:
            unresolved.append(f"{relative_path} (no safe blocks)")
            continue

        backup_path = backup_file(repo, relative_path)
        full_path.write_text(resolved_text, encoding="utf-8")

        ok, detail = validate_file(repo, relative_path)
        if not ok:
            full_path.write_bytes(backup_path.read_bytes())
            unresolved.append(f"{relative_path} (validation failed: {detail})")
            continue

        if unresolved_blocks == 0:
            git(repo, "add", "--", relative_path)
            auto_resolved.append(relative_path)
        else:
            unresolved.append(f"{relative_path} ({unresolved_blocks} conflict blocks still require review)")

    if auto_resolved:
        print("Auto-resolved files:")
        for path in auto_resolved:
            print(f"  - {path}")
    if unresolved:
        print("Still unresolved:")
        for path in unresolved:
            print(f"  - {path}")
        return 1

    print("All detected conflicts were resolved safely.")
    return 0


def lint_python_tree(repo: Path) -> tuple[bool, str]:
    scripts_dir = repo / "scripts"
    if not scripts_dir.is_dir():
        return True, ""
    result = run([sys.executable, "-m", "compileall", str(scripts_dir)], cwd=repo, check=False)
    detail = (result.stdout + "\n" + result.stderr).strip()
    return result.returncode == 0, detail


def command_deploy_guardian(repo: Path, config: dict) -> int:
    blocked, warnings = inspect_review(repo, config)
    if warnings:
        print("Warnings:")
        for item in warnings:
            print(f"  - {item}")
    if blocked:
        print("Deploy Guardian blocked by staged issues:")
        for item in blocked:
            print(f"  - {item}")
        return 1

    checks: list[tuple[str, bool, str]] = []

    ok, detail = lint_python_tree(repo)
    checks.append(("python compileall scripts", ok, detail))

    php_self_test = repo / "installer" / "self-test.php"
    if php_self_test.is_file() and shutil.which("php"):
        result = run(["php", "-l", str(php_self_test)], cwd=repo, check=False)
        checks.append(("php -l installer/self-test.php", result.returncode == 0, (result.stdout + "\n" + result.stderr).strip()))

    exit_code = 0
    for label, ok, detail in checks:
        status = "OK" if ok else "FAIL"
        print(f"[{status}] {label}")
        if detail and not ok:
            print(detail)
        if not ok:
            exit_code = 1

    if (repo / ".github" / "workflows" / "deploy.yml").is_file():
        print("Remote deploy workflow detected: .github/workflows/deploy.yml")
    elif (repo / ".github" / "workflows" / "pipeline.yml").is_file():
        print("Remote pipeline workflow detected: .github/workflows/pipeline.yml")

    return exit_code


def command_install_hooks(repo: Path) -> int:
    hooks_dir = repo / ".githooks"
    if not hooks_dir.is_dir():
        raise AgentError(f"Hook directory not found: {hooks_dir}")
    git(repo, "config", "core.hooksPath", ".githooks")
    print("Git hooks installed: core.hooksPath -> .githooks")
    return 0


def command_hook_pre_commit(repo: Path, config: dict) -> int:
    branch = branch_name(repo)
    if not branch:
        print("Warning: commit from detached HEAD. Review branch attachment before pushing.")
    return command_review(repo, config)


def is_ancestor(repo: Path, older: str, newer: str) -> bool:
    result = git(repo, "merge-base", "--is-ancestor", older, newer, check=False)
    return result.returncode == 0


def protected_branch_name(remote_ref: str) -> str:
    if remote_ref.startswith("refs/heads/"):
        return remote_ref.rsplit("/", 1)[-1]
    return remote_ref


def command_hook_pre_push(repo: Path, config: dict, stdin_text: str) -> int:
    branch = branch_name(repo)
    if not branch:
        print("Push blocked: detached HEAD state.")
        return 1

    protected = set(config.get("protected_branches", []))
    for line in stdin_text.splitlines():
        if not line.strip():
            continue
        local_ref, local_sha, remote_ref, remote_sha = line.split()
        target_branch = protected_branch_name(remote_ref)

        if local_sha == ZERO_SHA and target_branch in protected:
            print(f"Push blocked: refusing to delete protected branch {target_branch}.")
            return 1

        if remote_sha != ZERO_SHA and local_sha != ZERO_SHA and not is_ancestor(repo, remote_sha, local_sha):
            print(f"Push blocked: non-fast-forward update detected for {target_branch}.")
            print("Create a normal sync/rebase flow or a pull request instead of force-pushing.")
            return 1

    blocked, warnings = inspect_review(repo, config)
    if warnings:
        for item in warnings:
            print(f"Warning: {item}")
    if blocked:
        for item in blocked:
            print(f"Push blocked: {item}")
        return 1

    print("Pre-push checks passed.")
    return 0


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="ShopVivaliz Git autonomous agent")
    subparsers = parser.add_subparsers(dest="command", required=True)

    status_parser = subparsers.add_parser("status", help="Show branch/sync/conflict status")
    status_parser.add_argument("--no-fetch", action="store_true", help="Do not fetch before showing status")

    sync_parser = subparsers.add_parser("workspace-sync", help="Fetch and optionally pull --rebase safely")
    sync_parser.add_argument("--apply", action="store_true", help="Actually execute pull --rebase")
    sync_parser.add_argument("--autostash", action="store_true", help="Allow git pull --rebase --autostash")

    subparsers.add_parser("review", help="Review staged changes for secrets and blocked paths")
    subparsers.add_parser("resolve-conflicts", help="Safely resolve trivial merge conflicts")
    subparsers.add_parser("deploy-guardian", help="Run local deploy preflight checks")
    subparsers.add_parser("install-hooks", help="Point core.hooksPath to .githooks")
    subparsers.add_parser("hook-pre-commit", help=argparse.SUPPRESS)
    subparsers.add_parser("hook-pre-push", help=argparse.SUPPRESS)

    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()

    try:
        repo = repo_root()
        config = load_config(repo)

        if args.command == "status":
            return command_status(repo, fetch=not args.no_fetch)
        if args.command == "workspace-sync":
            return command_workspace_sync(repo, apply_changes=args.apply, autostash=args.autostash)
        if args.command == "review":
            return command_review(repo, config)
        if args.command == "resolve-conflicts":
            return command_resolve_conflicts(repo)
        if args.command == "deploy-guardian":
            return command_deploy_guardian(repo, config)
        if args.command == "install-hooks":
            return command_install_hooks(repo)
        if args.command == "hook-pre-commit":
            return command_hook_pre_commit(repo, config)
        if args.command == "hook-pre-push":
            return command_hook_pre_push(repo, config, sys.stdin.read())
    except AgentError as exc:
        print(str(exc))
        return 1
    except subprocess.CalledProcessError as exc:
        message = exc.stderr.strip() if exc.stderr else exc.stdout.strip()
        print(message or str(exc))
        return exc.returncode or 1

    return 0


if __name__ == "__main__":
    sys.exit(main())
