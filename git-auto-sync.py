#!/usr/bin/env python3
"""Sincroniza um checkout local com a branch canonica sem publicar mudancas.

Fluxo normal:
- exige branch canonica e arvore limpa;
- exige runtime local saudavel antes de qualquer sincronizacao;
- faz fetch;
- aplica somente fast-forward.

Recuperacao excepcional:
- quando o historico remoto foi substituido por uma raiz sanitizada;
- valida `.security/sanitized-history.json` no remoto;
- exige que a raiz real do remoto corresponda exatamente ao marcador;
- realinha apenas uma arvore limpa, sem push e sem criar historico alternativo.

Compatibilidade de transicao:
- um checkout deixado pelo orquestrador legado em `patch/agente-*` pode voltar
  para `main` somente se estiver limpo e o HEAD legado ja for ancestral de
  `origin/main`; branches arbitrarias ou com commits exclusivos continuam
  bloqueadas para nao descartar trabalho.
"""
from __future__ import annotations

import json
import logging
import os
import re
import subprocess
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any

DEFAULT_REPO_DIR = Path(__file__).resolve().parent
REPO_DIR = Path(os.getenv("SHOPVIVALIZ_REPO_DIR", str(DEFAULT_REPO_DIR))).resolve()
DEFAULT_BRANCH = os.getenv("SHOPVIVALIZ_SYNC_BRANCH", "main")
STATUS_FILE = REPO_DIR / "logs" / "tri-environment-sync.json"
SANITIZED_HISTORY_MARKER = ".security/sanitized-history.json"
LEGACY_AGENT_BRANCH_PREFIX = "patch/agente-"
HEALTH_URL = os.getenv(
    "SHOPVIVALIZ_HEALTH_URL",
    "http://127.0.0.1/api/health.php?health=1",
)
MINIMUM_HEALTH_SCORE = float(os.getenv("SHOPVIVALIZ_SYNC_MIN_HEALTH_SCORE", "85"))
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


def local_branch_exists(branch: str) -> bool:
    result = run(["git", "show-ref", "--verify", "--quiet", f"refs/heads/{branch}"])
    return result.returncode == 0


def write_status(payload: dict[str, object]) -> None:
    ensure_logs_dir()
    STATUS_FILE.write_text(
        json.dumps(payload, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )


def check_local_health() -> dict[str, Any]:
    """Valida o runtime local antes de qualquer fetch ou realinhamento Git."""
    req = urllib.request.Request(HEALTH_URL, headers={"Accept": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=10) as response:
            body = response.read().decode("utf-8", "replace")
            data = json.loads(body)
            if response.status not in (200, 207):
                raise RuntimeError(f"Health endpoint returned HTTP {response.status}")
            if not isinstance(data, dict) or not data.get("ok", False):
                checks = data.get("checks") if isinstance(data, dict) else None
                failed_checks = [
                    str(name)
                    for name, passed in (checks.items() if isinstance(checks, dict) else [])
                    if passed is not True
                ]
                detail = ", ".join(failed_checks[:8]) or "sem detalhe de checks"
                score = data.get("health_score_percent") if isinstance(data, dict) else None
                raise RuntimeError(
                    f"Health endpoint reported unhealthy state; score={score}; "
                    f"failed_checks={detail}"
                )
            score = float(data.get("health_score_percent", 0))
            if score < MINIMUM_HEALTH_SCORE:
                raise RuntimeError(
                    f"Health score below threshold: {score} < {MINIMUM_HEALTH_SCORE}"
                )
            return {"ok": True, "status": response.status, "data": data}
    except (
        urllib.error.URLError,
        TimeoutError,
        ValueError,
        json.JSONDecodeError,
        RuntimeError,
    ) as exc:
        return {"ok": False, "error": str(exc)}


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

        legacy_agent_branch = bool(
            current_branch
            and current_branch != branch
            and current_branch.startswith(LEGACY_AGENT_BRANCH_PREFIX)
        )
        if current_branch != branch and not legacy_agent_branch:
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

        health = check_local_health()
        health_data = health.get("data") if isinstance(health.get("data"), dict) else {}
        payload["health"] = {
            "status": "ok" if health.get("ok") else "degraded",
            "endpoint": HEALTH_URL,
            "score": health_data.get("health_score_percent"),
            "queue": health_data.get("queue"),
            "error": None if health.get("ok") else str(health.get("error", "unknown error")),
        }
        if not health.get("ok"):
            log.warning("Runtime atual degradado; mantendo sync para permitir deploy corretivo: %s", payload["health"]["error"])

        run(["git", "fetch", "--prune", "--no-tags", "origin", branch], check=True)
        remote_sha = git_output(["rev-parse", f"origin/{branch}"])
        payload["remote_sha"] = remote_sha

        if legacy_agent_branch:
            legacy_branch = current_branch
            legacy_sha = local_sha
            if not is_ancestor(legacy_sha, remote_sha):
                payload.update(
                    {
                        "action": "blocked-legacy-branch-with-unique-commits",
                        "message": (
                            f"branch legado {legacy_branch} possui historico nao contido "
                            f"em origin/{branch}; nenhuma troca de branch foi aplicada"
                        ),
                    }
                )
                write_status(payload)
                log.error(payload["message"])
                return 6

            if not local_branch_exists(branch):
                payload.update(
                    {
                        "action": "blocked-missing-canonical-branch",
                        "message": (
                            f"branch canonica local {branch} ausente; recuperacao automatica "
                            "recusada para nao inventar estado Git"
                        ),
                    }
                )
                write_status(payload)
                log.error(payload["message"])
                return 7

            run(["git", "switch", branch], check=True)
            current_branch = git_output(["branch", "--show-current"])
            local_sha = git_output(["rev-parse", "HEAD"])
            if current_branch != branch:
                raise RuntimeError("recuperacao de branch legado nao terminou na branch canonica")

            payload.update(
                {
                    "branch": current_branch,
                    "local_sha": local_sha,
                    "legacy_branch_recovery": {
                        "from_branch": legacy_branch,
                        "from_sha": legacy_sha,
                        "status": "switched-to-canonical",
                    },
                }
            )
            log.info(
                "Checkout legado %s recuperado com seguranca para %s",
                legacy_branch,
                branch,
            )

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
