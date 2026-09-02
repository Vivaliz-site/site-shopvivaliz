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

find . -path './vendor' -prune -o -path './.git' -prune -o -name '*.php' -type f -print0 | xargs -0 -r -n1 php -l >/dev/null

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
