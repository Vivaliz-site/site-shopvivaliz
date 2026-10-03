#!/usr/bin/env bash
set -Eeuo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
script="$root/scripts/chatgpt-business-model-settings-probe.mjs"
workflow="$root/.github/workflows/chatgpt-business-model-settings-probe.yml"

test -f "$script"
test -f "$workflow"

node --check "$script"

grep -Fq "const CDP_URL = 'http://127.0.0.1:9555';" "$script"
grep -Fq "/json/new?" "$script"
grep -Fq "new WebSocket(target.webSocketDebuggerUrl)" "$script"
! grep -Fq "chromium.connectOverCDP(CDP_URL)" "$script"
grep -Fq "CHATGPT_BUSINESS_MODELS_AUTHENTICATED=" "$script"
grep -Fq "CHATGPT_BUSINESS_MODELS_PAGE_REACHED=" "$script"
grep -Fq "CHATGPT_BUSINESS_MODELS_LOAD_ERROR_PRESENT=" "$script"
grep -Fq "CHATGPT_BUSINESS_MODELS_CONTROLS_ENABLED=" "$script"
grep -Fq "openSettingsNavigation" "$script"
grep -Fq "openWorkspaceAdmin" "$script"
grep -Fq "openModelsSection" "$script"
grep -Fq "CHATGPT_BUSINESS_MODELS_PROBE=PASS" "$script"
! grep -Eq "accessToken|cookie|authorization|localStorage|sessionStorage" "$script"

grep -Fq "runs-on: [self-hosted, Linux, ARM64, shopvivaliz-backend-browser]" "$workflow"
grep -Fq "github.event.issue.title == '[chatgpt-business-model-settings-probe]'" "$workflow"
grep -Fq "grep -E '^CHATGPT_BUSINESS_MODELS_[A-Z_]+='" "$workflow"
grep -Fq "CHATGPT_BUSINESS_MODELS_DIRECT_PROBE=PASS" "$workflow"

echo "CHATGPT_BUSINESS_MODEL_SETTINGS_PROBE_CONTRACT=PASS"
