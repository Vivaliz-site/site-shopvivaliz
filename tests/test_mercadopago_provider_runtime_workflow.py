from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WF = ROOT / '.github' / 'workflows' / 'restore-mercadopago-runtime.yml'


def test() -> None:
    assert WF.exists(), 'Mercado Pago provider-specific workflow missing'
    text = WF.read_text(encoding='utf-8')
    for key in ('MERCADOPAGO_ACCESS_TOKEN','MERCADOPAGO_PUBLIC_KEY','MERCADOPAGO_WEBHOOK_SECRET'):
        assert f'secrets.{key}' in text
    assert 'environment: production' in text
    assert 'scripts/update-production-env.py' in text
    assert 'scripts/materialize-runtime-secrets.php' in text
    assert '/tmp/shopvivaliz-update-production-env.py' in text
    assert '/tmp/shopvivaliz-materialize-runtime-secrets.php' in text
    assert '/api/agent/integrations-health.php' in text
    assert 'mercado_pago' in text
    for forbidden in ('secrets.DB_USER','secrets.DB_PASS','secrets.DB_HOST','secrets.OLIST_REFRESH_TOKEN'):
        assert forbidden not in text, f'provider workflow must not touch {forbidden}'


if __name__ == '__main__':
    test()
    print('mercadopago provider runtime workflow contract: PASS')
