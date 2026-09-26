#!/usr/bin/env bash
set -euo pipefail
core="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/includes/ai-squad-core.php"
grep -q 'function svais_provider_request_timeout' "$core"
grep -q 'AI_SQUAD_PROVIDER_TIMEOUT' "$core"
grep -Fq "(getenv('AI_SQUAD_PROVIDER_TIMEOUT') ?: 240)" "$core"
grep -q '\$timeout = min(240, svais_provider_request_timeout())' "$core"
bridge="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/ops/ai-squad/claude-bridge.mjs"
grep -q 'DEFAULT_TIMEOUT_MS = 225000' "$bridge"
grep -q 'deadline' "$bridge"
echo "ai-squad-provider-timeout-contract: PASS"
