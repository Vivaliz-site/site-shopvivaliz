from pathlib import Path
import re

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

def job_body(name: str) -> str:
    match = re.search(
        rf"^  {re.escape(name)}:\n(?P<body>.*?)(?=^  [A-Za-z0-9_-]+:\n|\Z)",
        text,
        re.M | re.S,
    )
    if not match:
        raise SystemExit(f'job not found: {name}')
    return match.group('body')


static = job_body('static-audit')
if "if: ${{ github.event_name != 'workflow_run' }}" not in static:
    raise SystemExit('static audit must skip post-deploy workflow_run events')

gate = job_body('production-evidence-gate')
if 'runs-on: ubuntu-latest' not in gate:
    raise SystemExit('production evidence gate must stay on hosted runner')
if 'for ' in gate and 'workflow_run' not in gate:
    raise SystemExit('production evidence gate must not contain retry loops')
if 'gh api' not in gate:
    raise SystemExit('production evidence gate must read immutable deployment evidence')
if 'DEPLOY_CONCLUSION' not in gate or 'DEPLOY_HEAD_SHA' not in gate:
    raise SystemExit('production evidence gate must bind to the completed deploy event')
if 'should_run=false' not in gate:
    raise SystemExit('non-deploy/failed master runs must skip live audit')
if 'should_run=true' not in gate:
    raise SystemExit('exact deployed master runs must enable live audit')
if 'exit 0' in gate:
    raise SystemExit('production evidence gate must not use explicit fail-open exits')

live = job_body('live-production-audit')
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
