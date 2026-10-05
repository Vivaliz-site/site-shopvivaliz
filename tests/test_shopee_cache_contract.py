"""Isolated regression: Shopee persists only the canonical JSON cache."""
import importlib.util
import json
import os
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]


def load_renewer(filename):
    spec = importlib.util.spec_from_file_location('cache_contract_' + filename, ROOT / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def test_shopee_cache_preserves_environment_and_metadata(tmp_path, monkeypatch):
    module = load_renewer('daemon-shopee-token-renewer.py')
    assert not hasattr(module, 'update_env')
    assert not hasattr(module, 'write_env')
    environment = tmp_path / 'shared.env'
    environment.write_text('UNCHANGED=value\n')
    environment.chmod(0o640)
    cache = tmp_path / 'cache.json'
    cache.write_text(json.dumps({'tenant_fixture': 'preserved'}))
    cache.chmod(0o640)
    before = environment.read_bytes()
    monkeypatch.setattr(module, 'ENV_PATH', environment)
    monkeypatch.setattr(module, 'TOKEN_PATH', cache)
    module.update_token_cache('fixture-access', 'fixture-refresh', 3600)
    result = json.loads(cache.read_text())
    assert result['access_token'] == 'fixture-access'
    assert result['refresh_token'] == 'fixture-refresh'
    assert result['tenant_fixture'] == 'preserved'
    assert result['expires_at'] > result['updated_at']
    assert environment.read_bytes() == before
    if os.name != 'nt':
        assert cache.stat().st_mode & 0o777 == 0o640


@pytest.mark.skipif(os.name == 'nt', reason='POSIX permission boundary')
def test_google_rejects_group_writable_environment(tmp_path, monkeypatch):
    module = load_renewer('daemon-google-token-renewer.py')
    environment = tmp_path / 'shared.env'
    environment.write_text('UNCHANGED=value\n')
    environment.chmod(0o664)
    monkeypatch.setattr(module, 'ENV_PATH', environment)
    assert module.write_env({'FIXTURE': 'not-written'}) is False
    assert environment.read_text() == 'UNCHANGED=value\n'
