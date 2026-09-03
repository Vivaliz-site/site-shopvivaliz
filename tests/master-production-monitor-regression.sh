#!/usr/bin/env bash
set -euo pipefail

workflow='.github/workflows/master-production-pipeline.yml'
monitor="$(sed -n '/^  monitor:/,$p' "$workflow")"
deploy="$(sed -n '/^  deploy:/,/^  monitor:/p' "$workflow")"

if grep -Fq 'api/health/version.php?monitor=' <<<"$monitor"; then
  echo 'monitor must not depend on the public health API for release identity' >&2
  exit 1
fi

grep -Fq 'Configure pinned A1 SSH' <<<"$monitor"
grep -Fq "'bash -s' -- \"\$DEPLOY_SHA\" <<'REMOTE'" <<<"$monitor"
grep -Fq 'sha="$1"' <<<"$monitor"
grep -Fq '/home/ubuntu/shopvivaliz-deploy/current/.release-sha' <<<"$monitor"
grep -Fq 'test "$served_sha" = "$sha"' <<<"$monitor"
grep -Fq 'https://shopvivaliz.com.br${path}' <<<"$monitor"
grep -Fq 'sitemap_body="$(curl -sS' <<<"$monitor"
grep -Fq '[[ "$sitemap_body" == *'\''<urlset'\''* ]]' <<<"$monitor"

# mod_php keeps OPcache in the long-lived Apache parent process. A graceful
# reload is insufficient after the stable `current` symlink changes targets.
restart_count="$(grep -Fc 'sudo systemctl restart apache2' <<<"$deploy")"
if [ "$restart_count" -lt 2 ]; then
  echo 'deploy must restart Apache after activation and rollback' >&2
  exit 1
fi
if grep -Fq 'sudo systemctl reload apache2' <<<"$deploy"; then
  echo 'deploy must not use graceful reload across immutable release switches' >&2
  exit 1
fi
grep -Fq 'sudo systemctl is-active --quiet apache2' <<<"$deploy"
