from datetime import datetime,timedelta,timezone
import pytest
from okx_pilot.decision import decision_from_payload,REQUIRED_LAYERS
from okx_pilot.domain import DecisionKind

def payload():
    now=datetime.now(timezone.utc)
    return dict(decision='TRADE',decision_id='d1',instrument_id='BTC-USDT-SWAP',instrument_type='swap',direction='long',entry='100',stop='95',targets=['110'],suggested_risk_usd='5',suggested_leverage='3',confidence=80,expected_rr='2',time_horizon='1h',invalidation='breaks structure',supporting_evidence=['trend'],contrary_evidence=['funding'],layers=list(REQUIRED_LAYERS),market_snapshot_ts=now.isoformat(),expires_at=(now+timedelta(seconds=30)).isoformat())

def test_accepts_complete_twenty_layer_trade():
    d=decision_from_payload(payload(),datetime.now(timezone.utc))
    assert d.decision is DecisionKind.TRADE and len(d.layers)==20

def test_rejects_missing_contrary_evidence():
    p=payload(); p['contrary_evidence']=[]
    with pytest.raises(ValueError): decision_from_payload(p,datetime.now(timezone.utc))

def test_rejects_expired_intent():
    p=payload(); p['expires_at']=(datetime.now(timezone.utc)-timedelta(seconds=1)).isoformat()
    with pytest.raises(ValueError): decision_from_payload(p,datetime.now(timezone.utc))

def test_rejects_risk_override_or_raw_order_fields():
    p=payload(); p['max_risk_per_trade']='99'
    with pytest.raises(ValueError): decision_from_payload(p,datetime.now(timezone.utc))
