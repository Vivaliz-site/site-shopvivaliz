#!/usr/bin/env bash
set -euo pipefail

browser_audit='tests/storefront-screenshot-audit.mjs'
repair_workflow='.github/workflows/blog-editorial-repair-once.yml'
article_path='/blog/acessorios-que-ajudam-na-rotina-de-limpeza-e-manutencao'

for route in "{ name: 'blog', url: '/blog/'" "{ name: 'blog-article', url: '${article_path}'"; do
  if ! grep -Fq "$route" "$browser_audit"; then
    echo "Storefront browser audit is missing required blog route: $route" >&2
    exit 1
  fi
done

if [[ ! -f "$repair_workflow" ]]; then
  echo 'One-time blog editorial repair workflow is missing.' >&2
  exit 1
fi

required=(
  'paths:'
  '.github/workflows/blog-editorial-repair-once.yml'
  '163.176.103.253'
  'scripts/repair-blog-editorial-content.php --dry-run'
  'scripts/repair-blog-editorial-content.php --apply --backup-dir='
  'blog-editorial-repair-evidence.json'
  'final_changed'
)
for needle in "${required[@]}"; do
  if ! grep -Fq -- "$needle" "$repair_workflow"; then
    echo "One-time repair workflow is missing safety/evidence contract: $needle" >&2
    exit 1
  fi
done

apply_count="$(grep -Fc -- 'scripts/repair-blog-editorial-content.php --apply --backup-dir=' "$repair_workflow")"
if [[ "$apply_count" -lt 2 ]]; then
  echo 'Repair workflow must apply twice so the second run proves idempotency.' >&2
  exit 1
fi

echo 'BLOG_PRODUCTION_PROOF_REGRESSION_OK'
