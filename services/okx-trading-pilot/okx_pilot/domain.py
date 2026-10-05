from dataclasses import dataclass
from datetime import datetime
from decimal import Decimal
from enum import Enum
from typing import Any

class Mode(str, Enum):
    SHADOW = 'SHADOW'
    PAPER = 'PAPER'
    LIVE_PILOT = 'LIVE_PILOT'

class DecisionKind(str, Enum):
    TRADE = 'TRADE'
    HOLD = 'HOLD'
    REJECT = 'REJECT'

class InstrumentType(str, Enum):
    SPOT = 'spot'
    SWAP = 'swap'
    FUTURES = 'futures'
    OPTION = 'option'

class LifecycleState(str, Enum):
    DISCOVERED = 'DISCOVERED'
    ANALYZING = 'ANALYZING'
    CANDIDATE = 'CANDIDATE'
    RISK_CHECK = 'RISK_CHECK'
    APPROVED = 'APPROVED'
    REJECTED = 'REJECTED'
    ORDER_SENT = 'ORDER_SENT'
    PARTIAL = 'PARTIAL'
    FILLED = 'FILLED'
    PROTECTED = 'PROTECTED'
    EXITED = 'EXITED'
    RECONCILED = 'RECONCILED'

@dataclass(frozen=True)
class MarketSnapshot:
    instrument_id: str
    instrument_type: InstrumentType
    bid: Decimal
    ask: Decimal
    last: Decimal
    spread_bps: Decimal
    depth_usd: Decimal
    volume_24h_usd: Decimal
    expected_slippage_bps: Decimal
    ts: datetime
    active: bool = True
    metadata_ok: bool = True
    max_loss_known: bool = True

@dataclass(frozen=True)
class TradeDecision:
    decision: DecisionKind
    decision_id: str
    instrument_id: str
    instrument_type: InstrumentType
    direction: str
    entry: Decimal | None
    stop: Decimal | None
    targets: tuple[Decimal, ...]
    suggested_risk_usd: Decimal
    suggested_leverage: Decimal
    confidence: int
    expected_rr: Decimal
    time_horizon: str
    invalidation: str
    supporting_evidence: tuple[str, ...]
    contrary_evidence: tuple[str, ...]
    layers: tuple[str, ...]
    market_snapshot_ts: datetime
    expires_at: datetime
    extra: dict[str, Any] | None = None
