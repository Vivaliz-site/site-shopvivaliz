#!/usr/bin/env python3
"""Gerenciador seguro de rollback.

Por padrao, apenas cria um plano e valida o commit alvo. Quando executado com
``--apply``, cria uma branch isolada e usa ``git revert``. Nunca executa
``reset --hard`` nem ``push --force`` e recusa operar diretamente em branches
protegidas.
"""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
LOG_PATH = ROOT / "logs" / "rollbacks.jsonl"
PROTECTED_BRANCHES = {"main", "master", "production", "prod"}
COMMIT_RE = re.compile(r"^[0-9a-fA-F]{7,40}$")


def run(*args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["git", *args],
        cwd=ROOT,
        check=check,
        capture_output=True,
        text=True,
    )


def current_branch() -> str:
    return run("branch", "--show-current").stdout.strip()


def resolve_commit(value: str) -> str:
    if not COMMIT_RE.fullmatch(value):
        raise ValueError("commit deve conter somente hexadecimal Git")
    resolved = run("rev-parse", "--verify", f"{value}^{{commit}}").stdout.strip()
    if not resolved:
        raise ValueError("commit nao encontrado")
    return resolved


def ensure_clean_tree() -> None:
    if run("status", "--porcelain").stdout.strip():
        raise RuntimeError("arvore de trabalho possui alteracoes; rollback cancelado")


def commit_summary(commit: str) -> dict[str, object]:
    subject = run("show", "-s", "--format=%s", commit).stdout.strip()
    files = [line for line in run("diff-tree", "--no-commit-id", "--name-only", "-r", commit).stdout.splitlines() if line]
    return {"commit": commit, "subject": subject, "files": files, "file_count": len(files)}


def write_log(payload: dict[str, object]) -> None:
    LOG_PATH.parent.mkdir(parents=True, exist_ok=True)
    with LOG_PATH.open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(payload, ensure_ascii=False) + "\n")


def create_revert_branch(commit: str, task_id: str) -> dict[str, object]:
    ensure_clean_tree()
    branch = current_branch()
    if branch in PROTECTED_BRANCHES:
        raise RuntimeError(f"execucao direta em branch protegida '{branch}' e proibida")

    timestamp = datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S")
    safe_task = re.sub(r"[^A-Za-z0-9._-]+", "-", task_id).strip("-") or "rollback"
    revert_branch = f"rollback/{safe_task}-{timestamp}"

    run("switch", "-c", revert_branch)
    result = run("revert", "--no-edit", commit, check=False)
    if result.returncode != 0:
        run("revert", "--abort", check=False)
        raise RuntimeError(result.stderr.strip() or "git revert falhou")

    new_commit = run("rev-parse", "HEAD").stdout.strip()
    return {
        "ok": True,
        "mode": "applied-locally",
        "branch": revert_branch,
        "reverted_commit": commit,
        "revert_commit": new_commit,
        "next_step": "publique esta branch e abra um pull request apos os testes",
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Planejar ou criar rollback reversivel")
    parser.add_argument("commit", help="SHA do commit que deve ser revertido")
    parser.add_argument("--task-id", default="manual", help="identificador para auditoria")
    parser.add_argument("--apply", action="store_true", help="cria branch local e executa git revert")
    args = parser.parse_args()

    try:
        commit = resolve_commit(args.commit)
        summary = commit_summary(commit)
        payload: dict[str, object] = {
            "timestamp": datetime.now(timezone.utc).isoformat(),
            "task_id": args.task_id,
            "requested_by": "rollback-manager",
            "summary": summary,
        }
        if args.apply:
            payload.update(create_revert_branch(commit, args.task_id))
        else:
            payload.update({
                "ok": True,
                "mode": "plan-only",
                "message": "nenhuma alteracao foi aplicada; use --apply em branch nao protegida",
            })
        write_log(payload)
        print(json.dumps(payload, ensure_ascii=False, indent=2))
        return 0
    except (ValueError, RuntimeError, subprocess.CalledProcessError) as exc:
        error = {
            "timestamp": datetime.now(timezone.utc).isoformat(),
            "task_id": args.task_id,
            "ok": False,
            "error": str(exc),
        }
        write_log(error)
        print(json.dumps(error, ensure_ascii=False), file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
