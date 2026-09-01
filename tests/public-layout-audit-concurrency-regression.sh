#!/usr/bin/env bash
set -Eeuo pipefail
workflow=.github/workflows/public-layout-audit.yml

grep -Fq 'group: public-layout-audit' "$workflow"
grep -Fq 'cancel-in-progress: false' "$workflow"
echo 'public-layout-audit-concurrency-regression: ok'
