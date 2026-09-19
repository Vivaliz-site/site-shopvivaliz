"""Mercado Livre runtime recovery workflow contract.

After the MLRR OAuth ownership plan (Task 9, step 5) the recovery workflow first
resolves who owns the Mercado Livre credentials on the host. Under MLRR
ownership it may not rebuild ``ml-tokens.json`` nor call ``svih_ml(true)``: it
drives the MLRR maintenance service and verifies that ShopVivaLiz still serves
``/api/ml/me`` from the published access-only snapshot.
"""

from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WF = ROOT / '.github' / 'workflows' / 'restore-mercadolivre-runtime.yml'

OWNERSHIP_STEP = '- name: Resolve Mercado Livre credential ownership'
MLRR_STEP = '- name: Verify MLRR-owned Mercado Livre runtime'
LEGACY_STEP = '- name: Backup and refresh legacy Mercado Livre OAuth'
CLEANUP_STEP = '- name: Cleanup temporary runtime tools'


def workflow_text() -> str:
    assert WF.exists(), 'Mercado Livre recovery workflow missing'
    return WF.read_text(encoding='utf-8')


def section(text: str, start_marker: str, end_marker: str) -> str:
    start = text.index(start_marker)
    end = text.index(end_marker, start)
    return text[start:end]


def test_static_credentials_remain_the_only_secrets() -> None:
    text = workflow_text()
    for key in ('ML_CLIENT_ID', 'ML_CLIENT_SECRET', 'ML_REDIRECT_URI'):
        assert f'secrets.{key}' in text
        assert f'{key}_VALUE' in text
    assert 'secrets.ML_ACCESS_TOKEN' not in text
    assert 'secrets.ML_REFRESH_TOKEN' not in text
    assert 'scripts/update-production-env.py' in text
    assert 'scripts/materialize-runtime-secrets.php' in text
    assert 'rm -f "$HOME/.ssh/id_rsa" "$HOME/.ssh/known_hosts"' in text


def test_ownership_is_resolved_on_the_host_before_any_recovery_branch() -> None:
    text = workflow_text()
    ownership = section(text, OWNERSHIP_STEP, MLRR_STEP)
    assert 'id: ownership' in ownership
    assert 'runtime-secrets.php' in ownership
    assert 'ML_TOKEN_OWNER' in ownership
    assert 'owner=' in ownership
    assert '$GITHUB_OUTPUT' in ownership
    # Ownership is read from the protected runtime, never by sourcing .env.
    assert 'source ' not in ownership

    assert text.index(OWNERSHIP_STEP) < text.index(MLRR_STEP) < text.index(LEGACY_STEP)


def test_mlrr_branch_drives_mlrr_and_never_rebuilds_the_legacy_store() -> None:
    text = workflow_text()
    mlrr = section(text, MLRR_STEP, LEGACY_STEP)
    assert "steps.ownership.outputs.owner == 'mlrr'" in mlrr
    assert 'mlrr-oauth-maintenance.service' in mlrr
    assert '/home/ubuntu/mercadolivre-returns-recovery-deploy/current/bin/ml-runtime-status' in mlrr
    assert '/api/ml/me' in mlrr
    assert 'ml-tokens.json' not in mlrr
    assert 'svih_ml(true)' not in mlrr
    assert 'refresh_token' not in mlrr


def test_legacy_branch_only_runs_while_shopvivaliz_still_owns_the_grant() -> None:
    text = workflow_text()
    legacy = section(text, LEGACY_STEP, CLEANUP_STEP)
    assert "steps.ownership.outputs.owner == 'legacy'" in legacy
    assert 'ml-tokens.json' in legacy
    assert 'backup.' in legacy
    assert 'svih_ml(true)' in legacy
    assert 'mercadolivre_provider_http=200' in legacy


def main() -> None:
    test_static_credentials_remain_the_only_secrets()
    test_ownership_is_resolved_on_the_host_before_any_recovery_branch()
    test_mlrr_branch_drives_mlrr_and_never_rebuilds_the_legacy_store()
    test_legacy_branch_only_runs_while_shopvivaliz_still_owns_the_grant()
    print('mercadolivre runtime recovery workflow contract: PASS')


if __name__ == '__main__':
    main()
