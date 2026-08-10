#!/usr/bin/env python3
"""
Git Auto-Sync - Multi-Agent Safe Synchronization Orchestrator

This script functions as a rigid state orchestrator that manages multiple simultaneous agents
and prevents one AI from overwriting another's work in a shared local Docker environment.

Key features:
- File locking (flock) to prevent concurrent execution
- Agent identification via AGENT_ID environment variable (fallback to hostname)
- Stash local changes before any Git operations
- Checkout/pull of main branch
- Isolated temporary branches per agent (patch/agente-<AGENT_ID>)
- Reapply stash only if changes exist for that specific agent
- Detailed terminal logging showing agent ID, branch name, stash status, and final SHA
- JSON status file in logs/tri-environment-sync.json for state tracking
"""

import os
import sys
import json
import subprocess
import socket
import time
from pathlib import Path
from typing import Optional, List, Dict, Any
from datetime import datetime

# Configuration
REPO_ROOT = Path(__file__).parent.resolve()
LOCK_FILE = Path("/tmp/git_auto_sync.lock")
STATUS_FILE = REPO_ROOT / "logs" / "tri-environment-sync.json"
DEFAULT_BRANCH = "main"
REMOTE_NAME = "origin"
SANITIZED_HISTORY_MARKER = ".sanitized_history_root"


def run(cmd: List[str], check: bool = False, capture: bool = True) -> subprocess.CompletedProcess[str]:
    """Execute a command and return the result."""
    try:
        result = subprocess.run(
            cmd,
            cwd=REPO_ROOT,
            capture_output=capture,
            text=True,
            check=check
        )
        return result
    except subprocess.CalledProcessError as e:
        if capture:
            print(f"\u274c Command failed: {' '.join(cmd)}")
            print(f"   stderr: {e.stderr}")
        raise


def acquire_lock() -> None:
    """Acquire exclusive file lock to prevent concurrent execution."""
    import time
    max_wait = 30  # Maximum wait time in seconds
    wait_interval = 0.1  # Wait interval in seconds
    waited = 0

    while waited < max_wait:
        try:
            # Try to create the lock file exclusively (Windows compatible)
            with open(LOCK_FILE, "x") as lock_f:
                lock_f.write(f"{os.getpid()}\n")
            # Keep lock file for duration of script
            globals()['_lock_file_handle'] = LOCK_FILE
            print(f"[{get_agent_id()}] Lock acquired")
            return
        except FileExistsError:
            # Lock file exists, check if it's stale
            try:
                with open(LOCK_FILE, "r") as lock_f:
                    pid_content = lock_f.read().strip()

                # Check if process is still running
                if pid_content:
                    try:
                        # Try to import psutil for process checking
                        import psutil
                        if psutil.pid_exists(int(pid_content)):
                            print(f"\u274c Another git-auto-sync instance is running (PID: {pid_content}). Exiting.")
                            sys.exit(1)
                        else:
                            # Stale lock, remove it
                            os.remove(LOCK_FILE)
                            continue
                    except (ImportError, ValueError):
                        # If psutil not available or PID invalid, assume stale
                        os.remove(LOCK_FILE)
                        continue
                else:
                    # Empty lock file, remove it
                    os.remove(LOCK_FILE)
                    continue
            except Exception:
                # If we can't read the lock file, remove it
                try:
                    os.remove(LOCK_FILE)
                except:
                    pass
                continue

        waited += wait_interval
        time.sleep(wait_interval)

    print(f"\u274c Could not acquire lock after {max_wait} seconds. Exiting.")
    sys.exit(1)


def release_lock() -> None:
    """Release the file lock."""
    if '_lock_file_handle' in globals():
        try:
            os.remove(LOCK_FILE)
        except:
            pass
        del globals()['_lock_file_handle']


def get_agent_id() -> str:
    """Get agent identifier from environment or hostname."""
    agent_id = os.getenv("AGENT_ID")
    if not agent_id:
        agent_id = socket.gethostname().split(".")[0][:12]
    return agent_id


def get_current_branch() -> str:
    """Get the current git branch name."""
    result = run(["git", "rev-parse", "--abbrev-ref", "HEAD"])
    return result.stdout.strip()


def get_current_sha() -> str:
    """Get the current commit SHA."""
    result = run(["git", "rev-parse", "HEAD"])
    return result.stdout.strip()


