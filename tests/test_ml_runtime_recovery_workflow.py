from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WF = ROOT / '.github' / 'workflows' / 'restore-mercadolivre-runtime.yml'


def test() -> None:
    assert WF.exists(), 'Mercado Livre recovery workflow missing'
    text = WF.read_text(encoding='utf-8')
    for key in ('ML_CLIENT_ID', 'ML_CLIENT_SECRET', 'ML_REDIRECT_URI'):
        assert f'secrets.{key}' in text
        assert f'{key}_VALUE' in text
    assert 'secrets.ML_ACCESS_TOKEN' not in text
    assert 'secrets.ML_REFRESH_TOKEN' not in text
    assert 'scripts/update-production-env.py' in text
    assert 'scripts/materialize-runtime-secrets.php' in text
    assert 'ml-tokens.json' in text
    assert 'backup.' in text
    assert 'svih_ml(true)' in text
    assert 'mercadolivre_provider_http=200' in text
    assert 'push:' in text and 'branches: [main]' in text
    assert '.github/workflows/restore-mercadolivre-runtime.yml' in text
    assert "[restore-ml-runtime]" in text
    assert "github.event_name == 'workflow_dispatch'" in text
    assert 'rm -f "$HOME/.ssh/id_rsa" "$HOME/.ssh/known_hosts"' in text


if __name__ == '__main__':
    test()
    print('mercadolivre runtime recovery workflow contract: PASS')
