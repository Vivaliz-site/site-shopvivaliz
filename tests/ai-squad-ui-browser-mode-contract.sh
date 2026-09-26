#!/usr/bin/env bash
set -euo pipefail
script="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/scripts/ai-squad-ui-audit.mjs"
grep -q "browserHeadless" "$script"
grep -q "headless: browserHeadless" "$script"
grep -Fq "if (browserHeadless) fail('headless_mode_not_certifiable');" "$script"
if grep -q "headless: true" "$script"; then
  echo "stale unconditional headless mode" >&2
  exit 1
fi
if grep -Fq "!process.env.DISPLAY" "$script"; then
  echo "automatic headless fallback would invalidate the graphical E2E gate" >&2
  exit 1
fi
echo "ai-squad-ui-browser-mode-contract: PASS"
