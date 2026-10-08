#!/usr/bin/env python3
"""Task-owned disposable Git clone registry with fail-closed terminal cleanup.

Only explicitly registered clones are eligible for deletion. Never sweep random
/tmp directories, dirty Git work, unpushed commits, live processes or locked
worktrees. State files live alongside durable agent-task-state checkpoints.
"""
from __future__ import annotations

import argparse
from contextlib import contextmanager
from datetime import datetime, timezone
import fcntl
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
from typing import Iterable

DEFAULT_ROOTS = (
    Path("/tmp"),
    Path("/home/ubuntu/worktrees"),
    Path("/home/ubuntu/chat-workspaces"),
    Path("/home/ubuntu/shopvivaliz-deploy/worktrees"),
)
TASK_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,159}$")
REGISTRY_NAME = "_agent_clone_registry"
GIT_TIMEOUT = 15


def _state_dir(state_dir: Path | str | None = None) -> Path:
    if state_dir is not None:
        return Path(state_dir)
    configured = os.environ.get("SHOPVIVALIZ_AGENT_TASK_STATE_DIR", "")
    if configured:
        return Path(configured)
    prod = Path("/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state")
    if prod.is_dir():
        return prod
    return Path(__file__).resolve().parents[1] / "storage/private/agent-task-state"


def _task_id(task_id: str) -> str:
    if not TASK_RE.fullmatch(str(task_id)) or str(task_id) in {".", ".."}:
        raise ValueError("invalid_task_id")
    return str(task_id)


def _status(task_id: str, state_dir: Path) -> str:
    try:
        row = json.loads((state_dir / (task_id + ".json")).read_text(encoding="utf-8"))
        if isinstance(row, dict) and row.get("task_id") == task_id:
            return str(row.get("status", ""))
    except (OSError, ValueError):
        pass
    return ""



def _verified_completion(task_id: str, state_dir: Path) -> bool:
    """Never reclaim work unless terminal verification evidence is persisted."""
    try:
        payload = json.loads((state_dir / (task_id + ".json")).read_text(encoding="utf-8"))
        return (
            isinstance(payload, dict)
            and payload.get("task_id") == task_id
            and payload.get("status") == "CONCLUIDO"
            and bool(payload.get("evidence"))
            and bool(payload.get("verification"))
            and bool(payload.get("completed_at"))
        )
    except (OSError, ValueError):
        return False


def _normalize_path(path: Path | str, allowed_roots: Iterable[Path | str]) -> Path:
    supplied = Path(path).expanduser().absolute()
    if str(supplied) != str(supplied.resolve(strict=True)):
        raise ValueError("clone_path_symlink_or_noncanonical")
    if not supplied.is_dir() or supplied.is_symlink():
        raise ValueError("clone_directory_required")
    for root in allowed_roots:
        trusted = Path(root).expanduser().resolve(strict=True)
        if supplied != trusted and trusted in supplied.parents:
            return supplied
    raise ValueError("clone_path_outside_ephemeral_roots")


def _git(path: Path, *arguments: str) -> str:
    # Ignore inherited Git overrides: they can silently reroute repository
    # inspection to a different checkout and authorize unsafe removal.
    env = {k: v for k, v in os.environ.items() if not k.startswith("GIT_")}
    p = subprocess.run(
        ["git", "-C", str(path), *arguments],
        text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        timeout=GIT_TIMEOUT, check=False, env=env,
    )
    if p.returncode != 0:
        raise ValueError("git_check_failed")
    return p.stdout.strip()


def _validate_git_root(path: Path) -> None:
    if not (path / ".git").exists():
        raise ValueError("git_metadata_missing")
    top = Path(_git(path, "rev-parse", "--show-toplevel")).resolve()
    if top != path:
        raise ValueError("not_git_repository_root")


def _active(path: Path) -> bool:
    proc = Path("/proc")
    if not proc.is_dir():
        return True  # no process visibility: fail closed
    # A completion invoked inside the clone must wait for the agent to leave.
    current = Path.cwd().resolve()
    if current == path or path in current.parents:
        return True
    needle = str(path)
    try:
        entries = list(proc.iterdir())
    except OSError:
        return True
    for entry in entries:
        if not entry.name.isdecimal() or int(entry.name) == os.getpid():
            continue
        try:
            cwd = os.readlink(entry / "cwd")
            if cwd == needle or cwd.startswith(needle + os.sep):
                return True
        except (PermissionError, FileNotFoundError, ProcessLookupError):
            pass
        try:
            argv = (entry / "cmdline").read_bytes().decode(errors="replace")
            if needle in argv:
                return True
        except (PermissionError, FileNotFoundError, ProcessLookupError):
            pass
    return False


def _clone_identity(path: Path) -> list[int]:
    stat = path.stat()
    return [stat.st_dev, stat.st_ino]


