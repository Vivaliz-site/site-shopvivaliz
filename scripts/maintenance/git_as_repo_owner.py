#!/usr/bin/env python3
"""Run a Git command using the repository owner identity, never root by default."""
import argparse
import os
from pathlib import Path
import pwd
import subprocess
import sys


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--repo', required=True)
    parser.add_argument('git_args', nargs=argparse.REMAINDER)
    args = parser.parse_args()
    repo = Path(args.repo).resolve(strict=True)
    if not (repo / '.git').exists():
        parser.error('repository must be a worktree root')
    owner = pwd.getpwuid(repo.stat().st_uid)
    if owner.pw_uid == 0:
        parser.error('refusing root-owned repository: select a non-root worktree')
    if not args.git_args:
        parser.error('Git command required')
    git_args = args.git_args[1:] if args.git_args[0] == '--' else args.git_args
    if not git_args:
        parser.error('Git command required')
    if os.geteuid() not in (0, owner.pw_uid):
        parser.error('caller cannot switch to repository owner')
    command = ['git', '-C', str(repo), *git_args]
    if os.geteuid() == 0:
        command = ['runuser', '-u', owner.pw_name, '--', *command]
    return subprocess.run(command, check=False).returncode


if __name__ == '__main__':
    sys.exit(main())
