from datetime import datetime,timedelta,timezone
from decimal import Decimal
from okx_pilot.domain import MarketSnapshot,InstrumentType
from okx_pilot.config import PilotConfig
from okx_pilot.scanner import evaluate_snapshot

def snap(**kw):
    base=dict(instrument_id='BTC-USDT-SWAP',instrument_type=InstrumentType.SWAP,bid=Decimal('100'),ask=Decimal('100.1'),last=Decimal('100.05'),spread_bps=Decimal('10'),depth_usd=Decimal('10000'),volume_24h_usd=Decimal('1000000'),expected_slippage_bps=Decimal('5'),ts=datetime.now(timezone.utc))
    base.update(kw); return MarketSnapshot(**base)

def test_rejects_stale_snapshot():
    r=evaluate_snapshot(snap(ts=datetime.now(timezone.utc)-timedelta(seconds=60)),PilotConfig(),datetime.now(timezone.utc))
    assert not r.eligible and 'STALE_DATA' in r.reasons

def test_rejects_bad_market_quality():
    r=evaluate_snapshot(snap(spread_bps=Decimal('31'),depth_usd=Decimal('100'),expected_slippage_bps=Decimal('41')),PilotConfig(),datetime.now(timezone.utc))
    assert not r.eligible
    assert {'SPREAD','DEPTH','SLIPPAGE'} <= set(r.reasons)

def test_options_need_bounded_loss_inputs():
    r=evaluate_snapshot(snap(instrument_type=InstrumentType.OPTION,max_loss_known=False),PilotConfig(),datetime.now(timezone.utc))
    assert not r.eligible and 'MAX_LOSS_UNKNOWN' in r.reasons

def test_valid_liquid_candidate_passes():
    assert evaluate_snapshot(snap(),PilotConfig(),datetime.now(timezone.utc)).eligible
