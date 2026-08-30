from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WF = ROOT / '.github' / 'workflows' / 'master-production-pipeline.yml'


def test() -> None:
    text = WF.read_text(encoding='utf-8')
    for key in ('ML_CLIENT_ID', 'ML_CLIENT_SECRET', 'ML_REDIRECT_URI'):
        assert f'secrets.{key}' in text, f'master deploy missing {key}'
        assert f'{key}_VALUE' in text, f'master deploy missing {key} payload'
    assert 'scripts/update-production-env.py' in text
    assert 'shopvivaliz-update-production-env.py' in text
    assert "keys = ('ML_CLIENT_ID', 'ML_CLIENT_SECRET', 'ML_REDIRECT_URI')" in text
    assert 'secrets.ML_ACCESS_TOKEN' not in text
    assert 'secrets.ML_REFRESH_TOKEN' not in text


if __name__ == '__main__':
    test()
    print('master pipeline ML static runtime contract: PASS')
