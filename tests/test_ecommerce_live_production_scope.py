from pathlib import Path

WORKFLOW = Path('.github/workflows/ecommerce-excellence-audit.yml').read_text(encoding='utf-8')


def test_live_audit_uses_verified_deployment_gate():
    assert 'production-audit-gate:' in WORKFLOW
    assert 'uses: ./.github/workflows/production-deploy-event-gate.yml' in WORKFLOW
    assert 'needs: production-audit-gate' in WORKFLOW
    assert "if: needs.production-audit-gate.outputs.should_run == 'true'" in WORKFLOW
    assert 'ref: ${{ needs.production-audit-gate.outputs.production_sha }}' in WORKFLOW
    assert 'expected_sha: ${{ github.event_name ==' in WORKFLOW
    assert "github.event.workflow_run.head_sha || '' }}" in WORKFLOW


def test_manual_and_scheduled_audits_keep_the_production_gate():
    assert "github.event_name == 'workflow_run'" in WORKFLOW
    assert "github.event_name == 'schedule'" in WORKFLOW
    assert "github.event_name == 'workflow_dispatch' && inputs.pr_replay != true" in WORKFLOW
    assert 'source_conclusion: ${{ github.event_name ==' in WORKFLOW
    assert "github.event.workflow_run.conclusion || '' }}" in WORKFLOW
    assert 'await-production-evidence:' not in WORKFLOW


if __name__ == '__main__':
    test_live_audit_uses_verified_deployment_gate()
    test_manual_and_scheduled_audits_keep_the_production_gate()
    print('ecommerce-live-production-scope-contract: ok')
