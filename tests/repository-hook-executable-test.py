#!/usr/bin/env python3
import subprocess

hooks = [".githooks/pre-commit", ".githooks/pre-push", "scripts/repository-governance-validate.sh"]
output = subprocess.check_output(["git", "ls-files", "-s", *hooks], text=True)
modes = {}
for line in output.splitlines():
    mode, _, _, path = line.split(maxsplit=3)
    modes[path] = mode

invalid = {path: modes.get(path) for path in hooks if modes.get(path) != "100755"}
if invalid:
    raise SystemExit(f"mandatory hooks are not executable: {invalid}")

print("repository-hook-executable: ok")