def _safe_to_remove(path: Path) -> tuple[bool, str, bool, str]:
    try:
        _validate_git_root(path)
        gitdir = Path(_git(path, "rev-parse", "--absolute-git-dir")).resolve()
        common = Path(_git(path, "rev-parse", "--path-format=absolute", "--git-common-dir")).resolve()
        linked = (path / ".git").is_file()
        if (gitdir / "locked").exists() or (gitdir / "locked").is_symlink():
            return False, "git_locked", linked, str(common)
        if _active(path):
            return False, "process_active", linked, str(common)
        if _git(path, "status", "--porcelain", "--untracked-files=all"):
            return False, "dirty_or_untracked", linked, str(common)
        if not _git(path, "for-each-ref", "--format=%(refname)", "refs/remotes"):
            return False, "no_remote_tracking_refs", linked, str(common)
        if int(_git(path, "rev-list", "--count", "--all", "--not", "--remotes")) > 0:
            return False, "unpushed_branches", linked, str(common)
        if int(_git(path, "rev-list", "--count", "HEAD", "--not", "--remotes")) > 0:
            return False, "unpushed_head", linked, str(common)
        if not linked:
            count = _git(path, "worktree", "list", "--porcelain").count("worktree ")
            if count != 1:
                return False, "other_linked_worktrees", linked, str(common)
        return True, "safe", linked, str(common)
    except (OSError, ValueError, subprocess.TimeoutExpired):
        return False, "metadata_unverifiable", False, ""


@contextmanager
def _registry_lock(state_dir: Path):
    directory = state_dir / REGISTRY_NAME
    directory.mkdir(parents=True, exist_ok=True, mode=0o700)
    lock_path = directory / ".lock"
    with lock_path.open("a+") as fp:
        os.chmod(lock_path, 0o600)
        fcntl.flock(fp, fcntl.LOCK_EX)
        try:
            yield directory
        finally:
            fcntl.flock(fp, fcntl.LOCK_UN)


