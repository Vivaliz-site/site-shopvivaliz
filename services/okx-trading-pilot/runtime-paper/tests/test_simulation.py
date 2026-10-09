from decimal import Decimal
from datetime import datetime, timezone, timedelta
from okx_pilot.domain import InstrumentType, MarketSnapshot, PilotLimits
from okx_pilot.scanner import MarketScanner
from okx_pilot.decision import HeuristicDecisionProvider
from okx_pilot.risk import RiskGateway
from okx_pilot.paper import PaperBroker
from okx_pilot.simulation import SimulationRunner


def s(price, t):
    p=Decimal(str(price)); bid=p-Decimal("0.1"); ask=p+Decimal("0.1")
    return MarketSnapshot("AAA-USDT",InstrumentType.SPOT,p,bid,ask,Decimal("100000"),t)


def test_simulation_series_produces_closed_trade_metrics_without_live_path():
    t=datetime.now(timezone.utc)
    series=[s("100",t),s("110",t+timedelta(seconds=1)),s("110",t+timedelta(seconds=2)),s("100",t+timedelta(seconds=3))]
    runner=SimulationRunner(MarketScanner(),HeuristicDecisionProvider(),RiskGateway(PilotLimits()),PaperBroker())
    report=runner.run_series(series)
    assert report.mode == "PAPER"
    assert report.metrics.trades == 2
    assert report.metrics.wins == 1
    assert report.metrics.losses == 1
    assert report.final_equity != Decimal("100")
    assert not hasattr(runner, "live_executor")
