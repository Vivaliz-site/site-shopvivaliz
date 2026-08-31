#!/usr/bin/env bash
set -Eeuo pipefail
root="$(git rev-parse --show-toplevel)"; cd "$root"
if command -v composer >/dev/null 2>&1 && [ ! -d vendor ]; then composer install --no-interaction --prefer-dist; fi
find . -path './vendor' -prune -o -path './.git' -prune -o -name '*.php' -type f -print0 | xargs -0 -r -n1 php -l >/dev/null
if [ -x vendor/bin/phpunit ]; then vendor/bin/phpunit; fi
