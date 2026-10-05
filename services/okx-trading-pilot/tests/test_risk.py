from datetime import datetime,timezone
from decimal import Decimal
from okx_pilot.config import PilotConfig
from okx_pilot.domain import TradeDecision,DecisionKind,InstrumentType
from okx_pilot.risk import RiskState,evaluate_risk

def decision(**kw):
    now=datetime.now(timezone.utc)
    base=dict(decision=DecisionKind.TRADE,decision_id='d',instrument_id='BTC-USDT-SWAP',instrument_type=InstrumentType.SWAP,direction='long',entry=Decimal('100'),stop=Decimal('95'),targets=(Decimal('110'),),suggested_risk_usd=Decimal('10'),suggested_leverage=Decimal('20'),confidence=70,expected_rr=Decimal('1.5'),time_horizon='1h',invalidation='x',supporting_evidence=('a',),contrary_evidence=('b',),layers=tuple(str(i) for i in range(20)),market_snapshot_ts=now,expires_at=now)
    base.update(kw); return TradeDecision(**base)

def test_exact_limits_are_allowed():
    st=RiskState(Decimal('20'),Decimal('10'),Decimal('0'),Decimal('0'))
    assert evaluate_risk(decision(),st,PilotConfig()).approved

def test_over_any_hard_limit_rejects():
    st=RiskState(Decimal('21'),Decimal('11'),Decimal('0'),Decimal('0'))
    v=evaluate_risk(decision(suggested_risk_usd=Decimal('10.01'),suggested_leverage=Decimal('20.01')),st,PilotConfig())
    assert not v.approved
    assert {'PER_TRADE_RISK','OPEN_RISK','CORRELATED_RISK','LEVERAGE'} <= set(v.reasons)

def test_confidence_rr_daily_and_kill_switch_reject_new_entries():
    st=RiskState(Decimal('0'),Decimal('0'),Decimal('15'),Decimal('30'))
    v=evaluate_risk(decision(confidence=69,expected_rr=Decimal('1.49')),st,PilotConfig())
    assert not v.approved
    assert {'CONFIDENCE','RR','DAILY_STOP','KILL_SWITCH'} <= set(v.reasons)
    assert v.defensive_management_allowed

def test_missing_stop_rejects():
    v=evaluate_risk(decision(stop=None),RiskState.zero(),PilotConfig())
    assert not v.approved and 'NO_PROTECTION' in v.reasons
