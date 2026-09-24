#!/usr/bin/env bash
set -Eeuo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source_file="$repo_root/js/public-experience-v1.js"

grep -Fq "function isPublicPath(path)" "$source_file"
grep -Fq "(?:admin|api|auth|painel|claude|mcp)" "$source_file"
if grep -Fq "(?:admin|api|auth|checkout|painel|claude|mcp)" "$source_file"; then
  echo 'checkout-mobile-nav-regression: checkout must remain eligible for public mobile navigation' >&2
  exit 1
fi

echo 'checkout-mobile-nav-regression: PASS'
