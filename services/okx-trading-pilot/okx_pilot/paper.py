from dataclasses import dataclass
from decimal import Decimal
from .domain import TradeDecision
from .risk import RiskVerdict

@dataclass(frozen=True)
class PaperFill:
    decision_id: str
    instrument_id: str
    quantity: Decimal
    fill_price: Decimal
    fee: Decimal
    slippage_bps: Decimal
    risk: Decimal

class PaperBroker:
    """Simulation-only broker. No exchange write adapter is accepted or stored."""
    def __init__(self, fee_bps=Decimal('5'), slippage_bps=Decimal('2')):
        self.fee_bps=Decimal(fee_bps)
        self.slippage_bps=Decimal(slippage_bps)
        self.fills=[]

    def execute(self, decision: TradeDecision, verdict: RiskVerdict) -> PaperFill:
        if not verdict.approved:
            raise PermissionError(','.join(verdict.reasons))
        distance=abs(decision.entry-decision.stop)
        if distance <= 0:
            raise ValueError('invalid stop distance')
        quantity=(verdict.approved_risk/distance).quantize(Decimal('0.00000001'))
        side=Decimal('1') if decision.direction.upper()=='LONG' else Decimal('-1')
        fill_price=decision.entry*(Decimal('1')+side*self.slippage_bps/Decimal('10000'))
        fee=abs(fill_price*quantity)*self.fee_bps/Decimal('10000')
        fill=PaperFill(decision.decision_id,decision.instrument_id,quantity,fill_price,fee,self.slippage_bps,verdict.approved_risk)
        self.fills.append(fill)
        return fill

    def mark_pnl(self, fill: PaperFill, direction: str, mark: Decimal) -> Decimal:
        gross=(Decimal(mark)-fill.fill_price)*fill.quantity
        if direction.upper()=='SHORT':
            gross=-gross
        return gross-fill.fee
