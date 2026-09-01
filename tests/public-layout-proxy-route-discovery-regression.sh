#!/usr/bin/env bash
set -euo pipefail
script=scripts/public-layout-audit.mjs

grep -Fq "import { execFile } from 'node:child_process'" "$script"
grep -Fq 'const auditFetch = async' "$script"
grep -Fq "proxyServer.replace(/^socks5:\\/\\//, 'socks5h://')" "$script"
grep -Fq "execFileAsync('curl'" "$script"
grep -Fq 'fetchImpl: auditFetch' "$script"
echo 'public-layout-proxy-route-discovery-regression: ok'
