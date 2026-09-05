from pathlib import Path

workflow = Path('.github/workflows/pr-conflict-auto-healer.yml').read_text(encoding='utf-8')
assert 'mktemp -d /tmp/shopvivaliz-pr-healer-' in workflow, 'healer staging must use a private randomized temp directory'
assert 'remote_base="${remote_dir}/healer"' in workflow, 'staged healer files must live inside the private directory'
assert "rm -rf -- '${REMOTE_BASE%/*}'" in workflow, 'cleanup must remove the private temp directory atomically'
assert 'remote_base="/tmp/shopvivaliz-pr-healer-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"' not in workflow, 'predictable direct /tmp file staging must be removed'
print('pr-conflict-healer-staging-contract: ok')
