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

print('ecommerce excellence deploy event contract: PASS')
