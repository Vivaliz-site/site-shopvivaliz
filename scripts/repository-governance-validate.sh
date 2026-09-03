#!/usr/bin/env bash
set -Eeuo pipefail
root="$(git rev-parse --show-toplevel)"; cd "$root"
phase="${1:-manual}"

bash -n .githooks/pre-commit .githooks/pre-push scripts/repository-governance-validate.sh
python3 scripts/validate-recurring-ai-policy.py
python3 scripts/validate-final-response-deploy-gate.py

if command -v composer >/dev/null 2>&1; then
  composer validate --no-check-publish --strict
fi

lint_php_list() {
  local list_file="$1"
  while IFS= read -r -d '' file; do
    if [ -f "$file" ]; then
      php -l "$file" >/dev/null
    fi
  done < "$list_file"
}

tmp_php_list="$(mktemp)"
trap 'rm -f "$tmp_php_list"' EXIT

case "$phase" in
  pre-commit)
    git diff --cached --name-only -z --diff-filter=ACMR -- '*.php' > "$tmp_php_list"
    lint_php_list "$tmp_php_list"
    ;;
  pre-push)
    upstream_ref=""
    if upstream_ref="$(git rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>/dev/null)"; then
      base_ref="$(git merge-base HEAD "$upstream_ref")"
    else
      base_ref="$(git merge-base HEAD origin/main)"
    fi
    git diff --name-only -z --diff-filter=ACMR "$base_ref"..HEAD -- '*.php' > "$tmp_php_list"
    lint_php_list "$tmp_php_list"
    ;;
  ci)
    if [ "${GITHUB_EVENT_NAME:-}" = "pull_request" ] && [ -n "${GITHUB_BASE_REF:-}" ]; then
      base_ref="$(git merge-base HEAD "origin/$GITHUB_BASE_REF")"
      git diff --name-only -z --diff-filter=ACMR "$base_ref"..HEAD -- '*.php' > "$tmp_php_list"
      lint_php_list "$tmp_php_list"
    elif [ "${GITHUB_EVENT_NAME:-}" = "push" ] && [ -n "${PUSH_BEFORE_SHA:-}" ] && ! printf '%s' "$PUSH_BEFORE_SHA" | grep -Eq '^0+$'; then
      git diff --name-only -z --diff-filter=ACMR "$PUSH_BEFORE_SHA"..HEAD -- '*.php' > "$tmp_php_list"
      lint_php_list "$tmp_php_list"
    else
      find . -path './vendor' -prune -o -path './.git' -prune -o -path './.worktrees' -prune -o -path './node_modules' -prune -o -name '*.php' -type f -print0 | xargs -0 -r -n1 php -l >/dev/null
    fi
    ;;
  manual|full)
    find . -path './vendor' -prune -o -path './.git' -prune -o -path './.worktrees' -prune -o -path './node_modules' -prune -o -name '*.php' -type f -print0 | xargs -0 -r -n1 php -l >/dev/null
    ;;
  *)
    echo "Unknown validation phase: $phase" >&2
    exit 64
    ;;
esac

if [ "$phase" = "ci" ]; then
  if command -v composer >/dev/null 2>&1 && [ ! -d vendor ]; then
    composer install --no-interaction --prefer-dist
  fi
  if [ -x vendor/bin/phpunit ]; then
    if [ -f phpunit.xml ] || [ -f phpunit.xml.dist ]; then
      vendor/bin/phpunit
    elif [ -d tests ] && find tests -type f \( -name '*Test.php' -o -name '*.phpt' \) -print -quit | grep -q .; then
      vendor/bin/phpunit tests
    fi
  fi
fi
