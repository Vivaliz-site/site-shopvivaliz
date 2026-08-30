#!/usr/bin/env bash
set -euo pipefail

pipeline='.github/workflows/master-production-pipeline.yml'
gate='.github/workflows/quality-gate.yml'

grep -Fq 'sudo bash "$current/scripts/install-catalog-sync-service.sh"' "$pipeline"
grep -Fq "grep -q '163.176.103.253' .github/workflows/master-production-pipeline.yml" "$gate"
grep -Fq "! grep -q '137.131.156.17' .github/workflows/master-production-pipeline.yml" "$gate"
if grep -Fq "! grep -q '163.176.103.253' .github/workflows/master-production-pipeline.yml" "$gate"; then
  echo 'quality gate contains self-contradictory A1 target check' >&2
  exit 1
fi
