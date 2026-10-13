from decimal import Decimal
from datetime import datetime, timezone
from pathlib import Path
import tempfile
from okx_pilot.domain import InstrumentType, MarketSnapshot, PilotState
from okx_pilot.orchestrator import PilotOrchestrator
from okx_pilot.scanner import MarketScanner
from okx_pilot.decision import HeuristicDecisionProvider
from okx_pilot.risk import RiskGateway
from okx_pilot.domain import PilotLimits
from okx_pilot.paper import PaperBroker


def test_cycle_is_autonomous_paper_only_and_persists_decision():
    snap=MarketSnapshot("AAA-USDT",InstrumentType.SPOT,Decimal("100"),Decimal("99.9"),Decimal("100.1"),Decimal("100000"),datetime.now(timezone.utc))
    with tempfile.TemporaryDirectory() as td:
        path=Path(td)/"events.jsonl"
        o=PilotOrchestrator(MarketScanner(),HeuristicDecisionProvider(),RiskGateway(PilotLimits()),PaperBroker(),path)
        result=o.run_cycle([snap], PilotState.zero(), now=datetime.now(timezone.utc))
        assert result.mode=="PAPER"
        assert result.decisions>=1
        assert path.exists() and '"decision_id"' in path.read_text()
        assert not hasattr(o,"live_executor")
