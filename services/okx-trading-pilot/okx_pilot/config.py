from dataclasses import dataclass, field
from decimal import Decimal
from .domain import Mode

@dataclass(frozen=True)
class PilotLimits:
    pilot_capital: Decimal = Decimal('100')
    max_risk_per_trade: Decimal = Decimal('10')
    max_open_risk: Decimal = Decimal('30')
    max_correlated_risk: Decimal = Decimal('20')
    daily_loss_stop: Decimal = Decimal('15')
    cumulative_kill_switch: Decimal = Decimal('30')
    max_leverage: Decimal = Decimal('20')
    min_confidence: int = 70
    min_rr: Decimal = Decimal('1.5')
    timezone: str = 'America/Sao_Paulo'
    def __post_init__(self):
        decimals = [self.pilot_capital, self.max_risk_per_trade, self.max_open_risk, self.max_correlated_risk, self.daily_loss_stop, self.cumulative_kill_switch, self.max_leverage, self.min_rr]
        if any(v <= 0 for v in decimals):
            raise ValueError('limits must be positive')
        if not 0 <= self.min_confidence <= 100:
            raise ValueError('min_confidence must be 0..100')

@dataclass(frozen=True)
class PilotConfig:
    limits: PilotLimits = field(default_factory=PilotLimits)
    mode: Mode = Mode.SHADOW
    withdrawal_enabled: bool = False
    live_enabled: bool = False
    max_spread_bps: Decimal = Decimal('30')
    max_slippage_bps: Decimal = Decimal('40')
    min_depth_usd: Decimal = Decimal('5000')
    min_volume_24h_usd: Decimal = Decimal('100000')
    market_ttl_seconds: int = 15
    def __post_init__(self):
        if self.withdrawal_enabled:
            raise ValueError('withdrawals are prohibited')
        if self.market_ttl_seconds <= 0:
            raise ValueError('market_ttl_seconds must be positive')
