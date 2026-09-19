#!/usr/bin/env bash
set -Eeuo pipefail
workflow=.github/workflows/public-layout-audit.yml
script=scripts/public-layout-audit.mjs

grep -Fq 'group: public-layout-audit' "$workflow"
grep -Fq 'cancel-in-progress: true' "$workflow"
grep -Fq 'timeout-minutes: 35' "$workflow"
grep -Fq "cron: '17 */6 * * *'" "$workflow"
grep -Fq 'retention-days: 1' "$workflow"
grep -Fq 'compression-level: 0' "$workflow"
grep -Fq "import { mapWithConcurrency, resolveAuditConcurrency } from './lib/audit-concurrency.mjs';" "$script"
grep -Fq 'const auditConcurrency = resolveAuditConcurrency();' "$script"
grep -Fq 'await mapWithConcurrency(routes, auditConcurrency' "$script"
echo 'public-layout-audit-concurrency-regression: ok'
