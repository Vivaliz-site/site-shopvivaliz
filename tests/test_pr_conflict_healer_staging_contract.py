from pathlib import Path

workflow = Path('.github/workflows/pr-conflict-auto-healer.yml').read_text(encoding='utf-8')
healer = Path('scripts/pr_conflict_vm_heal.sh').read_text(encoding='utf-8')
assert 'install -d -m 700 /home/ubuntu/.cache/shopvivaliz-pr-healer' in workflow, 'workflow staging parent must be private and writable by ubuntu SSH sessions'
assert 'mktemp -d /home/ubuntu/.cache/shopvivaliz-pr-healer/run-' in workflow, 'workflow staging must use randomized private home directory'
assert 'remote_base="${remote_dir}/healer"' in workflow, 'staged healer files must live inside the private directory'
assert "rm -rf -- '${REMOTE_BASE%/*}'" in workflow, 'workflow cleanup must remove the private temp directory atomically'
assert '/tmp/shopvivaliz-pr-heal-' not in healer, 'Oracle-side healer checkout must not use shared /tmp'
assert 'work_parent=/home/ubuntu/.cache/shopvivaliz-pr-healer-work' in healer, 'Oracle-side healer must use a private home work parent'
assert 'mktemp -d "${work_parent}/pr-${pr_number}-XXXXXX"' in healer, 'Oracle-side healer checkout must be randomized under the private home parent'
print('pr-conflict-healer-staging-contract: ok')
