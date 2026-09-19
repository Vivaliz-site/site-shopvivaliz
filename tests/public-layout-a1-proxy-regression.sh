#!/usr/bin/env bash
set -Eeuo pipefail
workflow=.github/workflows/public-layout-audit.yml
script=scripts/public-layout-audit.mjs

grep -Fq 'runs-on: ubuntu-latest' "$workflow"
! grep -Fq 'SHOPVIVALIZ_VM_SSH_KEY' "$workflow"
! grep -Fq -- '-D 127.0.0.1:1080' "$workflow"
! grep -Fq 'E2E_PROXY_SERVER:' "$workflow"
grep -Fq 'const proxyServer = process.env.E2E_PROXY_SERVER' "$script"
grep -Fq 'proxy: proxyServer ? { server: proxyServer } : undefined' "$script"
echo 'public-layout-hosted-runner-regression: ok'
