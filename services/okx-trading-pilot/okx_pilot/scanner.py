from dataclasses import dataclass
from datetime import datetime, timezone
from .domain import MarketSnapshot, InstrumentType
from .config import PilotConfig

@dataclass(frozen=True)
class EligibilityResult:
    eligible: bool
    reasons: tuple[str, ...]

def evaluate_snapshot(snapshot: MarketSnapshot, config: PilotConfig, now: datetime) -> EligibilityResult:
    reasons=[]
    age=(now.astimezone(timezone.utc)-snapshot.ts.astimezone(timezone.utc)).total_seconds()
    if age > config.market_ttl_seconds: reasons.append('STALE_DATA')
    if not snapshot.active: reasons.append('INACTIVE')
    if not snapshot.metadata_ok: reasons.append('METADATA')
    if snapshot.spread_bps > config.max_spread_bps: reasons.append('SPREAD')
    if snapshot.depth_usd < config.min_depth_usd: reasons.append('DEPTH')
    if snapshot.volume_24h_usd < config.min_volume_24h_usd: reasons.append('VOLUME')
    if snapshot.expected_slippage_bps > config.max_slippage_bps: reasons.append('SLIPPAGE')
    if snapshot.instrument_type is InstrumentType.OPTION and not snapshot.max_loss_known: reasons.append('MAX_LOSS_UNKNOWN')
    return EligibilityResult(not reasons, tuple(reasons))
