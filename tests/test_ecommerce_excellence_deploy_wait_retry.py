from pathlib import Path

workflow = Path('.github/workflows/ecommerce-excellence-audit.yml').read_text(encoding='utf-8')

required = [
    'workflow_run:',
    'Master Production Pipeline 24/7',
    'uses: ./.github/workflows/production-deploy-event-gate.yml',
    "if: github.event_name != 'workflow_run'",
    'needs: production-audit-gate',
    'ref: ${{ needs.production-audit-gate.outputs.production_sha }}',
]
missing = [item for item in required if item not in workflow]
if missing:
    raise SystemExit('event-driven ecommerce contract missing: ' + ', '.join(missing))

for forbidden in (
    'await-production-evidence:',
    'Wait for exact SHA production evidence',
    'for _ in $(seq 1 36); do',
    'sleep 20',
    'uses: ./.github/workflows/production-release-await.yml',
):
    if forbidden in workflow:
        raise SystemExit('obsolete ecommerce deploy polling remains: ' + forbidden)

# This audit runs on the same Marina VM as the deployed release.
# A loopback SSH key shared by runners can disappear during a job;
# never require it to inspect local runtime or publish audit artifacts.
audit_job = workflow.split('\n  live-production-audit:\n', 1)[1]
for marker in (
    'runs-on: [self-hosted, Linux, ARM64, shopvivaliz-audit]',
    'python3 scripts/ecommerce-excellence-audit.py --mode live',
    'SHOPVIVALIZ_ROOT="$root" php scripts/google-commerce-config-audit.php',
    'rm -f artifacts/ecommerce-live.json artifacts/ecommerce-live.md artifacts/google-commerce-config.json',
    'test -s artifacts/ecommerce-live.json',
    'test -s artifacts/ecommerce-live.md',
    'test -s artifacts/google-commerce-config.json',
):
    if marker not in audit_job:
        raise SystemExit('local Marina audit contract missing: ' + marker)
for forbidden in ('ubuntu@127.0.0.1', 'ORACLE_VM_SSH_KEY', 'scp ', '$HOME/.ssh/id_rsa'):
    if forbidden in audit_job:
        raise SystemExit('loopback SSH dependence persists: ' + forbidden)

print('ecommerce excellence deploy event contract: PASS')
