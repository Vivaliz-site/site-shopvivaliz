#!/usr/bin/env bash
set -euo pipefail
core="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/includes/ai-squad-core.php"
grep -q 'function svais_provider_request_timeout' "$core"
grep -q 'AI_SQUAD_PROVIDER_TIMEOUT' "$core"
grep -q '\$timeout = min(240, svais_provider_request_timeout())' "$core"
echo "ai-squad-provider-timeout-contract: PASS"
