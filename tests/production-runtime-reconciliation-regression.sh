#!/usr/bin/env bash
set -euo pipefail

pipeline='.github/workflows/master-production-pipeline.yml'
gate='.github/workflows/quality-gate.yml'

grep -Fq -- "--exclude=.github" "$pipeline"
grep -Fq -- "--exclude=.codex" "$pipeline"
grep -Fq -- "--exclude=.claude" "$pipeline"
grep -Fq -- "--exclude=docs" "$pipeline"
grep -Fq -- "--exclude=tests" "$pipeline"
grep -Fq 'rsync -a --checksum --delete --link-dest=' "$pipeline"
grep -Fq 'release_dir=' "$pipeline"
if grep -Fq 'shopvivaliz-release.tgz' "$pipeline"; then
  echo 'production deploy still transfers the full tar archive' >&2
  exit 1
fi
grep -Fq 'catalog_service_changed=false' "$pipeline"
grep -Fq 'amazon_service_changed=false' "$pipeline"
grep -Fq 'cmp -s "$release/scripts/install-catalog-sync-service.sh" "$previous/scripts/install-catalog-sync-service.sh"' "$pipeline"
grep -Fq 'cmp -s "$release/scripts/install-amazon-returns-service.sh" "$previous/scripts/install-amazon-returns-service.sh"' "$pipeline"
grep -Fq 'sudo bash "$current/scripts/install-catalog-sync-service.sh"' "$pipeline"
grep -Fq 'sudo bash "$current/scripts/install-amazon-returns-service.sh"' "$pipeline"
if grep -Fq 'sleep 2' "$pipeline"; then
  echo 'production activation still uses fixed two-second sleep' >&2
  exit 1
fi
grep -Fq "grep -q '163.176.103.253' .github/workflows/master-production-pipeline.yml" "$gate"
grep -Fq "php tests/retired-e2-endpoints-contract-test.php" "$gate"
if grep -Fq "! grep -q '163.176.103.253' .github/workflows/master-production-pipeline.yml" "$gate"; then
  echo 'quality gate contains self-contradictory A1 target check' >&2
  exit 1
fi
