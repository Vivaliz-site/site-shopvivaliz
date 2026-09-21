#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
core="$root/includes/ai-squad-core.php"
worker="$root/ops/browser-worker/server.mjs"
docs="$root/docs/knowledge/ai-squad.md"
admin="$root/admin/ai-squad.php"

node --check "$worker"
grep -Fq "return ['codex_chatgpt', 'chatgpt_browser', 'manual_chatgpt'];" "$core"
grep -Fq "'chatgpt_browser' => svais_chatgpt_browser_call" "$core"
grep -Fq "'chatgpt_browser_exact_model_guarantee' => false" "$core"
grep -Fq "AI_SQUAD_BROWSER_WORKER_URL" "$core"
grep -Fq "/chatgpt/health" "$worker"
grep -Fq "/chatgpt/respond" "$worker"
grep -Fq "chatgpt_auth_required" "$worker"
grep -Fq "chatgpt_browser" "$admin"
grep -Fq "chatgpt_browser" "$docs"
! grep -q "getenv('OPENAI_API_KEY')" "$core"

echo "AI_SQUAD_CHATGPT_BROWSER_CONTRACT=PASS"
