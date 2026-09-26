#!/usr/bin/env bash
set -Eeuo pipefail

workflow=.github/workflows/public-layout-audit.yml
layout_script=scripts/public-layout-audit.mjs
parity_script=scripts/production-runtime-parity.mjs
installer=scripts/install-shopvivaliz-backend-browser-runner.sh
remote_workflow=.github/workflows/shopvivaliz-remote-access.yml

grep -Fq 'runs-on: [self-hosted, Linux, ARM64, shopvivaliz-backend-browser]' "$workflow"
! grep -Fq 'runs-on: ubuntu-latest' "$workflow"
! grep -Fq 'SHOPVIVALIZ_VM_SSH_KEY' "$workflow"
! grep -Fq -- '-D 127.0.0.1:1080' "$workflow"
! grep -Fq 'npx playwright install --with-deps chromium' "$workflow"
grep -Fq 'SHOPVIVALIZ_CHROMIUM_PATH:' "$workflow"
grep -Fq 'const configuredBrowserPath = String(process.env.SHOPVIVALIZ_CHROMIUM_PATH' "$layout_script"
grep -Fq 'executablePath: configuredBrowserPath || undefined' "$layout_script"
grep -Fq 'const configuredBrowserPath = String(process.env.SHOPVIVALIZ_CHROMIUM_PATH' "$parity_script"
grep -Fq 'executablePath: configuredBrowserPath || undefined' "$parity_script"
test -f "$installer"
grep -Fq 'shopvivaliz-backend-browser' "$installer"
grep -Fq -- '--unattended' "$installer"
grep -Fq 'systemctl --user enable --now' "$installer"
grep -Fq 'actions.runner.shopvivaliz-backend-browser.service' "$installer"
grep -Fq 'ExecStart=/home/ubuntu/actions-runner-shopvivaliz-browser/run.sh' "$installer"
! grep -Fq './svc.sh install' "$installer"
! grep -Fq 'sudo ./svc.sh' "$installer"
echo 'public-layout-backend-browser-runner-regression: ok'

grep -Fq 'backend_browser_runner_install' "$remote_workflow"
grep -Fq 'backend_browser_runner_status' "$remote_workflow"
grep -Fq 'scripts/install-shopvivaliz-backend-browser-runner.sh' "$remote_workflow"
grep -Fq 'actions/runners/registration-token' "$remote_workflow"
grep -Fq 'always-free-arm-1787907847-26' "$remote_workflow"
