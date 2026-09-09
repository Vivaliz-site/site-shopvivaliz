#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
QUALITY="$ROOT/.github/workflows/quality-gate.yml"
cd "$ROOT"

mapfile -t vsix_files < <(git ls-files '*.vsix')
if [ "${#vsix_files[@]}" -ne 0 ]; then
  printf 'Tracked VSIX artifacts are forbidden:\n' >&2
  printf ' - %s\n' "${vsix_files[@]}" >&2
  exit 1
fi

grep -Fq 'bash tests/repository-editor-artifacts-regression.sh' "$QUALITY"
echo REPOSITORY_EDITOR_ARTIFACTS_OK
