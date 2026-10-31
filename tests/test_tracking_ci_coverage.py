from pathlib import Path


def test_tracking_ci_covers_changed_runtime_contract_inputs():
    workflow = Path('.github/workflows/tracking-policy-regression.yml').read_text()
    assert 'run: python -m pytest -q --tb=short' in workflow
    assert 'PyYAML==6.0.3' in workflow, 'full suite imports yaml during collection'
    paths = [
        'scripts/codex-native-profile-failover.py', 'scripts/google_ads/client.py',
        'ai_collaboration.py', 'scripts/ai/retired_executor.py',
        'daemon-token-renewer.py', 'daemon-shopee-token-renewer.py',
        'daemon-google-token-renewer.py', 'scripts/env_keyset_guard.py',
        '.github/workflows/ecommerce-excellence-audit.yml',
        '.github/workflows/provision-governed-backend-ci-runners.yml',
        '.github/workflows/issue-comment-dispatcher.yml',
        'tests/test_tracking_ci_coverage.py',
    ]
    for path in paths:
        assert workflow.count("      - '" + path + "'") == 2, path


def test_tracking_ci_selects_every_retired_ads_writer_and_guard():
    from fnmatch import fnmatchcase
    import yaml

    workflow = yaml.load(
        Path('.github/workflows/tracking-policy-regression.yml').read_text(),
        Loader=yaml.BaseLoader,
    )
    guarded_inputs = [
        'scripts/google_ads_legacy_budget_policy.py',
        'scripts/google_ads_antique_decore_status.py',
        'scripts/google_ads_antique_decore_status_v2.py',
        'scripts/google_ads_antique_decore_status_v3.py',
        'scripts/google_ads_antique_decore_automation.py',
        'scripts/google_ads_operator_core_recovery.py',
        'scripts/google_ads_sales_round1_delivery.py',
        'tests/test_google_ads_legacy_budget_policy.py',
        '.github/workflows/google-ads-antique-decore-status.yml',
        '.github/workflows/google-ads-core-recovery.yml',
        '.github/workflows/sales-round-01-google-ads-delivery.yml',
    ]
    for event in ('pull_request', 'push'):
        patterns = workflow['on'][event]['paths']
        for path in guarded_inputs:
            assert any(fnmatchcase(path, pattern) for pattern in patterns), (event, path)
