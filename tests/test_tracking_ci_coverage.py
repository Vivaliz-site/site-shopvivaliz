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
