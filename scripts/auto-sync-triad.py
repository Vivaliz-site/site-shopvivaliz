#!/usr/bin/env python3
"""
Sincroniza local, GitHub e servidor Ubuntu para o mesmo commit.

Fluxo:
1. valida branch atual e worktree local;
2. fetch + pull --rebase do branch remoto correspondente;
3. push do branch local para origin;
4. atualiza o servidor Ubuntu para o mesmo branch/commit;
5. confirma que local, origin e servidor terminaram no mesmo hash.
"""

from __future__ import annotations

import json
import subprocess
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
CONFIG_PATH = ROOT / "config" / "tri-environment-sync.json"


def run(cmd: list[str], cwd: Path | None = None, check: bool = True) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(
        cmd,
        cwd=str(cwd or ROOT),
        text=True,
        capture_output=True,
    )
    if check and result.returncode != 0:
        raise RuntimeError(
            f"Command failed ({result.returncode}): {' '.join(cmd)}\nSTDOUT:\n{result.stdout}\nSTDERR:\n{result.stderr}"
        )
    return result


def git(*args: str, check: bool = True) -> str:
    return run(["git", *args], cwd=ROOT, check=check).stdout.strip()


def load_config() -> dict:
    if not CONFIG_PATH.is_file():
        raise RuntimeError(f"Missing config file: {CONFIG_PATH}")
    return json.loads(CONFIG_PATH.read_text(encoding="utf-8"))


def ensure_clean_local() -> None:
    status = git("status", "--porcelain", check=True)
    lines = [line for line in status.splitlines() if line.strip()]
    allowed_untracked = {"reports/deploy-validation-local.json"}
    blocking = []
    for line in lines:
        path = line[3:] if len(line) > 3 else line
        path = path.strip()
        if line.startswith("?? ") and path in allowed_untracked:
            continue
        blocking.append(line)
    if blocking:
        raise RuntimeError("Local worktree is not clean:\n" + "\n".join(blocking))


def current_branch() -> str:
    branch = git("branch", "--show-current")
    if not branch:
        raise RuntimeError("Unable to detect current branch.")
    return branch


def ssh_cmd(config: dict, remote_command: str, check: bool = True) -> str:
    ssh_conf = config.get("triad_sync", {}).get("ubuntu_server", {})
    host = ssh_conf.get("host")
    user = ssh_conf.get("user")
    key = ssh_conf.get("ssh_key_windows")
    if not host or not user or not key:
        raise RuntimeError("triad_sync.ubuntu_server config is incomplete.")
    cmd = ["ssh", "-i", key, f"{user}@{host}", remote_command]
    return run(cmd, cwd=ROOT, check=check).stdout.strip()


def sync_server(config: dict, branch: str) -> str:
    server_path = config["triad_sync"]["ubuntu_server"]["repo_path"]
    command = (
        f"cd {server_path} && "
        f"status=$(git status --porcelain) && "
        f"if [ -n \"$status\" ]; then printf '%s\\n' 'Remote worktree dirty; aborting safe sync' \"$status\"; exit 2; fi && "
        f"git fetch origin {branch} --prune && "
        f"git switch {branch} && "
        f"git merge --ff-only origin/{branch} && "
        f"git rev-parse HEAD"
    )
    return ssh_cmd(config, command, check=True).splitlines()[-1].strip()


def server_origin_hash(config: dict, branch: str) -> str:
    server_path = config["triad_sync"]["ubuntu_server"]["repo_path"]
    command = f"cd {server_path} && git rev-parse origin/{branch}"
    return ssh_cmd(config, command, check=True).strip().splitlines()[-1].strip()


def main() -> int:
    config = load_config()
    branch = current_branch()
    print(f"[sync] branch: {branch}")

    ensure_clean_local()
    print("[sync] local worktree clean")

    git("fetch", "origin", branch, "--prune")
    git("pull", "--rebase", "origin", branch)
    print("[sync] local updated from origin")

    local_hash = git("rev-parse", "HEAD")
    git("push", "origin", branch)
    origin_hash = git("rev-parse", f"origin/{branch}")
    print(f"[sync] local/origin hash: {origin_hash}")

    server_hash = sync_server(config, branch)
    server_origin = server_origin_hash(config, branch)
    print(f"[sync] server hash: {server_hash}")

    if not (local_hash == origin_hash == server_hash == server_origin):
        raise RuntimeError(
            "Triad sync mismatch:\n"
            f"local={local_hash}\norigin={origin_hash}\nserver={server_hash}\nserver_origin={server_origin}"
        )

    print("[sync] local, GitHub and Ubuntu are aligned")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"[sync] ERROR: {exc}", file=sys.stderr)
        raise SystemExit(1)
