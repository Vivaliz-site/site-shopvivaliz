#!/usr/bin/env python3
"""Sincroniza um checkout local com a branch canonica sem publicar mudancas.

Fluxo normal:
- exige branch canonica e arvore limpa;
- faz fetch;
- aplica somente fast-forward.

Recuperacao excepcional:
- quando o historico remoto foi substituido por uma raiz sanitizada;
- valida `.security/sanitized-history.json` no remoto;
- exige que a raiz real do remoto corresponda exatamente ao marcador;
- realinha apenas uma arvore limpa, sem push e sem criar historico alternativo.
"""
from __future__ import annotations

import json
import logging
import os
import re
import subprocess
from pathlib import Path

DEFAULT_REPO_DIR = Path(__file__).resolve().parent
REPO_DIR = Path(os.getenv("SHOPVIVALIZ_REPO_DIR", str(DEFAULT_REPO_DIR))).resolve()
DEFAULT_BRANCH = os.getenv("SHOPVIVALIZ_SYNC_BRANCH", "main")
STATUS_FILE = REPO_DIR / "logs" / "tri-environment-sync.json"
SANITIZED_HISTORY_MARKER = ".security/sanitized-history.json"
SHA_RE = re.compile(r"^[0-9a-f]{40}$")

logging.basicConfig(level=logging.INFO, format="%(asctime)s - %(message)s")
log = logging.getLogger(__name__)


def run(cmd: list[str], *, check: bool = False) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(
        cmd,
        cwd=REPO_DIR,
        capture_output=True,
        text=True,
        timeout=180,
        env=os.environ.copy(),
    )
    if check and result.returncode != 0:
        detail = result.stderr.strip() or result.stdout.strip() or "command failed"
        raise RuntimeError(f"{' '.join(cmd)}: {detail}")
    return result


def ensure_logs_dir() -> None:
    STATUS_FILE.parent.mkdir(parents=True, exist_ok=True)


def git_output(args: list[str]) -> str:
    return run(["git", *args], check=True).stdout.strip()


def tracked_dirty_paths() -> list[str]:
    result = run(["git", "status", "--porcelain"], check=True)
    paths: list[str] = []
    for line in result.stdout.splitlines():
        if not line:
            continue
        path = line[3:].strip()
        if path:
            paths.append(path.replace("\\", "/"))
    return paths


