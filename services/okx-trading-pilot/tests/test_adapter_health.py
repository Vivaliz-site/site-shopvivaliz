from okx_pilot.okx_adapter import sanitize_error,AdapterConfig
from okx_pilot.health import build_health
from okx_pilot.domain import Mode

def test_sanitizes_secret_values():
    cfg=AdapterConfig(api_key='SENTINELKEY',secret='SENTINELSECRET',passphrase='SENTINELPASS')
    msg=sanitize_error('bad SENTINELKEY SENTINELSECRET SENTINELPASS',cfg)
    assert 'SENTINEL' not in msg

def test_health_contains_only_sanitized_operational_fields():
    h=build_health(mode=Mode.PAPER,authenticated=True,market_fresh=True,risk_gateway_ok=True,reconciled=True,open_risk='12.5',daily_stop=False,kill_switch=False,error='safe_error')
    assert h['mode']=='PAPER' and h['authenticated'] is True
    assert set(h)=={'mode','authenticated','market_fresh','risk_gateway_ok','reconciled','open_risk','daily_stop','kill_switch','error'}
