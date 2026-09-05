from pathlib import Path

WORKFLOW = Path('.github/workflows/ecommerce-excellence-audit.yml').read_text(encoding='utf-8')

assert 'classify-production-impact:' in WORKFLOW, 'workflow must classify whether a push changes production'
assert 'bash scripts/should-deploy-production.sh' in WORKFLOW, 'workflow must reuse the canonical production-impact classifier'
assert "github.event_name == 'push' && needs.classify-production-impact.outputs.should_deploy == 'true'" in WORKFLOW, 'push live audit must run only for production-impacting commits'
assert "github.event_name == 'schedule'" in WORKFLOW, 'scheduled live audit must remain enabled'
assert "github.event_name == 'workflow_dispatch'" in WORKFLOW, 'manual live audit must remain enabled'
assert 'EXPECTED_SHA: ${{ github.event_name == \'push\' && github.sha || \'\' }}' in WORKFLOW, 'only push runs may require exact current SHA evidence'
assert "if [ -n \"$EXPECTED_SHA\" ]; then" in WORKFLOW, 'evidence validation must distinguish exact-SHA push from last-deployed schedule/manual audit'
print('ecommerce-live-production-scope-contract: ok')
