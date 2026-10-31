#!/usr/bin/env python3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
script = (root / 'scripts' / 'pr_conflict_vm_heal.sh').read_text()

auth = 'gh auth status --hostname github.com'
no_write = 'if [[ "$after_sha" == "$before_sha" ]]; then'
if auth not in script:
    raise SystemExit('missing guarded GitHub write-auth check')
if no_write not in script:
    raise SystemExit('missing no-write fast path')
if script.index(auth) < script.index(no_write):
    raise SystemExit('GitHub write auth is required before write necessity is known')
if 'gh repo clone "$repo"' in script:
    raise SystemExit('public read path still depends on authenticated gh clone')
if 'git clone ' not in script or 'https://github.com/${repo}.git' not in script:
    raise SystemExit('public git clone path missing')
if 'curl -fsSL' not in script:
    raise SystemExit('public PR metadata fetch missing')

if '--connect-timeout' not in script or '--max-time' not in script:
    raise SystemExit('public PR metadata fetch lacks bounded timeouts')
if "fail 'GitHub PR metadata fetch failed'" not in script:
    raise SystemExit('public PR metadata fetch lacks controlled failure')
if 'git clone -q --filter=blob:none --no-tags' not in script:
    raise SystemExit('public clone is not quiet while preserving stderr')
clone_lines = [line for line in script.splitlines() if line.strip().startswith('git clone ')]
if len(clone_lines) != 1 or '2>&1' in clone_lines[0] or '>/dev/null' in clone_lines[0]:
    raise SystemExit('public clone suppresses diagnostic stderr')

if 'external_github_auth_used=false' not in script:
    raise SystemExit('GitHub auth telemetry does not default to false')
if 'external_github_auth_used=true' not in script:
    raise SystemExit('GitHub auth telemetry is not set on publication path')
if 'external_github_auth_used=${external_github_auth_used}' not in script:
    raise SystemExit('GitHub auth telemetry does not report the real state')
print('pr-conflict-healer-write-auth-contract: ok')
