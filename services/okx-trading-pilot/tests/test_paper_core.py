from datetime import datetime, timedelta, timezone
from decimal import Decimal
import pytest
from okx_pilot.config import PilotConfig, PilotLimits
from okx_pilot.domain import Mode, DecisionKind, InstrumentType, TradeDecision
from okx_pilot.risk import RiskState
from okx_pilot.paper import PaperBroker
from okx_pilot.orchestrator import PaperOrchestrator

def decision(**overrides):
    now=datetime.now(timezone.utc)
    data=dict(decision=DecisionKind.TRADE,decision_id='d1',instrument_id='BTC-USDT-SWAP',
      instrument_type=InstrumentType.SWAP,direction='LONG',entry=Decimal('100'),stop=Decimal('95'),
      targets=(Decimal('110'),),suggested_risk_usd=Decimal('10'),suggested_leverage=Decimal('20'),
      confidence=70,expected_rr=Decimal('1.5'),time_horizon='intraday',invalidation='stop',
      supporting_evidence=('x',),contrary_evidence=(),layers=tuple(str(i) for i in range(1,21)),
      market_snapshot_ts=now,expires_at=now+timedelta(seconds=120))
    data.update(overrides); return TradeDecision(**data)

def test_approved_limits_exact():
    L=PilotLimits()
    assert [L.pilot_capital,L.max_risk_per_trade,L.max_open_risk,L.max_correlated_risk,
      L.daily_loss_stop,L.cumulative_kill_switch,L.max_leverage,L.min_rr]==[
      Decimal('100'),Decimal('10'),Decimal('30'),Decimal('20'),Decimal('15'),Decimal('30'),Decimal('20'),Decimal('1.5')]
    assert L.min_confidence==70

def test_paper_fill_has_costs_and_never_live():
    o=PaperOrchestrator(PilotConfig(mode=Mode.PAPER))
    result=o.process(decision(),RiskState.zero())
    assert result['status']=='PAPER_FILLED'
    assert result['fill'].fee > 0 and result['fill'].fill_price > Decimal('100')
    assert o.live_execution_available is False

@pytest.mark.parametrize('state,reason',[
 (RiskState(Decimal('21'),Decimal('0'),Decimal('0'),Decimal('0')),'OPEN_RISK'),
 (RiskState(Decimal('0'),Decimal('11'),Decimal('0'),Decimal('0')),'CORRELATED_RISK'),
 (RiskState(Decimal('0'),Decimal('0'),Decimal('15'),Decimal('0')),'DAILY_STOP'),
 (RiskState(Decimal('0'),Decimal('0'),Decimal('0'),Decimal('30')),'KILL_SWITCH')])
def test_limits_fail_closed(state,reason):
    r=PaperOrchestrator(PilotConfig(mode=Mode.PAPER)).process(decision(),state)
    assert r['status']=='REJECTED' and reason in r['reasons']

def test_stale_decision_rejected():
    now=datetime.now(timezone.utc)
    r=PaperOrchestrator(PilotConfig(mode=Mode.PAPER)).process(decision(expires_at=now-timedelta(seconds=1)),RiskState.zero(),now)
    assert r=={'status':'REJECTED','reasons':('STALE_DECISION',)}

def test_live_flag_is_rejected():
    with pytest.raises(ValueError):
        PaperOrchestrator(PilotConfig(mode=Mode.PAPER,live_enabled=True))
