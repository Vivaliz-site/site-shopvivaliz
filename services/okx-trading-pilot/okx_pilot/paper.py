from dataclasses import dataclass
from decimal import Decimal

@dataclass(frozen=True)
class PaperResult:
    gross_pnl:Decimal
    costs:Decimal
    net_pnl:Decimal

def simulate_round_trip(*,entry:Decimal,exit:Decimal,qty:Decimal,fee_bps:Decimal,slippage_bps:Decimal,funding:Decimal)->PaperResult:
    gross=(exit-entry)*qty
    notional=(entry+exit)*qty
    fees=notional*fee_bps/Decimal('10000')
    slip=notional*slippage_bps/Decimal('10000')
    costs=fees+slip+funding
    return PaperResult(gross,costs,gross-costs)
