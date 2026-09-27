#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
workflow="$root/.github/workflows/shopvivaliz-remote-access.yml"
diag="$root/scripts/chatgpt-account-diagnostic.mjs"

test -f "$workflow"
test -f "$diag"

grep -Fq 'chatgpt_account_diag' "$workflow"
grep -Fq 'ChatGPT account diagnostic is restricted to the backend VM' "$workflow"
grep -Fq 'scripts/chatgpt-account-diagnostic.mjs' "$workflow"
grep -Fq 'CHATGPT_ACCOUNT_DIAG=PASS' "$workflow"

grep -Fq "connectOverCDP('http://127.0.0.1:9555')" "$diag"
grep -Fq "Responda apenas: TESTE-OK" "$diag"
grep -Fq 'const ATTEMPTS = 3' "$diag"
grep -Fq 'x-oai-request-id' "$diag"
grep -Fq 'x-oai-turn-trace-id' "$diag"
grep -Fq 'cf-ray' "$diag"
grep -Fq 'url.search = ' "$diag"
grep -Fq 'CHATGPT_ACCOUNT_DIAGNOSTIC=' "$diag"
grep -Fq "const CANONICAL_PROFILE = '/home/ubuntu/.local/share/shopvivaliz-browser-worker/profiles/ai-squad-chatgpt';" "$diag"
grep -Fq 'browser_worker_profile_fallback' "$diag"
grep -Fq 'canonical_profile_in_use_without_cdp' "$diag"
grep -Fq 'launchPersistentContext(CANONICAL_PROFILE' "$diag"
grep -Fq "const forcedProfile = String(process.env.CHATGPT_ACCOUNT_FORCE_PROFILE || '').trim();" "$diag"
grep -Fq 'legacy_fredrdp_profile_fallback' "$workflow"
grep -Fq '/home/fredrdp/.config/shopvivaliz-chromium' "$workflow"
grep -Fq 'sudo -n -u fredrdp' "$workflow"
grep -Fq 'legacy_profile_in_use_without_cdp' "$workflow"
grep -Fq 'function resolveBrowserPath' "$diag"
grep -Fq "'/usr/bin/chromium'" "$diag"
grep -Fq "'/snap/bin/chromium'" "$diag"
grep -Fq 'chromium.executablePath()' "$diag"

if grep -Eq 'mkdtemp|profile-[A-Za-z0-9]|chromium\.launch\(' "$diag"; then
  echo "diagnostic must reuse only the canonical persistent ChatGPT profile" >&2
  exit 1
fi
if grep -Eiq 'authorization|set-cookie|document\.cookie|localStorage|sessionStorage' "$diag"; then
  echo "diagnostic must not read or emit auth/session secrets" >&2
  exit 1
fi

echo "CHATGPT_ACCOUNT_DIAGNOSTIC_CONTRACT=PASS"