def write_status(payload: dict[str, object]) -> None:
    ensure_logs_dir()
    STATUS_FILE.write_text(
        json.dumps(payload, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )


def is_ancestor(ancestor: str, descendant: str) -> bool:
    result = run(["git", "merge-base", "--is-ancestor", ancestor, descendant])
    if result.returncode == 0:
        return True
    if result.returncode == 1:
        return False
    detail = result.stderr.strip() or result.stdout.strip() or "merge-base failed"
    raise RuntimeError(detail)


def verified_sanitized_history(branch: str) -> dict[str, str] | None:
    marker_ref = f"origin/{branch}:{SANITIZED_HISTORY_MARKER}"
    marker_result = run(["git", "show", marker_ref])
    if marker_result.returncode != 0:
        return None

    try:
        marker = json.loads(marker_result.stdout)
    except json.JSONDecodeError:
        return None

    root_sha = str(marker.get("root_sha", "")).lower()
    if not SHA_RE.fullmatch(root_sha):
        return None

    roots = [
        item.strip().lower()
        for item in git_output(
            ["rev-list", "--max-parents=0", f"origin/{branch}"]
        ).splitlines()
        if item.strip()
    ]
    if roots != [root_sha]:
        return None

    remote_tip = git_output(["rev-parse", f"origin/{branch}"]).lower()
    if not is_ancestor(root_sha, remote_tip):
        return None

    return {
        "root_sha": root_sha,
        "remote_tip": remote_tip,
        "expected_tag": str(marker.get("expected_tag", "")),
    }


def realign_clean_checkout(branch: str, local_sha: str, remote_sha: str) -> None:
    """Move a branch local para o remoto somente apos validacao da raiz limpa."""
    run(["git", "switch", "--detach", remote_sha], check=True)
    try:
        run(["git", "branch", "--force", branch, remote_sha], check=True)
        run(["git", "switch", branch], check=True)
    except Exception:
        run(["git", "branch", "--force", branch, local_sha])
        run(["git", "switch", branch])
        raise

    current_branch = git_output(["branch", "--show-current"])
    current_sha = git_output(["rev-parse", "HEAD"])
    if current_branch != branch or current_sha != remote_sha:
        raise RuntimeError("realinhamento terminou em branch ou SHA inesperado")


def main() -> int:
    branch = DEFAULT_BRANCH
    ensure_logs_dir()
    log.info("Auto-sync iniciado para branch canonica %s", branch)

    payload: dict[str, object] = {
        "ok": False,
        "canonical_branch": branch,
        "action": "initializing",
    }

    try:
        local_sha = git_output(["rev-parse", "HEAD"])
        current_branch = git_output(["branch", "--show-current"])
        dirty = tracked_dirty_paths()
        payload.update(
            {
                "branch": current_branch,
                "local_sha": local_sha,
                "remote_sha": None,
                "dirty_paths": dirty,
            }
        )

        if current_branch != branch:
            payload["action"] = "blocked-wrong-branch"
            payload["message"] = (
                f"checkout atual em {current_branch or 'detached'}, esperado {branch}"
            )
            write_status(payload)
            log.error(payload["message"])
            return 2

        if dirty:
            payload["action"] = "blocked-dirty-tree"
            payload["message"] = (
                "working tree contem alteracoes; sync seguro abortado antes do fetch"
            )
            write_status(payload)
            log.error("%s: %s", payload["message"], ", ".join(dirty))
            return 3

        run(["git", "fetch", "--prune", "--no-tags", "origin", branch], check=True)
        remote_sha = git_output(["rev-parse", f"origin/{branch}"])
        payload["remote_sha"] = remote_sha

        if local_sha == remote_sha:
            payload.update(
                {
                    "ok": True,
                    "action": "noop",
                    "message": "checkout ja alinhado com a branch canonica",
                }
            )
            write_status(payload)
            log.info(payload["message"])
            return 0

        if is_ancestor(local_sha, remote_sha):
            run(["git", "merge", "--ff-only", f"origin/{branch}"], check=True)
            payload.update(
                {
                    "ok": True,
                    "action": "fast-forward-to-canonical",
                    "local_sha_after": git_output(["rev-parse", "HEAD"]),
                    "message": "checkout alinhado por fast-forward",
                }
            )
            write_status(payload)
            log.info(payload["message"])
            return 0

        sanitized = verified_sanitized_history(branch)
        if sanitized is None:
            payload.update(
                {
                    "action": "blocked-diverged-history",
                    "message": (
                        "historico divergiu e o remoto nao possui uma raiz sanitizada "
                        "verificavel; nenhuma alteracao local foi aplicada"
                    ),
                }
            )
            write_status(payload)
            log.error(payload["message"])
            return 4

        realign_clean_checkout(branch, local_sha, remote_sha)
        payload.update(
            {
                "ok": True,
                "action": "realigned-to-verified-sanitized-history",
                "local_sha_before": local_sha,
                "local_sha_after": git_output(["rev-parse", "HEAD"]),
                "sanitized_root_sha": sanitized["root_sha"],
                "sanitized_expected_tag": sanitized["expected_tag"],
                "message": (
                    "checkout limpo realinhado para o historico sanitizado verificado"
                ),
            }
        )
        write_status(payload)
        log.info(payload["message"])
        return 0
    except Exception as exc:  # pragma: no cover - caminho operacional
        payload.update(
            {
                "ok": False,
                "action": "error",
                "message": str(exc),
            }
        )
        write_status(payload)
        log.error("Auto-sync falhou: %s", exc)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
