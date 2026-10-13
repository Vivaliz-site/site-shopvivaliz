from decimal import Decimal
from datetime import datetime, timezone, timedelta
from okx_pilot.domain import *
from okx_pilot.paper import PaperBroker, PaperScenario
from okx_pilot.metrics import PerformanceTracker


def verdict():
    now=datetime.now(timezone.utc)
    intent=DecisionIntent("d1",DecisionKind.TRADE,"AAA-USDT",InstrumentType.SPOT,Direction.LONG,Decimal("99"),Decimal("101"),Decimal("95"),(Decimal("110"),),Decimal("80"),Decimal("2"),Decimal("5"),Decimal("1"),now,now+timedelta(seconds=120),tuple({"id":i,"assessment":"ok","evidence":[],"risk_flags":[]} for i in range(1,21)),"t","i")
    return RiskVerdict(True,"approved",intent,Decimal("0.5"),Decimal("5"))


def market(price="100", bid="99.9", ask="100.1"):
    return MarketSnapshot("AAA-USDT",InstrumentType.SPOT,Decimal(price),Decimal(bid),Decimal(ask),Decimal("100000"),datetime.now(timezone.utc))


def test_paper_fill_accounts_for_spread_slippage_and_fee_and_stop():
    b=PaperBroker(starting_equity=Decimal("100"), fee_bps=Decimal("10"))
    ex=b.submit(verdict(), market(), PaperScenario(slippage_bps=Decimal("5"), fill_fraction=Decimal("1")))
    assert ex.filled_quantity == Decimal("0.5")
    assert ex.entry_price > Decimal("100.1")
    assert ex.fee > Decimal("0")
    events=b.manage_positions([market(price="94",bid="93.9",ask="94.1")])
    assert any(e.kind=="STOP" for e in events)
    assert b.equity < Decimal("100")


def test_metrics_are_computed_from_closed_trades():
    p=PerformanceTracker(Decimal("100"))
    p.record(Decimal("5")); p.record(Decimal("-2")); p.record(Decimal("3"))
    m=p.snapshot()
    assert m.trades==3 and m.wins==2 and m.win_rate==Decimal("66.6667")
    assert m.profit_factor==Decimal("4")
    assert m.expectancy==Decimal("2")

def test_paper_broker_exposes_open_risk_state_and_rejects_duplicate_instrument():
    b=PaperBroker(starting_equity=Decimal("100"))
    v=verdict()
    first=b.submit(v, market(), PaperScenario())
    second=b.submit(v, market(), PaperScenario())
    assert first.accepted
    assert not second.accepted and second.reason == "position_exists"
    state=b.pilot_state()
    assert Decimal("0") < state.open_risk <= Decimal("5")
    assert state.correlated_risk == state.open_risk
