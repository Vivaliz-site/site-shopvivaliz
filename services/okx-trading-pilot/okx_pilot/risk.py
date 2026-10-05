from dataclasses import dataclass
from decimal import Decimal
from .domain import TradeDecision, DecisionKind
from .config import PilotConfig

@dataclass(frozen=True)
class RiskState:
    open_risk: Decimal
    correlated_risk: Decimal
    daily_loss: Decimal
    cumulative_loss: Decimal
    @classmethod
    def zero(cls): return cls(Decimal('0'),Decimal('0'),Decimal('0'),Decimal('0'))

@dataclass(frozen=True)
class RiskVerdict:
    approved: bool
    reasons: tuple[str,...]
    approved_risk: Decimal
    approved_leverage: Decimal
    defensive_management_allowed: bool = True

def evaluate_risk(decision: TradeDecision, state: RiskState, config: PilotConfig) -> RiskVerdict:
    L=config.limits; reasons=[]
    if decision.decision is not DecisionKind.TRADE: reasons.append('NOT_TRADE')
    if decision.stop is None or decision.entry is None: reasons.append('NO_PROTECTION')
    if decision.suggested_risk_usd > L.max_risk_per_trade: reasons.append('PER_TRADE_RISK')
    if state.open_risk + decision.suggested_risk_usd > L.max_open_risk: reasons.append('OPEN_RISK')
    if state.correlated_risk + decision.suggested_risk_usd > L.max_correlated_risk: reasons.append('CORRELATED_RISK')
    if state.daily_loss >= L.daily_loss_stop: reasons.append('DAILY_STOP')
    if state.cumulative_loss >= L.cumulative_kill_switch: reasons.append('KILL_SWITCH')
    if decision.suggested_leverage > L.max_leverage: reasons.append('LEVERAGE')
    if decision.confidence < L.min_confidence: reasons.append('CONFIDENCE')
    if decision.expected_rr < L.min_rr: reasons.append('RR')
    return RiskVerdict(not reasons,tuple(reasons),min(decision.suggested_risk_usd,L.max_risk_per_trade),min(decision.suggested_leverage,L.max_leverage))
