#!/usr/bin/env bash
set -euo pipefail

saw_path=0
while IFS= read -r path; do
  [ -n "$path" ] || continue
  saw_path=1
  case "$path" in
    *.md|docs/*|.codex/*|.github/*|tests/*)
      ;;
    *)
      printf '%s\n' true
      exit 0
      ;;
  esac
done

# Empty/unknown change sets deploy conservatively.
if [ "$saw_path" -eq 0 ]; then
  printf '%s\n' true
else
  printf '%s\n' false
fi
