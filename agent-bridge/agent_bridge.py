#!/usr/bin/env python3
"""
ShopVivaliz Mobile Agent Bridge

Executa tarefas vindas do ChatGPT Chat mobile por fila local ou, futuramente, Gmail.
Foco: operar GitHub com seguranca via git/gh, sempre em branch e PR.
"""
from __future__ import annotations

import argparse
import fnmatch
import json
import re
import shutil
import subprocess
import sys
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List


class BridgeError(Exception):
    pass


@dataclass
class CmdResult:
    cmd: str
    code: int
    stdout: str
    stderr: str


def load_json(path: Path) -> Dict[str, Any]:
    with path.open("r", encoding="utf-8") as handle:
        return json.load(handle)


def write_json(path: Path, data: Dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(path.suffix + ".tmp")
    with tmp.open("w", encoding="utf-8") as handle:
        json.dump(data, handle, ensure_ascii=False, indent=2)
        handle.write("\n")
    tmp.replace(path)


def run(cmd: List[str], cwd: Path, timeout: int = 120, check: bool = True) -> CmdResult:
    joined = " ".join(cmd)
    forbidden_fragments = [
        "git reset --hard",
        "git clean",
        "rm -rf",
        "sudo rm",
        "gh pr merge",
    ]
    if any(fragment in joined for fragment in forbidden_fragments):
        raise BridgeError(f"Comando bloqueado por seguranca: {joined}")
    proc = subprocess.run(cmd, cwd=str(cwd), text=True, capture_output=True, timeout=timeout)
    result = CmdResult(joined, proc.returncode, proc.stdout, proc.stderr)
    if check and proc.returncode != 0:
        raise BridgeError(
            f"Comando falhou ({proc.returncode}): {joined}\nSTDOUT:\n{proc.stdout}\nSTDERR:\n{proc.stderr}"
        )
    return result


def run_shell(command: str, cwd: Path, timeout: int = 180, check: bool = True) -> CmdResult:
    dangerous_patterns = [
        r"(^|[;&|]\s*)git\s+reset\s+--hard(\s|$)",
        r"(^|[;&|]\s*)git\s+clean(\s|$)",
        r"(^|[;&|]\s*)rm\s+-rf(\s|$)",
        r"(^|[;&|]\s*)sudo\s+rm(\s|$)",
        r"(^|[;&|]\s*)gh\s+pr\s+merge(\s|$)",
        r"(^|[;&|]\s*)git\s+push\s+origin\s+main(\s|$)",
    ]
    lowered = command.lower()
    for pattern in dangerous_patterns:
        if re.search(pattern, lowered):
            raise BridgeError(f"Comando shell bloqueado: {command}")
    proc = subprocess.run(["bash", "-lc", command], cwd=str(cwd), text=True, capture_output=True, timeout=timeout)
    result = CmdResult(command, proc.returncode, proc.stdout, proc.stderr)
    if check and proc.returncode != 0:
        raise BridgeError(
            f"Comando falhou ({proc.returncode}): {command}\nSTDOUT:\n{proc.stdout}\nSTDERR:\n{proc.stderr}"
        )
    return result


def assert_repo_clean(repo: Path) -> None:
    status = run(["git", "status", "--porcelain"], repo).stdout.splitlines()
    relevant = []
    for line in status:
        if not line.strip():
            continue
        path = line[3:]
        if " -> " in path:
            path = path.split(" -> ", 1)[1]
        normalized = path.replace("\\", "/")
        if normalized.startswith("agent-bridge/") or normalized.startswith(".agent-bridge"):
            continue
        relevant.append(line)
    if relevant:
        raise BridgeError(
            "Repositorio com alteracoes locais. Abortando para evitar sobrescrever trabalho.\n"
            + "\n".join(relevant)
        )


def safe_branch_name(prefix: str, title: str) -> str:
    slug = title.lower()
    slug = re.sub(r"[^a-z0-9]+", "-", slug).strip("-")[:60]
    if not slug:
        slug = "task"
    return f"{prefix}{slug}-{int(time.time())}"


def is_blocked_path(path: str, config: Dict[str, Any]) -> bool:
    normalized = path.replace("\\", "/")
    for pattern in config.get("blocked_file_patterns", []):
        if fnmatch.fnmatch(normalized, pattern) or fnmatch.fnmatch(Path(normalized).name, pattern):
            return True
    return False


def is_allowed_path(path: str, config: Dict[str, Any]) -> bool:
    normalized = path.replace("\\", "/")
    if is_blocked_path(normalized, config):
        return False
    prefixes = config.get("allowed_file_prefixes", [])
    return any(normalized.startswith(prefix) for prefix in prefixes)


def extract_patch_paths(patch_text: str) -> List[str]:
    paths: List[str] = []
    for line in patch_text.splitlines():
        if line.startswith("diff --git "):
            parts = line.split()
            if len(parts) >= 4:
                b_path = parts[3]
                if b_path.startswith("b/"):
                    paths.append(b_path[2:])
        elif line.startswith("+++ b/"):
            paths.append(line[6:])
    return sorted(set(paths))


def validate_patch(patch_text: str, config: Dict[str, Any]) -> List[str]:
    if len(patch_text.encode("utf-8")) > int(config.get("max_patch_bytes", 200000)):
        raise BridgeError("Patch excede max_patch_bytes")
    paths = extract_patch_paths(patch_text)
    if not paths:
        raise BridgeError("Patch sem arquivos detectaveis")
    blocked = [path for path in paths if not is_allowed_path(path, config)]
    if blocked:
        raise BridgeError(
            "Patch tenta alterar arquivos fora do escopo permitido ou bloqueados: " + ", ".join(blocked)
        )
    if re.search(r"(?i)(password|client_secret|access_token|refresh_token|private key|senha\s*=|token\s*=)", patch_text):
        raise BridgeError("Patch contem padrao parecido com segredo. Abortando.")
    return paths


def create_issue(task: Dict[str, Any], config: Dict[str, Any], repo: Path) -> Dict[str, Any]:
    title = task.get("title") or task.get("issue", {}).get("title")
    body = task.get("body") or task.get("issue", {}).get("body")
    if not title or not body:
        raise BridgeError("create_issue exige title/body")
    tmp_body = repo / ".agent-bridge-issue-body.md"
    tmp_body.write_text(body, encoding="utf-8")
    try:
        args = ["gh", "issue", "create", "--repo", config["repo_full_name"], "--title", title, "--body-file", str(tmp_body)]
        for label in config.get("github_labels", []):
            args += ["--label", label]
        res = run(args, repo, timeout=120)
        return {"status": "OK", "action": "create_issue", "stdout": res.stdout.strip(), "stderr": res.stderr.strip()}
    finally:
        try:
            tmp_body.unlink()
        except FileNotFoundError:
            pass


def apply_patch_pr(task: Dict[str, Any], config: Dict[str, Any], repo: Path) -> Dict[str, Any]:
    title = task.get("title") or "Agent patch"
    patch_text = task.get("patch")
    body = task.get("body") or "PR criado pelo ShopVivaliz Mobile Agent Bridge."
    if not patch_text:
        raise BridgeError("apply_patch_pr exige campo patch")

    changed_paths = validate_patch(patch_text, config)
    assert_repo_clean(repo)

    base = task.get("base_branch") or config.get("default_base_branch", "main")
    branch = task.get("branch") or safe_branch_name(config.get("branch_prefix", "agent/"), title)
    evidence: Dict[str, Any] = {"changed_paths": changed_paths, "commands": []}

    run(["git", "fetch", "origin", base], repo)
    run(["git", "checkout", base], repo)
    run(["git", "pull", "--ff-only", "origin", base], repo)
    run(["git", "checkout", "-b", branch], repo)

    patch_file = repo / ".agent-bridge.patch"
    patch_file.write_text(patch_text, encoding="utf-8")
    try:
        res = run(["git", "apply", "--check", str(patch_file)], repo)
        evidence["commands"].append(res.__dict__)
        res = run(["git", "apply", str(patch_file)], repo)
        evidence["commands"].append(res.__dict__)
    finally:
        try:
            patch_file.unlink()
        except FileNotFoundError:
            pass

    validations: List[Dict[str, Any]] = []
    for command in config.get("validations", []):
        res = run_shell(command, repo, check=True)
        validations.append(res.__dict__)
    evidence["validations"] = validations

    status = run(["git", "status", "--porcelain"], repo).stdout.strip()
    if not status:
        raise BridgeError("Patch nao gerou alteracao real. Abortando sem PR.")

    diffstat = run(["git", "diff", "--stat"], repo).stdout
    evidence["diffstat"] = diffstat

    run(["git", "add"] + changed_paths, repo)
    commit_message = task.get("commit_message") or title
    run(["git", "commit", "-m", commit_message], repo)
    run(["git", "push", "-u", "origin", branch], repo)

    pr_body = (
        body
        + "\n\n## Evidencias do Agent Bridge\n\n```json\n"
        + json.dumps(evidence, ensure_ascii=False, indent=2)
        + "\n```\n"
    )
    tmp_body = repo / ".agent-bridge-pr-body.md"
    tmp_body.write_text(pr_body, encoding="utf-8")
    try:
        args = [
            "gh",
            "pr",
            "create",
            "--repo",
            config["repo_full_name"],
            "--base",
            base,
            "--head",
            branch,
            "--title",
            title,
            "--body-file",
            str(tmp_body),
        ]
        pr = run(args, repo, timeout=120)
    finally:
        try:
            tmp_body.unlink()
        except FileNotFoundError:
            pass

    return {
        "status": "OK",
        "action": "apply_patch_pr",
        "branch": branch,
        "changed_paths": changed_paths,
        "diffstat": diffstat,
        "pr": pr.stdout.strip(),
        "validations_count": len(validations),
    }


def read_file(task: Dict[str, Any], config: Dict[str, Any], repo: Path) -> Dict[str, Any]:
    path = task.get("path")
    if not path:
        raise BridgeError("read_file exige path")
    if not is_allowed_path(path, config):
        raise BridgeError("Arquivo bloqueado ou fora do escopo permitido")
    full = (repo / path).resolve()
    if not str(full).startswith(str(repo.resolve())):
        raise BridgeError("Path traversal bloqueado")
    text = full.read_text(encoding="utf-8", errors="replace")
    return {
        "status": "OK",
        "action": "read_file",
        "path": path,
        "content": text[:20000],
        "truncated": len(text) > 20000,
    }


def run_readonly_audit(task: Dict[str, Any], config: Dict[str, Any], repo: Path) -> Dict[str, Any]:
    commands = [
        "git status --short",
        "git log --oneline -n 15",
        "find .github/workflows -maxdepth 1 -type f -print 2>/dev/null | sort",
        "grep -RInE 'git clean|reset --hard|gh pr merge|--auto|continue-on-error|exit 0|simula|mock|fallback' .github scripts api includes admin 2>/dev/null | head -200",
    ]
    outputs = []
    for command in commands:
        res = run_shell(command, repo, check=False)
        outputs.append(res.__dict__)
    return {"status": "OK", "action": "run_readonly_audit", "outputs": outputs}


def process_task(task_path: Path, config: Dict[str, Any]) -> Dict[str, Any]:
    repo = Path(config["repo_path"]).resolve()
    task = load_json(task_path)
    action = task.get("action")
    if action == "create_issue":
        return create_issue(task, config, repo)
    if action == "apply_patch_pr":
        return apply_patch_pr(task, config, repo)
    if action == "read_file":
        return read_file(task, config, repo)
    if action == "run_readonly_audit":
        return run_readonly_audit(task, config, repo)
    raise BridgeError(f"Acao nao permitida: {action}")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", default="agent-bridge/config.json")
    parser.add_argument("--once", action="store_true")
    parser.add_argument("--sleep", type=int, default=30)
    args = parser.parse_args()

    config_path = Path(args.config)
    if not config_path.exists():
        example = Path("agent-bridge/config.example.json")
        raise SystemExit(f"Config nao encontrada: {config_path}. Copie {example} para {config_path}.")
    config = load_json(config_path)
    repo = Path(config["repo_path"])
    inbox = repo / config.get("inbox_dir", "agent-bridge/inbox")
    outbox = repo / config.get("outbox_dir", "agent-bridge/outbox")
    logs = repo / config.get("log_dir", "agent-bridge/logs")
    inbox.mkdir(parents=True, exist_ok=True)
    outbox.mkdir(parents=True, exist_ok=True)
    logs.mkdir(parents=True, exist_ok=True)

    while True:
        tasks = sorted(inbox.glob("*.json"))
        for task_path in tasks:
            stamp = int(time.time())
            result_path = outbox / f"{task_path.stem}.{stamp}.result.json"
            try:
                result = process_task(task_path, config)
                write_json(result_path, result)
                processed = task_path.with_suffix(".json.done")
                shutil.move(str(task_path), str(processed))
            except Exception as exc:
                err = {"status": "ERROR", "task": task_path.name, "error": str(exc)}
                write_json(result_path, err)
                failed = task_path.with_suffix(".json.failed")
                shutil.move(str(task_path), str(failed))
        if args.once:
            break
        time.sleep(args.sleep)
    return 0


if __name__ == "__main__":
    sys.exit(main())
