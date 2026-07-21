#!/usr/bin/env python3
"""
Git Auto-Push Daemon for Windows
Monitors .git/HEAD and auto-pushes on commit
Installation: Run as background task via Windows Task Scheduler
"""

import os
import sys
import time
import subprocess
import hashlib
from datetime import datetime
from pathlib import Path

class GitAutoPushDaemon:
    def __init__(self, repo_path="c:\\site-shopvivaliz", check_interval=2):
        self.repo_path = Path(repo_path)
        self.git_dir = self.repo_path / ".git"
        self.head_file = self.git_dir / "HEAD"
        self.log_file = self.repo_path / ".git" / "auto-push-daemon.log"
        self.check_interval = check_interval
        self.last_commit = None
        self.last_push_time = 0

    def log(self, message):
        """Log message with timestamp"""
        timestamp = datetime.now().isoformat()
        log_entry = f"[{timestamp}] {message}"
        print(log_entry)

        try:
            with open(self.log_file, "a", encoding="utf-8") as f:
                f.write(log_entry + "\n")
        except Exception as e:
            print(f"Warning: Could not write to log: {e}")

    def get_current_commit(self):
        """Get current HEAD commit hash"""
        try:
            result = subprocess.run(
                ["git", "rev-parse", "HEAD"],
                cwd=str(self.repo_path),
                capture_output=True,
                text=True,
                timeout=10
            )
            if result.returncode == 0:
                return result.stdout.strip()
        except Exception as e:
            self.log(f"Error getting commit: {e}")
        return None

    def get_remote_commit(self):
        """Get remote origin/main commit hash"""
        try:
            result = subprocess.run(
                ["git", "rev-parse", "origin/main"],
                cwd=str(self.repo_path),
                capture_output=True,
                text=True,
                timeout=10
            )
            if result.returncode == 0:
                return result.stdout.strip()
        except Exception as e:
            pass  # Remote may not be available
        return None

    def push_to_github(self):
        """Push current branch to GitHub"""
        try:
            # Get current branch
            result = subprocess.run(
                ["git", "rev-parse", "--abbrev-ref", "HEAD"],
                cwd=str(self.repo_path),
                capture_output=True,
                text=True,
                timeout=10
            )
            if result.returncode != 0:
                self.log("ERROR: Could not determine branch")
                return False

            branch = result.stdout.strip()
            self.log(f"Pushing to origin/{branch}...")

            # Push to remote
            result = subprocess.run(
                ["git", "push", "origin", branch],
                cwd=str(self.repo_path),
                capture_output=True,
                text=True,
                timeout=30
            )

            if result.returncode == 0:
                self.log(f"SUCCESS: Pushed to origin/{branch}")
                self.last_push_time = time.time()
                return True
            else:
                self.log(f"ERROR: Push failed - {result.stderr}")
                return False

        except Exception as e:
            self.log(f"ERROR: Exception during push: {e}")
            return False

    def check_and_push(self):
        """Check for new commits and push if needed"""
        try:
            current_commit = self.get_current_commit()

            if current_commit is None:
                return

            # Detect new commit
            if self.last_commit is None:
                self.last_commit = current_commit
                self.log(f"Initialized with commit {current_commit[:7]}")
                return

            if current_commit != self.last_commit:
                self.log(f"New commit detected: {current_commit[:7]}")
                self.last_commit = current_commit

                # Push new commit
                self.push_to_github()
                return

            # Check if local is ahead of remote
            local_commit = self.get_current_commit()
            remote_commit = self.get_remote_commit()

            if local_commit and remote_commit and local_commit != remote_commit:
                # Avoid pushing too frequently
                now = time.time()
                if now - self.last_push_time > 10:  # At least 10 seconds between pushes
                    self.log(f"Local ahead of remote, pushing...")
                    self.push_to_github()

        except Exception as e:
            self.log(f"ERROR in check_and_push: {e}")

    def run(self):
        """Main daemon loop"""
        self.log("=" * 60)
        self.log("Git Auto-Push Daemon Started")
        self.log(f"Repo: {self.repo_path}")
        self.log(f"Check interval: {self.check_interval} seconds")
        self.log("=" * 60)

        try:
            while True:
                self.check_and_push()
                time.sleep(self.check_interval)
        except KeyboardInterrupt:
            self.log("Daemon stopped by user")
            sys.exit(0)
        except Exception as e:
            self.log(f"FATAL ERROR: {e}")
            sys.exit(1)

if __name__ == "__main__":
    repo_path = sys.argv[1] if len(sys.argv) > 1 else "c:\\site-shopvivaliz"
    check_interval = int(sys.argv[2]) if len(sys.argv) > 2 else 2

    daemon = GitAutoPushDaemon(repo_path, check_interval)
    daemon.run()
