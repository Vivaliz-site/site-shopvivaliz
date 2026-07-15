#!/usr/bin/env python3
"""Sincroniza o checkout local com a branch canonica sem criar commits locais.

Uso principal:
- Ubuntu/Oracle Cloud: manter o checkout publicado alinhado com a branch remota
- Ambientes auxiliares: diagnosticar divergencia sem inventar historico local
"""
from __future__ import annotations

import json
import logging
import os
import subprocess
import sys
import shutil
import tempfile
from pathlib import Path

REPO_DIR = Path(__file__).resolve().parent
DEFAULT_BRANCH = os.getenv("SHOPVIVALIZ_SYNC_BRANCH", "main")
STATUS_FILE = REPO_DIR / "logs" / "tri-environment-sync.json"
PRESERVE_PATHS = {
    ".env.local",
    "logs",
    "storage/private",
    ".agent-heartbeats",
    "reports/hourly",
    "tasks-queue.json",
    "config/__pycache__",
    "storage/order-idempotency",
    "storage/order-rate-limit",
    "storage/orders",
    ".claude/scheduled_tasks.lock",
    ".claude/settings.local.json",
}
ALLOWED_DIRTY_PATHS = PRESERVE_PATHS | {
    # Rebuilt caches may be dirty, but the canonical branch copy should win on
    # deploy so a stale or empty runtime cache cannot overwrite a newer seed.
    "api/catalog/fallback-products.json",
    "storage/products-cache-ativos.json",
}

logging.basicConfig(level=logging.INFO, format="%(asctime)s - %(message)s")
log = logging.getLogger(__name__)


def run(cmd: list[str], *, check: bool = False) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(
        cmd,
        cwd=REPO_DIR,
        capture_output=True,
        text=True,
        timeout=120,
        env=os.environ.copy(),
    )
    if check and result.returncode != 0:
        raise RuntimeError(result.stderr.strip() or result.stdout.strip() or "command failed")
    return result


def ensure_logs_dir() -> None:
    STATUS_FILE.parent.mkdir(parents=True, exist_ok=True)


def git_output(args: list[str]) -> str:
    return run(["git", *args], check=True).stdout.strip()


def tracked_dirty_paths() -> list[str]:
    status = run(["git", "status", "--porcelain"]).stdout.splitlines()
    paths: list[str] = []
    for line in status:
        if not line:
            continue
        path = line[3:].strip()
        if path:
            paths.append(path)
    return paths


def unsafe_dirty_paths(paths: list[str]) -> list[str]:
    unsafe: list[str] = []
    for path in paths:
        normalized = path.replace("\\", "/")
        if normalized in ALLOWED_DIRTY_PATHS:
            continue
        if any(normalized == keep or normalized.startswith(keep + "/") for keep in ALLOWED_DIRTY_PATHS):
            continue
        unsafe.append(normalized)
    return unsafe


def is_preserved_path(path: str) -> bool:
    normalized = path.replace("\\", "/").rstrip("/")
    return any(normalized == keep or normalized.startswith(keep + "/") for keep in PRESERVE_PATHS)


def snapshot_preserved_paths(paths: list[str], backup_root: Path) -> list[str]:
    """Copy dirty runtime paths outside the checkout before Git updates it."""
    copied: list[str] = []
    for path in sorted(set(paths)):
        normalized = path.replace("\\", "/").rstrip("/")
        if not normalized or not is_preserved_path(normalized):
            continue
        source = REPO_DIR / normalized
        if not source.exists():
            continue
        destination = backup_root / normalized
        destination.parent.mkdir(parents=True, exist_ok=True)
        if source.is_dir():
            shutil.copytree(source, destination, dirs_exist_ok=True)
        else:
            shutil.copy2(source, destination)
        copied.append(normalized)
    return copied


def restore_preserved_paths(paths: list[str], backup_root: Path) -> None:
    for normalized in paths:
        source = backup_root / normalized
        destination = REPO_DIR / normalized
        destination.parent.mkdir(parents=True, exist_ok=True)
        if source.is_dir():
            shutil.copytree(source, destination, dirs_exist_ok=True)
        else:
            shutil.copy2(source, destination)


def write_status(payload: dict[str, object]) -> None:
    ensure_logs_dir()
    STATUS_FILE.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def main() -> int:
    branch = DEFAULT_BRANCH
    ensure_logs_dir()
    log.info("Auto-sync iniciado para branch canonica %s", branch)

    try:
        run(["git", "fetch", "origin", branch], check=True)
        local_sha = git_output(["rev-parse", "HEAD"])
        remote_sha = git_output(["rev-parse", f"origin/{branch}"])
        current_branch = git_output(["branch", "--show-current"])
        dirty = tracked_dirty_paths()
        unsafe = unsafe_dirty_paths(dirty)

        payload: dict[str, object] = {
            "ok": True,
            "branch": current_branch,
            "canonical_branch": branch,
            "local_sha": local_sha,
            "remote_sha": remote_sha,
            "dirty_paths": dirty,
            "unsafe_dirty_paths": unsafe,
            "action": "noop",
        }

        if current_branch != branch:
            payload["ok"] = False
            payload["action"] = "blocked-wrong-branch"
            payload["message"] = f"checkout atual em {current_branch}, esperado {branch}"
            write_status(payload)
            log.error(payload["message"])
            return 2

        if unsafe:
            payload["ok"] = False
            payload["action"] = "blocked-unsafe-dirty-tree"
            payload["message"] = "working tree contem alteracoes rastreadas fora das areas preservadas"
            write_status(payload)
            log.error("%s: %s", payload["message"], ", ".join(unsafe))
            return 3

        if local_sha == remote_sha:
            payload["message"] = "checkout ja alinhado com a branch canonica"
            write_status(payload)
            log.info(payload["message"])
            return 0

        with tempfile.TemporaryDirectory(prefix="shopvivaliz-sync-") as temp_dir:
            backup_root = Path(temp_dir)
            preserved = snapshot_preserved_paths(dirty, backup_root)
            run(["git", "reset", "--hard", f"origin/{branch}"], check=True)
            restore_preserved_paths(preserved, backup_root)
        payload["action"] = "hard-reset-to-canonical"
        payload["local_sha_after"] = git_output(["rev-parse", "HEAD"])
        payload["preserved_paths"] = preserved
        payload["message"] = "checkout alinhado via hard reset para a branch canonica"
        write_status(payload)
        log.info(payload["message"])
        return 0
    except Exception as exc:  # pragma: no cover - exec path
        payload = {
            "ok": False,
            "branch": branch,
            "action": "error",
            "message": str(exc),
        }
        write_status(payload)
        log.error("Auto-sync falhou: %s", exc)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
