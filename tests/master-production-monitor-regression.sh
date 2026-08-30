#!/usr/bin/env bash
set -euo pipefail

workflow='.github/workflows/master-production-pipeline.yml'
monitor="$(sed -n '/^  monitor:/,$p' "$workflow")"

if grep -Fq 'api/health/version.php?monitor=' <<<"$monitor"; then
  echo 'monitor must not depend on the public health API for release identity' >&2
  exit 1
fi

grep -Fq 'Configure pinned A1 SSH' <<<"$monitor"
grep -Fq '/home/ubuntu/shopvivaliz-deploy/current/.release-sha' <<<"$monitor"
grep -Fq 'test "$served_sha" = "$DEPLOY_SHA"' <<<"$monitor"
grep -Fq 'https://shopvivaliz.com.br${path}' <<<"$monitor"
