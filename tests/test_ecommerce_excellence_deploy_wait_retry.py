from pathlib import Path

WORKFLOW = Path('.github/workflows/ecommerce-excellence-audit.yml')
text = WORKFLOW.read_text(encoding='utf-8')

required = [
    'workflow_run:',
    'workflows: [Master Production Pipeline 24/7]',
    'types: [completed]',
    'branches: [main]',
    'production-evidence-gate:',
    'runs-on: ubuntu-latest',
    "DEPLOY_HEAD_SHA: ${{ github.event.workflow_run.head_sha }}",
    "DEPLOY_CONCLUSION: ${{ github.event.workflow_run.conclusion }}",
    'deployment/latest.json?ref=deployment-evidence',
    'deployed_sha',
    'should_run',
    'audit_sha',
]
missing = [fragment for fragment in required if fragment not in text]
if missing:
    raise SystemExit('event-driven production audit contract missing: ' + ', '.join(missing))

if 'await-production-evidence:' in text:
    raise SystemExit('legacy hosted polling job must be removed')
if 'for _ in $(seq 1 36); do' in text or 'sleep 20' in text:
    raise SystemExit('ecommerce production audit must not poll/sleep while waiting for deploy')

static = text.split('  static-audit:', 1)[1].split('\n  ', 1)[0]
if "if: ${{ github.event_name != 'workflow_run' }}" not in static:
    raise SystemExit('static audit must skip post-deploy workflow_run events')

gate = text.split('  production-evidence-gate:', 1)[1].split('\n  live-production-audit:', 1)[0]
if 'runs-on: ubuntu-latest' not in gate:
    raise SystemExit('production evidence gate must stay on hosted runner')
if 'for ' in gate and 'workflow_run' not in gate:
    raise SystemExit('production evidence gate must not contain retry loops')
if 'gh api' not in gate:
    raise SystemExit('production evidence gate must read immutable deployment evidence')
if 'DEPLOY_CONCLUSION' not in gate or 'DEPLOY_HEAD_SHA' not in gate:
    raise SystemExit('production evidence gate must bind to the completed deploy event')
if 'echo "should_run=false"' not in gate:
    raise SystemExit('non-deploy/failed master runs must skip live audit')
if 'echo "should_run=true"' not in gate:
    raise SystemExit('exact deployed master runs must enable live audit')

live = text.split('  live-production-audit:', 1)[1]
if 'needs: production-evidence-gate' not in live:
    raise SystemExit('live audit must depend on evidence gate')
if "needs.production-evidence-gate.outputs.should_run == 'true'" not in live:
    raise SystemExit('live audit must require exact production evidence')
if 'shopvivaliz-a1-deploy' not in live:
    raise SystemExit('live audit must remain on Oracle production runner')
if 'ref: ${{ needs.production-evidence-gate.outputs.audit_sha }}' not in live:
    raise SystemExit('live audit checkout must use the deployed SHA, not event/default ref')
if 'sleep 20' in live or 'deployment_wait_attempt' in live:
    raise SystemExit('Oracle production runner must never wait for deployment')

print('ecommerce excellence event-driven post-deploy contract: PASS')
