from dataclasses import dataclass
from decimal import Decimal

@dataclass(frozen=True)
class ReconciliationResult:
    positions: dict[str, Decimal]
    consistent: bool
    new_exposure_allowed: bool

def reconcile_positions(local: dict[str, Decimal], exchange: dict[str, Decimal]) -> ReconciliationResult:
    consistent = local == exchange
    return ReconciliationResult(dict(exchange), consistent, consistent)
