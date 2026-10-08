from __future__ import annotations
from dataclasses import dataclass
from decimal import Decimal, ROUND_HALF_UP


@dataclass(frozen=True)
class PerformanceMetrics:
    trades: int
    wins: int
    losses: int
    win_rate: Decimal
    profit_factor: Decimal
    expectancy: Decimal
    net_pnl: Decimal
    max_drawdown: Decimal


class PerformanceTracker:
    def __init__(self, starting_equity: Decimal):
        self.starting_equity = starting_equity
        self.results: list[Decimal] = []

    def record(self, pnl: Decimal): self.results.append(pnl)

    def snapshot(self) -> PerformanceMetrics:
        n=len(self.results); wins=[x for x in self.results if x>0]; losses=[x for x in self.results if x<0]
        gross_profit=sum(wins, Decimal("0")); gross_loss=-sum(losses, Decimal("0"))
        win_rate=(Decimal(len(wins))*Decimal("100")/Decimal(n)).quantize(Decimal("0.0001"), rounding=ROUND_HALF_UP) if n else Decimal("0")
        pf=(gross_profit/gross_loss) if gross_loss else (Decimal("0") if not gross_profit else Decimal("999999"))
        expectancy=(sum(self.results, Decimal("0"))/Decimal(n)) if n else Decimal("0")
        equity=self.starting_equity; peak=equity; max_dd=Decimal("0")
        for r in self.results:
            equity += r; peak=max(peak,equity); max_dd=max(max_dd, peak-equity)
        return PerformanceMetrics(n,len(wins),len(losses),win_rate,pf,expectancy,sum(self.results,Decimal("0")),max_dd)