def get_remote_sha(branch: str) -> str:
    """Get the remote branch SHA."""
    result = run(["git", "rev-parse", f"{REMOTE_NAME}/{branch}"])
    return result.stdout.strip()


def has_local_changes() -> bool:
    """Check if there are uncommitted changes in the working tree."""
    result = run(["git", "status", "--porcelain"])
    return bool(result.stdout.strip())


def stash_changes(agent_id: str) -> bool:
    """Stash local changes with agent-specific message. Returns True if stash was created."""
    if not has_local_changes():
        print(f"[{agent_id}] No local changes to stash")
        return False

    stash_msg = f"auto-sync-stash-{agent_id}-{int(time.time())}"
    run(["git", "stash", "push", "-m", stash_msg], check=True)
    print(f"[{agent_id}] Stashed local changes: {stash_msg}")
    return True


def get_latest_stash(agent_id: str) -> Optional[str]:
    """Get the latest stash reference for this agent."""
    result = run(["git", "stash", "list", "--format=%gd %gs"])
    for line in result.stdout.strip().split('\n'):
        if f"auto-sync-stash-{agent_id}-" in line:
            return line.split()[0]  # e.g., stash@{0}
    return None


def apply_stash(stash_ref: str, agent_id: str) -> bool:
    """Apply a specific stash reference. Returns True if applied successfully."""
    try:
        run(["git", "stash", "apply", stash_ref], check=True)
        print(f"[{agent_id}] Applied stash: {stash_ref}")
        return True
    except subprocess.CalledProcessError:
        print(f"[{agent_id}] Failed to apply stash: {stash_ref} (conflicts?)")
        return False


def fetch_remote() -> None:
    """Fetch latest from remote."""
    print(f"Fetching from {REMOTE_NAME}...")
    run(["git", "fetch", REMOTE_NAME], check=True)


def checkout_main_branch(agent_id: str) -> None:
    """Checkout the main branch."""
    run(["git", "checkout", DEFAULT_BRANCH], check=True)
    print(f"[{agent_id}] Checked out {DEFAULT_BRANCH}")


def create_agent_branch(agent_id: str, base_sha: str) -> str:
    """Create isolated temporary branch for this agent."""
    branch_name = f"patch/agente-{agent_id}"

    # Delete existing branch if it exists
    run(["git", "branch", "-D", branch_name], check=False)

    # Create new branch from the remote SHA
    run(["git", "checkout", "-b", branch_name, base_sha], check=True)
    print(f"[{agent_id}] Created isolated branch: {branch_name} at {base_sha[:8]}")
    return branch_name


def write_status(payload: Dict[str, Any]) -> None:
    """Write synchronization status to JSON file."""
    STATUS_FILE.parent.mkdir(parents=True, exist_ok=True)
    payload["timestamp"] = datetime.utcnow().isoformat() + "Z"
    payload["repo_root"] = str(REPO_ROOT)
    with open(STATUS_FILE, "w") as f:
        json.dump(payload, f, indent=2)


def is_ancestor(ancestor: str, descendant: str) -> bool:
    """Check if ancestor commit is an ancestor of descendant."""
    result = run(["git", "merge-base", "--is-ancestor", ancestor, descendant], check=False)
    return result.returncode == 0


def verified_sanitized_history(branch: str) -> Optional[Dict[str, str]]:
    """Verify sanitized history marker exists on remote branch."""
    try:
        marker_ref = f"{REMOTE_NAME}/{branch}:{SANITIZED_HISTORY_MARKER}"
        result = run(["git", "show", marker_ref], check=False)
        if result.returncode != 0:
            return None

        content = result.stdout.strip()
        roots = [line for line in content.split('\n') if line and not line.startswith('#')]
        if not roots:
            return None

        return {"marker": marker_ref, "roots": roots, "content": content}
    except Exception:
        return None


def realign_clean_checkout(branch: str, local_sha: str, remote_sha: str) -> None:
    """Move local branch to remote only after clean root validation."""
    marker_data = verified_sanitized_history(branch)
    if not marker_data:
        raise RuntimeError(f"Sanitized history marker not found on {REMOTE_NAME}/{branch}")

    roots = marker_data["roots"]
    valid = any(is_ancestor(root, local_sha) for root in roots)

    if not valid:
        raise RuntimeError(
            f"Local HEAD {local_sha[:8]} not descended from any sanitized root: {roots}"
        )

    run(["git", "branch", "-f", branch, remote_sha], check=True)
    run(["git", "checkout", branch], check=True)
    print(f"Realigned {branch} to {remote_sha[:8]} (validated against sanitized roots)")


