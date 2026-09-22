#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
api="$root/api/agent/buscador.php"
deploy="$root/scripts/deploy-production.sh"
ui="$root/admin/buscador.php"

php -l "$api" >/dev/null
bash -n "$deploy"

grep -Fq "ai-squad-deploy-gate.lock" "$api"
grep -Fq "ai-squad-runtime.lock" "$api"
grep -Fq 'flock($gate, LOCK_SH | LOCK_NB)' "$api"
grep -Fq 'flock($runtime, LOCK_SH)' "$api"
grep -Fq "svais_api_release_runtime_cycle_lock" "$api"

grep -Fq "reconcile_ai_squad_bridges()" "$deploy"
grep -Fq "ai-squad-deploy-gate.lock" "$deploy"
grep -Fq "ai-squad-runtime.lock" "$deploy"
grep -Fq 'flock -w 840 "$gate_fd"' "$deploy"
grep -Fq 'flock -w 840 "$runtime_fd"' "$deploy"
grep -Fq 'sudo touch "$gate_lock" "$runtime_lock"' "$deploy"
grep -Fq 'sudo chown ubuntu:www-data "$gate_lock" "$runtime_lock"' "$deploy"

python3 - "$deploy" <<'PY'
from pathlib import Path
import sys
text = Path(sys.argv[1]).read_text(encoding="utf-8")
touch = text.index('sudo touch "$gate_lock" "$runtime_lock"')
chown = text.index('sudo chown ubuntu:www-data "$gate_lock" "$runtime_lock"', touch)
open_gate = text.index('exec {gate_fd}>"$gate_lock"', chown)
open_runtime = text.index('exec {runtime_fd}>"$runtime_lock"', chown)
if not (touch < chown < open_gate < open_runtime):
    raise SystemExit("FAIL: lock ownership must be normalized before deploy opens lock files")
PY

grep -Fq "e.ok===true&&e.complete_provider_coverage===true&&e.consensus_available===true" "$ui"
grep -Fq "document.getElementById('phase').textContent=complete?'Concluído':'Incompleto'" "$ui"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
lock="$tmp/runtime.lock"
ready="$tmp/ready"

php -r '$h=fopen($argv[1],"c"); if(!$h || !flock($h, LOCK_SH)) exit(2); file_put_contents($argv[2],"ready"); usleep(700000);' "$lock" "$ready" &
holder=$!
for _ in $(seq 1 40); do
  [ -f "$ready" ] && break
  sleep 0.05
done
test -f "$ready"
start_ms="$(date +%s%3N)"
exec 8>"$lock"
flock -w 3 8
elapsed_ms=$(( $(date +%s%3N) - start_ms ))
flock -u 8
exec 8>&-
wait "$holder"

if [ "$elapsed_ms" -lt 450 ]; then
  echo "FAIL: exclusive deploy lock did not drain active shared cycle: ${elapsed_ms}ms" >&2
  exit 1
fi

echo "AI_SQUAD_RUNTIME_DRAIN_CONTRACT=PASS"
