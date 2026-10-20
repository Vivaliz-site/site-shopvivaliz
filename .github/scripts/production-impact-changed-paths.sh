#!/usr/bin/env bash
# Enumerate changed paths against the first parent of a verified commit.
#
# git diff-tree -r without -m yields NO paths on a merge commit. Passing that
# empty list to should-deploy-production.sh incorrectly marks docs-only merges
# as production changes, triggering futile long-lived release wait jobs.
#
# For a real root commit every tracked file is treated as changed.
# For a shallow/incomplete checkout with a missing parent, fail CLOSED instead
# of silently classifying as docs-only or claiming deployment evidence.
set -Eeuo pipefail

if [ "$#" -ne 1 ]; then
  echo 'PRODUCTION_IMPACT_SHA_REQUIRED' >&2
  exit 2
fi
sha="$1"
if [[ ! "$sha" =~ ^[0-9a-f]{40}$ ]]; then
  echo 'INVALID_PRODUCTION_IMPACT_SHA' >&2
  exit 2
fi
if ! git cat-file -e "$sha^{commit}" 2>/dev/null; then
  echo 'PRODUCTION_IMPACT_COMMIT_UNAVAILABLE' >&2
  exit 3
fi

# Read the actual object header, not git rev-list's shallow-boundary view.
header="$(git cat-file -p "$sha")"
first_parent="$(printf '%s\n' "$header" | awk '/^$/{exit} /^parent /{print $2;exit}')"
unset header

if [[ -z "$first_parent" ]]; then
  # Root commit: all files are new. Never classify an empty initial tree as docs.
  git ls-tree --name-only -r "$sha"
else
  if [[ ! "$first_parent" =~ ^[0-9a-f]{40}$ ]] || ! git cat-file -e "$first_parent^{commit}" 2>/dev/null; then
    echo 'PRODUCTION_IMPACT_FIRST_PARENT_UNAVAILABLE' >&2
    exit 4
  fi
  git diff --no-ext-diff --no-renames --name-only "$first_parent" "$sha" --
fi