def _write_registry(path: Path, content: dict) -> None:
    fd, filename = tempfile.mkstemp(prefix=".owned-", dir=path.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as fp:
            json.dump(content, fp, sort_keys=True)
            fp.flush()
            os.fsync(fp.fileno())
        os.chmod(filename, 0o600)
        os.replace(filename, path)
    finally:
        if os.path.exists(filename):
            os.unlink(filename)


def register_clone(
    task_id: str, path: Path | str, *,
    state_dir: Path | str | None = None,
    allowed_roots: Iterable[Path | str] = DEFAULT_ROOTS,
) -> dict:
    task = _task_id(task_id)
    state = _state_dir(state_dir)
    clone = _normalize_path(path, allowed_roots)
    _validate_git_root(clone)
    with _registry_lock(state) as registry:
        if _status(task, state) not in {"RUNNING", "READY_TO_COMPLETE"}:
            raise ValueError("task_not_active")
        manifest = registry / (task + ".json")
        try:
            data = json.loads(manifest.read_text())
        except FileNotFoundError:
            data = {"task_id": task, "paths": [], "identities": {},
                    "created_at": datetime.now(timezone.utc).isoformat()}
        identities = data.setdefault("identities", {})
        if data.get("task_id") != task or not isinstance(identities, dict) or not isinstance(data.get("paths"), list):
            raise ValueError("clone_registry_invalid")
        identity = _clone_identity(clone)
        if str(clone) in data["paths"]:
            if identities.get(str(clone)) != identity:
                raise ValueError("registered_clone_identity_changed")
        else:
            data["paths"].append(str(clone))
            identities[str(clone)] = identity
        _write_registry(manifest, data)
    return {"ok": True, "task_id": task, "registered": str(clone)}


def cleanup_task(
    task_id: str, *,
    state_dir: Path | str | None = None,
    allowed_roots: Iterable[Path | str] = DEFAULT_ROOTS,
) -> dict:
    task = _task_id(task_id)
    state = _state_dir(state_dir)
    removed = 0
    preserved: list[dict] = []
    with _registry_lock(state) as registry:
        manifest = registry / (task + ".json")
        if not manifest.is_file():
            return {"ok": True, "removed": 0, "preserved": 0, "reasons": []}
        try:
            data = json.loads(manifest.read_text())
        except (OSError, ValueError):
            return {"ok": False, "removed": 0, "preserved": 1, "reasons": ["registry_unreadable"]}
        paths = data.get("paths", [])
        identities = data.get("identities", {})
        if data.get("task_id") != task or not isinstance(paths, list) or not isinstance(identities, dict):
            return {"ok": False, "removed": 0, "preserved": 1, "reasons": ["registry_invalid"]}
        if not _verified_completion(task, state):
            return {"ok": True, "removed": 0, "preserved": len(paths), "reasons": ["task_not_completed"]}
        remaining = []
        for raw in paths:
            if not isinstance(raw, str):
                remaining.append(raw)
                preserved.append({"reason": "registry_path_invalid"})
                continue
            try:
                candidate = _normalize_path(raw, allowed_roots)
            except (ValueError, OSError):
                # May have been deleted manually; keep dubious paths out of rm.
                if not Path(str(raw)).exists():
                    continue
                remaining.append(raw)
                preserved.append({"reason": "path_invalid"})
                continue
            if identities.get(raw) != _clone_identity(candidate):
                remaining.append(raw)
                preserved.append({"reason": "registered_clone_identity_changed"})
                continue
            safe, reason, linked, common = _safe_to_remove(candidate)
            if not safe:
                remaining.append(raw)
                preserved.append({"reason": reason})
                continue
            # Repeat the safeguards just before mutation; a clean checkout can
            # become dirty or active while another agent takes ownership.
            safe, reason, linked, common = _safe_to_remove(candidate)
            if not safe:
                remaining.append(raw)
                preserved.append({"reason": reason})
                continue
            try:
                if identities.get(raw) != _clone_identity(candidate):
                    remaining.append(raw)
                    preserved.append({"reason": "registered_clone_identity_changed"})
                    continue
                if linked:
                    env = {k: v for k, v in os.environ.items() if not k.startswith("GIT_")}
                    p = subprocess.run(
                        ["git", "--git-dir=" + common, "worktree", "remove", str(candidate)],
                        text=True, capture_output=True, check=False, timeout=GIT_TIMEOUT, env=env,
                    )
                    if p.returncode != 0:
                        raise OSError("worktree_remove_refused")
                else:
                    shutil.rmtree(candidate)
                removed += 1
            except (OSError, subprocess.TimeoutExpired):
                remaining.append(raw)
                preserved.append({"reason": "deletion_failed"})
        if remaining:
            data["paths"] = remaining
            data["identities"] = {raw: identities[raw] for raw in remaining if isinstance(raw, str) and raw in identities}
            _write_registry(manifest, data)
        else:
            manifest.unlink(missing_ok=True)
    return {"ok": not preserved, "removed": removed, "preserved": len(preserved),
            "reasons": [entry["reason"] for entry in preserved]}


def sweep_completed(*, state_dir: Path | str | None = None, allowed_roots: Iterable[Path | str] = DEFAULT_ROOTS) -> dict:
    state = _state_dir(state_dir)
    registry = state / REGISTRY_NAME
    if not registry.is_dir():
        return {"ok": True, "removed": 0, "preserved": 0, "tasks": 0}
    removed = preserved = tasks = 0
    for manifest in sorted(registry.glob("*.json")):
        task = manifest.stem
        if not TASK_RE.fullmatch(task):
            continue
        result = cleanup_task(task, state_dir=state, allowed_roots=allowed_roots)
        tasks += 1
        removed += result["removed"]
        preserved += result["preserved"]
    return {"ok": True, "removed": removed, "preserved": preserved, "tasks": tasks}


def clone_for_task(task_id: str, repository: str, *,
                   state_dir: Path | str | None = None,
                   root: Path | str = "/tmp/shopvivaliz-agent-clones") -> Path:
    task = _task_id(task_id)
    state = _state_dir(state_dir)
    if _status(task, state) not in {"RUNNING", "READY_TO_COMPLETE"}:
        raise ValueError("task_not_active")
    parent = Path(root).expanduser()
    if parent.is_symlink():
        raise ValueError("clone_root_symlink")
    parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    if not repository or str(repository).startswith("-"):
        raise ValueError("invalid_clone_source")
    dest = Path(tempfile.mkdtemp(prefix=task + "-", dir=parent))
    try:
        env = {k: v for k, v in os.environ.items() if not k.startswith("GIT_")}
        p = subprocess.run(["git", "clone", "--", str(repository), str(dest)],
                           capture_output=True, text=True, check=False, timeout=240, env=env)
        if p.returncode != 0:
            raise ValueError("git_clone_failed")
        register_clone(task, dest, state_dir=state)
        return dest
    except Exception:
        # Only remove this freshly allocated directory; never a pre-existing
        # path or previously registered human worktree.
        shutil.rmtree(dest, ignore_errors=True)
        raise


def _main() -> int:
    parser = argparse.ArgumentParser(description="Task-owned temporary Git clones")
    parser.add_argument("--state-dir", default=None)
    sub = parser.add_subparsers(dest="action", required=True)
    reg = sub.add_parser("register")
    reg.add_argument("--task", required=True)
    reg.add_argument("--path", required=True)
    new = sub.add_parser("clone")
    new.add_argument("--task", required=True)
    new.add_argument("--repo", required=True)
    done = sub.add_parser("cleanup")
    done.add_argument("--task", required=True)
    sub.add_parser("sweep")
    args = parser.parse_args()
    try:
        if args.action == "register":
            result = register_clone(args.task, args.path, state_dir=args.state_dir)
        elif args.action == "clone":
            result = {"ok": True, "path": str(clone_for_task(args.task, args.repo, state_dir=args.state_dir))}
        elif args.action == "cleanup":
            result = cleanup_task(args.task, state_dir=args.state_dir)
        else:
            result = sweep_completed(state_dir=args.state_dir)
    except (ValueError, OSError, subprocess.TimeoutExpired) as exc:
        print(json.dumps({"ok": False, "error": str(exc)}))
        return 3
    print(json.dumps(result, ensure_ascii=False))
    return 0 if result.get("ok", False) else 4


if __name__ == "__main__":
    raise SystemExit(_main())
