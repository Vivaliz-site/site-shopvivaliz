#!/usr/bin/env bash
set -euo pipefail

# Incident guard: a crashed live crawl must never upload a previous /tmp report.
workflow='.github/workflows/ecommerce-excellence-audit.yml'
needle='rm -f /tmp/ecommerce-live.json /tmp/ecommerce-live.md /tmp/google-commerce-config.json'

if ! grep -Fq "$needle" "$workflow"; then
  echo "Live audit workflow must clear previous /tmp evidence before execution." >&2
  exit 1
fi

run_line="$(grep -n 'python3 /tmp/ecommerce-excellence-audit.py --mode live' "$workflow" | head -n1 | cut -d: -f1)"
cleanup_line="$(grep -nF "$needle" "$workflow" | head -n1 | cut -d: -f1)"
if [[ -z "$run_line" || -z "$cleanup_line" || "$cleanup_line" -ge "$run_line" ]]; then
  echo "Evidence cleanup must happen before the live auditor starts." >&2
  exit 1
fi

echo 'ECOMMERCE_LIVE_STALE_EVIDENCE_REGRESSION_OK'
