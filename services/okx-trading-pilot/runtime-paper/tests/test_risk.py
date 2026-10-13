from decimal import Decimal
from datetime import datetime, timezone, timedelta
from okx_pilot.domain import *
from okx_pilot.risk import RiskGateway


def intent(risk="10", confidence="70", rr="1.5"):
    now=datetime.now(timezone.utc)
    layers=tuple({"id":i,"assessment":"ok","evidence":[],"risk_flags":[]} for i in range(1,21))
    return DecisionIntent("d1",DecisionKind.TRADE,"AAA-USDT",InstrumentType.SPOT,Direction.LONG,Decimal("99"),Decimal("101"),Decimal("95"),(Decimal("110"),),Decimal(confidence),Decimal(rr),Decimal(risk),Decimal("1"),now,now+timedelta(seconds=120),layers,"t","i")


def market(age=0):
    return MarketSnapshot("AAA-USDT",InstrumentType.SPOT,Decimal("100"),Decimal("99.9"),Decimal("100.1"),Decimal("100000"),datetime.now(timezone.utc)-timedelta(seconds=age))


def test_exact_boundaries_authorize_but_over_blocks():
    g=RiskGateway(PilotLimits())
    state=PilotState(Decimal("20"),Decimal("10"),Decimal("14.99"),Decimal("29.99"))
    assert g.authorize(intent(), market(), state).approved
    assert not g.authorize(intent("10.01"), market(), PilotState.zero()).approved
    assert not g.authorize(intent(), market(), PilotState(Decimal("20.01"),Decimal("10"),Decimal("0"),Decimal("0"))).approved
    assert not g.authorize(intent(), market(), PilotState(Decimal("0"),Decimal("10.01"),Decimal("0"),Decimal("0"))).approved


def test_daily_cumulative_stale_confidence_rr_fail_closed():
    g=RiskGateway(PilotLimits())
    assert not g.authorize(intent(), market(), PilotState(Decimal("0"),Decimal("0"),Decimal("15"),Decimal("0"))).approved
    assert not g.authorize(intent(), market(), PilotState(Decimal("0"),Decimal("0"),Decimal("0"),Decimal("30"))).approved
    assert not g.authorize(intent(), market(age=6), PilotState.zero()).approved
    assert not g.authorize(intent(confidence="69.99"), market(), PilotState.zero()).approved
    assert not g.authorize(intent(rr="1.49"), market(), PilotState.zero()).approved

def test_authorize_accepts_explicit_clock_for_replay():
    g=RiskGateway(PilotLimits())
    m=market(age=100)
    assert not g.authorize(intent(), m, PilotState.zero()).approved
    assert g.authorize(intent(), m, PilotState.zero(), now=m.timestamp).approved