def main() -> int:
    agent_id = get_agent_id()
    branch = DEFAULT_BRANCH

    print(f"\n{'='*60}")
    print(f"Git Auto-Sync Starting")
    print(f"Agent ID: {agent_id}")
    print(f"Repository: {REPO_ROOT}")
    print(f"Branch: {branch}")
    print(f"{'='*60}\n")

    # Acquire lock first
    acquire_lock()
    print(f"[{agent_id}] Lock acquired")

    payload: Dict[str, Any] = {
        "agent_id": agent_id,
        "branch": branch,
        "status": "started",
        "steps": []
    }

    try:
        # Step 1: Fetch remote
        print(f"\n[{agent_id}] Step 1: Fetching remote...")
        fetch_remote()
        payload["steps"].append({"step": "fetch", "status": "ok"})

        # Step 2: Get SHAs
        local_sha = get_current_sha()
        remote_sha = get_remote_sha(branch)
        payload["local_sha_before"] = local_sha
        payload["remote_sha"] = remote_sha

        print(f"[{agent_id}] Local SHA:  {local_sha[:8]}")
        print(f"[{agent_id}] Remote SHA: {remote_sha[:8]}")

        # Step 3: Stash local changes
        print(f"\n[{agent_id}] Step 2: Stashing local changes...")
        stashed = stash_changes(agent_id)
        payload["steps"].append({"step": "stash", "status": "ok", "stashed": stashed})

        # Step 4: Checkout main branch
        print(f"\n[{agent_id}] Step 3: Checking out main branch...")
        checkout_main_branch(agent_id)
        payload["steps"].append({"step": "checkout_main", "status": "ok"})

        # Step 5: Create isolated agent branch
        print(f"\n[{agent_id}] Step 4: Creating isolated agent branch...")
        agent_branch = create_agent_branch(agent_id, remote_sha)
        payload["steps"].append({"step": "create_agent_branch", "status": "ok", "branch": agent_branch})

        # Step 6: Reapply stash if we had changes
        if stashed:
            print(f"\n[{agent_id}] Step 5: Reapplying stashed changes...")
            stash_ref = get_latest_stash(agent_id)
            if stash_ref:
                applied = apply_stash(stash_ref, agent_id)
                payload["steps"].append({"step": "apply_stash", "status": "ok" if applied else "failed", "stash_ref": stash_ref})
            else:
                print(f"[{agent_id}] No stash found for this agent")
                payload["steps"].append({"step": "apply_stash", "status": "skipped", "reason": "no_stash_found"})
        else:
            payload["steps"].append({"step": "apply_stash", "status": "skipped", "reason": "no_changes_stashed"})

        # Step 7: Final status
        final_sha = get_current_sha()
        current_branch = get_current_branch()

        payload["status"] = "completed"
        payload["final_sha"] = final_sha
        payload["final_branch"] = current_branch
        payload["local_sha_after"] = final_sha

        print(f"\n{'='*60}")
        print(f"Git Auto-Sync Completed Successfully")
        print(f"Agent ID:       {agent_id}")
        print(f"Final Branch:   {current_branch}")
        print(f"Final SHA:      {final_sha[:8]}")
        print(f"Remote SHA:     {remote_sha[:8]}")
        print(f"Stashed:        {stashed}")
        print(f"Stash Applied:  {payload.get('stash_applied', False)}")
        print(f"Agent Branch:   {agent_branch}")
        print(f"Status File:    {STATUS_FILE}")
        print(f"{'='*60}\n")

        write_status(payload)
        return 0

    except Exception as e:
        payload["status"] = "failed"
        payload["error"] = str(e)
        payload["steps"].append({"step": "error", "status": "failed", "error": str(e)})
        write_status(payload)

        print(f"\n❌ [{agent_id}] Sync failed: {e}")
        print(f"{'='*60}\n")
        return 1

    finally:
        release_lock()
        print(f"[{agent_id}] Lock released")


if __name__ == "__main__":
    sys.exit(main())
