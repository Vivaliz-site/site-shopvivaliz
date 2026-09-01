#!/usr/bin/env python3
import subprocess

raw = subprocess.check_output(["git", "ls-files", "-z", "*.php"])
paths = [p for p in raw.decode("utf-8", "surrogateescape").split("\0") if p]
invalid = [p for p in paths if "\uf03a" in p]

if invalid:
    rendered = ", ".join(repr(path) for path in invalid)
    raise SystemExit(f"invalid tracked PHP path(s) with U+F03A: {rendered}")

print("repository-php-path-hygiene: ok")
