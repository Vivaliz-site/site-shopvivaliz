#!/usr/bin/env python3
"""
Git Auto-Sync for VM Oracle
Syncs main branch every 2 minutes via cron
"""

import subprocess
import os
import sys
from datetime import datetime

REPO_DIR = "/home/ubuntu/site-shopvivaliz"
LOG_FILE = "/var/log/git-auto-sync.log"

def log(msg):
    """Log message with timestamp"""
    timestamp = datetime.now().isoformat()
    log_msg = f"[{timestamp}] {msg}"

    # Print to stdout (captured by cron)
    print(log_msg)

    # Append to log file
    try:
        with open(LOG_FILE, "a") as f:
            f.write(log_msg + "\n")
    except Exception as e:
        print(f"Warning: Could not write to {LOG_FILE}: {e}")

def run_cmd(cmd, cwd=REPO_DIR):
    """Run command and return output"""
    try:
        result = subprocess.run(
            cmd,
            cwd=cwd,
            capture_output=True,
            text=True,
            timeout=60
        )
        return result.returncode, result.stdout, result.stderr
    except subprocess.TimeoutExpired:
        return 124, "", "Command timed out"
    except Exception as e:
        return 1, "", str(e)

def main():
    """Main sync logic"""
    os.chdir(REPO_DIR)

    try:
        # Fetch latest from origin
        log("Fetching from origin...")
        code, out, err = run_cmd(["git", "fetch", "origin"])
        if code != 0:
            log(f"ERROR: git fetch failed: {err}")
            return 1

        # Check for local changes
        code, out, err = run_cmd(["git", "status", "--porcelain"])
        if code != 0:
            log(f"ERROR: git status failed: {err}")
            return 1

        if out.strip():
            # Local changes exist - stash and sync
            log(f"Local changes detected, stashing...")
            code, out, err = run_cmd(["git", "stash"])
            if code != 0:
                log(f"WARNING: git stash failed: {err}")

        # Pull main branch with fast-forward only
        log("Pulling main branch...")
        code, out, err = run_cmd(["git", "checkout", "main"])
        if code != 0:
            log(f"ERROR: git checkout main failed: {err}")
            return 1

        code, out, err = run_cmd(["git", "pull", "--ff-only", "origin", "main"])
        if code == 0:
            log("SUCCESS: Repository synced to latest main")
            return 0
        elif "fatal: Not a valid object name" in err or "fatal: your current branch" in err:
            # Branch doesn't exist or is empty, that's OK
            log("INFO: main branch already up to date or doesn't exist")
            return 0
        else:
            log(f"WARNING: git pull failed: {err}")
            return 1

    except Exception as e:
        log(f"ERROR: Unexpected error: {e}")
        return 1

if __name__ == "__main__":
    sys.exit(main())
