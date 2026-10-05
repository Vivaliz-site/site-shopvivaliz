from datetime import datetime
from decimal import Decimal, InvalidOperation
from .domain import TradeDecision, DecisionKind, InstrumentType

REQUIRED_LAYERS=('market_regime','multi_timeframe','price_structure','momentum','volume','order_book','microstructure','spread_slippage','volatility','derivatives','funding_basis','liquidation_leverage','correlation','account_context','asymmetry','probability_payoff','instrument_selection','timing_staleness','invalidation','adversarial_review')
FORBIDDEN_KEYS={'max_risk_per_trade','max_open_risk','max_correlated_risk','daily_loss_stop','cumulative_kill_switch','withdrawal','raw_order','exchange_command'}

def _dt(v):
    d=datetime.fromisoformat(v) if isinstance(v,str) else v
    if d.tzinfo is None: raise ValueError('timestamps must be timezone-aware')
    return d

def decision_from_payload(payload: dict, now: datetime) -> TradeDecision:
    if FORBIDDEN_KEYS.intersection(payload): raise ValueError('decision payload may not override controls')
    layers=tuple(payload.get('layers') or ())
    if set(layers) != set(REQUIRED_LAYERS) or len(layers)!=20: raise ValueError('all 20 layers required exactly once')
    if not payload.get('supporting_evidence') or not payload.get('contrary_evidence'): raise ValueError('supporting and contrary evidence required')
    expires=_dt(payload['expires_at'])
    if expires <= now: raise ValueError('decision expired')
    conf=int(payload['confidence'])
    if not 0 <= conf <= 100: raise ValueError('confidence out of range')
    try:
        dec=lambda x: None if x is None else Decimal(str(x))
        rr=dec(payload['expected_rr'])
    except (InvalidOperation, TypeError): raise ValueError('invalid decimal')
    return TradeDecision(decision=DecisionKind(payload['decision']),decision_id=str(payload['decision_id']),instrument_id=str(payload['instrument_id']),instrument_type=InstrumentType(payload['instrument_type']),direction=str(payload['direction']),entry=dec(payload.get('entry')),stop=dec(payload.get('stop')),targets=tuple(dec(x) for x in payload.get('targets',[])),suggested_risk_usd=dec(payload['suggested_risk_usd']),suggested_leverage=dec(payload['suggested_leverage']),confidence=conf,expected_rr=rr,time_horizon=str(payload['time_horizon']),invalidation=str(payload['invalidation']),supporting_evidence=tuple(map(str,payload['supporting_evidence'])),contrary_evidence=tuple(map(str,payload['contrary_evidence'])),layers=layers,market_snapshot_ts=_dt(payload['market_snapshot_ts']),expires_at=expires)
