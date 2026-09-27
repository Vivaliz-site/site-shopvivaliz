#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
workflow="$root/.github/workflows/shopvivaliz-remote-access.yml"
direct_workflow="$root/.github/workflows/chatgpt-account-diagnostic-direct.yml"
diag="$root/scripts/chatgpt-account-diagnostic.mjs"

test -f "$workflow"
test -f "$diag"
test -f "$direct_workflow"

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
grep -Fq 'sanitized.har.json' "$diag"
grep -Fq 'console-errors.json' "$diag"
grep -Fq 'attempt-' "$diag"
grep -Fq 'blocker.png' "$diag"
grep -Fq 'blocker_page' "$diag"
grep -Fq 'session_expired_present' "$diag"
grep -Fq 'login_prompt_present' "$diag"
grep -Fq "request_id: headers['x-oai-request-id']" "$diag"
grep -Fq "turn_trace_id: headers['x-oai-turn-trace-id']" "$diag"
grep -Fq "cf_ray: headers['cf-ray']" "$diag"
grep -Fq 'page.locator(' "$diag"
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
grep -Fq '/opt/shopvivaliz-browser/chrome-linux/chrome' "$workflow"
grep -Fq "source='/home/ubuntu/.cache/ms-playwright/chromium-1234/chrome-linux/chrome'" "$workflow"
grep -Fq "src_dir='/home/ubuntu/.cache/ms-playwright/chromium-1234/chrome-linux'" "$workflow"
grep -Fq 'sudo -n rm -rf /opt/shopvivaliz-browser/chrome-linux.new' "$workflow"
grep -Fq 'cp -a --reflink=auto' "$workflow"
if grep -Fq 'readlink -f /home/ubuntu/.local/bin/shopvivaliz-browser-chromium' "$workflow"; then
  echo "diagnostic browser export must not derive a directory from a mutable symlink target" >&2
  exit 1
fi
grep -Fq 'xvfb-run -a' "$workflow"
grep -Fq 'CHATGPT_ACCOUNT_BROWSER_PATH=' "$workflow"

if grep -Eq 'mkdtemp|profile-[A-Za-z0-9]|chromium\.launch\(' "$diag"; then
  echo "diagnostic must reuse only the canonical persistent ChatGPT profile" >&2
  exit 1
fi
if grep -Eiq 'authorization|set-cookie|document\.cookie|localStorage|sessionStorage' "$diag"; then
  echo "diagnostic must not read or emit auth/session secrets" >&2
  exit 1
fi

echo "CHATGPT_ACCOUNT_DIAGNOSTIC_CONTRACT=PASS"

grep -Fq "runs-on: [self-hosted, Linux, ARM64, shopvivaliz-backend-browser]" "$direct_workflow"
grep -Fq "/chatgpt-account-diag-v1" "$direct_workflow"
grep -Fq "sudo -n -u fredrdp" "$direct_workflow"
grep -Fq "/opt/shopvivaliz-browser/chrome-linux/chrome" "$direct_workflow"
grep -Fq "source='/home/ubuntu/.cache/ms-playwright/chromium-1234/chrome-linux/chrome'" "$direct_workflow"
grep -Fq "src_dir='/home/ubuntu/.cache/ms-playwright/chromium-1234/chrome-linux'" "$direct_workflow"
if grep -Fq 'readlink -f /home/ubuntu/.local/bin/shopvivaliz-browser-chromium' "$direct_workflow"; then
  echo "direct diagnostic must pin the canonical browser bundle" >&2
  exit 1
fi
grep -Fq "actions/upload-artifact@" "$direct_workflow"
if grep -Fq '\\${{ runner.temp }}' "$direct_workflow" || grep -Fq '\\${{ github.run_id }}' "$direct_workflow"; then
  echo "direct diagnostic artifact expressions must not be escaped" >&2
  exit 1
fi
grep -Fq '${{ runner.temp }}/chatgpt-account-diagnostic/**/blocker.png' "$direct_workflow"
grep -Fq "sanitized.har.json" "$direct_workflow"
grep -Fq "console-errors.json" "$direct_workflow"
grep -Fq "CHATGPT_ACCOUNT_DIRECT_DIAG=PASS" "$direct_workflow"
