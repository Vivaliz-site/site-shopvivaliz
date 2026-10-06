from __future__ import annotations
from dataclasses import dataclass, field
from datetime import datetime
from decimal import Decimal
from enum import Enum
from typing import Any


class Mode(str, Enum):
    SHADOW = "SHADOW"
    PAPER = "PAPER"


class DecisionKind(str, Enum):
    TRADE = "TRADE"
    HOLD = "HOLD"
    REJECT = "REJECT"


class InstrumentType(str, Enum):
    SPOT = "SPOT"
    SWAP = "SWAP"
    FUTURES = "FUTURES"
    OPTION = "OPTION"


class Direction(str, Enum):
    LONG = "LONG"
    SHORT = "SHORT"


def _decimal(name: str, value: Any) -> Decimal:
    if not isinstance(value, Decimal):
        raise TypeError(f"{name} must be Decimal")
    if not value.is_finite():
        raise ValueError(f"{name} must be finite")
    return value


def _aware(name: str, value: datetime | None) -> datetime:
    if not isinstance(value, datetime) or value.tzinfo is None or value.utcoffset() is None:
        raise TypeError(f"{name} must be timezone-aware datetime")
    return value


@dataclass(frozen=True)
class MarketSnapshot:
    instrument: str
    instrument_type: InstrumentType
    last: Decimal
    bid: Decimal
    ask: Decimal
    volume_24h: Decimal
    timestamp: datetime
    open_24h: Decimal = Decimal("0")
    quantity_step: Decimal = Decimal("0.00000001")
    minimum_quantity: Decimal = Decimal("0.00000001")
    expires_at_ms: int = 0

    def __post_init__(self):
        for name in ("last", "bid", "ask", "volume_24h", "open_24h"):
            _decimal(name, getattr(self, name))
        for name in ("quantity_step", "minimum_quantity"):
            _decimal(name, getattr(self, name))
            if getattr(self, name) <= 0: raise ValueError("invalid instrument size")
        _aware("timestamp", self.timestamp)
        if self.last <= 0 or self.bid <= 0 or self.ask <= 0 or self.ask < self.bid or self.open_24h < 0:
            raise ValueError("invalid market prices")


@dataclass(frozen=True)
class DecisionIntent:
    decision_id: str
    decision: DecisionKind
    instrument: str
    instrument_type: InstrumentType
    direction: Direction
    entry_low: Decimal
    entry_high: Decimal
    stop: Decimal
    targets: tuple[Decimal, ...]
    confidence: Decimal
    expected_rr: Decimal
    suggested_risk: Decimal
    suggested_leverage: Decimal
    created_at: datetime
    expires_at: datetime
    layers: tuple[dict[str, Any], ...]
    thesis: str
    invalidation: str
    time_horizon: str = ""
    supporting_evidence: tuple[str, ...] = ()
    contrary_evidence: tuple[str, ...] = ()
    market_snapshot_ts: datetime | None = None

    def __post_init__(self):
        for name in ("entry_low", "entry_high", "stop", "confidence", "expected_rr", "suggested_risk", "suggested_leverage"):
            _decimal(name, getattr(self, name))
        for target in self.targets:
            _decimal("target", target)
        _aware("created_at", self.created_at)
        _aware("expires_at", self.expires_at)
        if self.market_snapshot_ts is not None:
            _aware("market_snapshot_ts", self.market_snapshot_ts)
        if self.entry_low > self.entry_high:
            raise ValueError("entry_low above entry_high")


@dataclass(frozen=True)
class PilotLimits:
    reference_capital: Decimal = Decimal("100")
    max_risk_per_trade: Decimal = Decimal("10")
    max_total_open_risk: Decimal = Decimal("30")
    max_correlated_risk: Decimal = Decimal("20")
    daily_loss_stop: Decimal = Decimal("15")
    cumulative_loss_stop: Decimal = Decimal("30")
    max_leverage: Decimal = Decimal("20")
    min_confidence: Decimal = Decimal("70")
    min_rr: Decimal = Decimal("1.5")
    max_market_age_seconds: Decimal = Decimal("5")


@dataclass(frozen=True)
class PilotState:
    open_risk: Decimal
    correlated_risk: Decimal
    daily_loss: Decimal
    cumulative_loss: Decimal

    def __post_init__(self):
        for name in ("open_risk", "correlated_risk", "daily_loss", "cumulative_loss"):
            _decimal(name, getattr(self, name))

    @classmethod
    def zero(cls) -> "PilotState":
        z = Decimal("0")
        return cls(z, z, z, z)


@dataclass(frozen=True)
class RiskVerdict:
    approved: bool
    reason: str
    intent: DecisionIntent
    quantity: Decimal = Decimal("0")
    risk_usd: Decimal = Decimal("0")

    def __post_init__(self):
        _decimal("quantity", self.quantity)
        _decimal("risk_usd", self.risk_usd)
