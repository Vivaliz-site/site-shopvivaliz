from datetime import datetime,timezone,timedelta
from decimal import Decimal
from okx_pilot.service import TradingPilotService
from okx_pilot.config import PilotConfig
from okx_pilot.domain import MarketSnapshot,InstrumentType,Mode

class Okx:
    def list_instruments(self): return ['BTC-USDT-SWAP']
    def market_snapshot(self,i): return MarketSnapshot(i,InstrumentType.SWAP,Decimal('100'),Decimal('100.1'),Decimal('100.05'),Decimal('10'),Decimal('10000'),Decimal('1000000'),Decimal('5'),datetime.now(timezone.utc))
class Model:
    def analyze(self,s):
        now=datetime.now(timezone.utc)
        from okx_pilot.decision import REQUIRED_LAYERS
        return dict(decision='HOLD',decision_id='d',instrument_id=s.instrument_id,instrument_type='swap',direction='neutral',entry=None,stop=None,targets=[],suggested_risk_usd='0',suggested_leverage='1',confidence=80,expected_rr='2',time_horizon='1h',invalidation='none',supporting_evidence=['x'],contrary_evidence=['y'],layers=list(REQUIRED_LAYERS),market_snapshot_ts=now.isoformat(),expires_at=(now+timedelta(seconds=30)).isoformat())

def test_hold_never_reaches_execution():
    svc=TradingPilotService(Okx(),Model(),PilotConfig(mode=Mode.SHADOW))
    out=svc.run_cycle(datetime.now(timezone.utc))
    assert out['decisions']==1 and out['executed']==0
