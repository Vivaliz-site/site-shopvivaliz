from __future__ import annotations
from datetime import datetime, timezone
from decimal import Decimal
from .domain import DecisionKind, Direction, MarketSnapshot, PilotLimits, PilotState, RiskVerdict, DecisionIntent


class RiskGateway:
    def __init__(self, limits: PilotLimits):
        self.limits = limits

    def authorize(self, intent: DecisionIntent, market: MarketSnapshot, state: PilotState, now: datetime | None = None) -> RiskVerdict:
        deny = lambda reason: RiskVerdict(False, reason, intent)
        now = now or datetime.now(timezone.utc)
        if intent.instrument != market.instrument or intent.instrument_type is not market.instrument_type: return deny("instrument_mismatch")
        if market.instrument_type.value not in ("SPOT","SWAP","FUTURES"): return deny("unsupported_instrument")
        if (market.timestamp - now).total_seconds() > 1: return deny("future_market")
        if intent.expires_at <= now: return deny("expired_decision")
        if intent.decision is not DecisionKind.TRADE: return deny("not_trade")
        if market.instrument_type.value == "SPOT" and intent.direction is Direction.SHORT: return deny("spot_short_disabled")
        if (now - market.timestamp).total_seconds() > float(self.limits.max_market_age_seconds): return deny("stale_market")
        if intent.confidence < self.limits.min_confidence: return deny("confidence")
        if intent.expected_rr < self.limits.min_rr: return deny("rr")
        if intent.suggested_risk <= 0 or intent.suggested_risk > self.limits.max_risk_per_trade: return deny("trade_risk")
        if intent.suggested_leverage <= 0 or intent.suggested_leverage > self.limits.max_leverage: return deny("leverage")
        if state.open_risk + intent.suggested_risk > self.limits.max_total_open_risk: return deny("total_open_risk")
        if state.correlated_risk + intent.suggested_risk > self.limits.max_correlated_risk: return deny("correlated_risk")
        if state.daily_loss >= self.limits.daily_loss_stop: return deny("daily_stop")
        if state.cumulative_loss >= self.limits.cumulative_loss_stop: return deny("cumulative_kill")
        if not (intent.entry_low <= market.last <= intent.entry_high): return deny("outside_entry_band")
        stop_distance = abs(market.last - intent.stop)
        if stop_distance <= 0: return deny("invalid_stop")
        if intent.direction is Direction.LONG and intent.stop >= market.last: return deny("invalid_stop")
        if intent.direction is Direction.SHORT and intent.stop <= market.last: return deny("invalid_stop")
        quantity = (intent.suggested_risk / stop_distance).quantize(Decimal("0.00000001"))
        return RiskVerdict(True, "approved", intent, quantity, intent.suggested_risk)
