from pathlib import Path
from decimal import Decimal
from okx_pilot.state import PilotStateStore
from okx_pilot.domain import Mode

def test_persists_mode_and_rejects_duplicate_order_intent(tmp_path:Path):
    db=tmp_path/'pilot.db'; s=PilotStateStore(db)
    s.set_mode(Mode.PAPER)
    assert s.get_mode() is Mode.PAPER
    assert s.reserve_order_intent('x') is True
    assert s.reserve_order_intent('x') is False
    s.close()
    s2=PilotStateStore(db)
    assert s2.get_mode() is Mode.PAPER
    assert s2.reserve_order_intent('x') is False

def test_live_promotion_requires_explicit_operator_event(tmp_path:Path):
    s=PilotStateStore(tmp_path/'pilot.db')
    assert not s.promote_live(False)
    assert s.get_mode() is Mode.SHADOW
    assert s.promote_live(True)
    assert s.get_mode() is Mode.LIVE_PILOT

def test_persists_daily_and_pilot_baselines(tmp_path:Path):
    s=PilotStateStore(tmp_path/'pilot.db')
    s.set_baseline('daily','123.45')
    s.set_baseline('pilot','150.00')
    assert s.get_baseline('daily')==Decimal('123.45')
    assert s.get_baseline('pilot')==Decimal('150.00')

def test_audit_is_append_only_and_sanitized_shape(tmp_path:Path):
    s=PilotStateStore(tmp_path/'pilot.db')
    s.append_event('DECISION', {'decision_id':'d1','status':'HOLD'})
    s.append_event('RISK', {'decision_id':'d1','approved':False})
    events=s.events()
    assert [e['kind'] for e in events]==['DECISION','RISK']
    assert events[0]['payload']['decision_id']=='d1'
