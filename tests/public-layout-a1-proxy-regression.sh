#!/usr/bin/env bash
set -euo pipefail
workflow=.github/workflows/public-layout-audit.yml
script=scripts/public-layout-audit.mjs

grep -Fq 'SHOPVIVALIZ_VM_SSH_KEY' "$workflow"
grep -Fq 'StrictHostKeyChecking=yes' "$workflow"
grep -Fq 'ssh -N -D 127.0.0.1:1080' "$workflow"
grep -Fq 'E2E_PROXY_SERVER: socks5://127.0.0.1:1080' "$workflow"
grep -Fq 'const proxyServer = process.env.E2E_PROXY_SERVER' "$script"
grep -Fq 'proxy: proxyServer ? { server: proxyServer } : undefined' "$script"
echo 'public-layout-a1-proxy-regression: ok'
