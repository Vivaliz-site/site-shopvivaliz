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
if 'external_github_auth_used=false' not in script:
    raise SystemExit('GitHub auth telemetry does not default to false')
if 'external_github_auth_used=true' not in script:
    raise SystemExit('GitHub auth telemetry is not set on publication path')
if 'external_github_auth_used=${external_github_auth_used}' not in script:
    raise SystemExit('GitHub auth telemetry does not report the real state')
print('pr-conflict-healer-write-auth-contract: ok')
