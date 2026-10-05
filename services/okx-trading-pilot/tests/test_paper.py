from decimal import Decimal
from okx_pilot.paper import simulate_round_trip

def test_costs_can_turn_gross_profit_negative():
    r=simulate_round_trip(entry=Decimal('100'),exit=Decimal('100.2'),qty=Decimal('1'),fee_bps=Decimal('10'),slippage_bps=Decimal('5'),funding=Decimal('0.05'))
    assert r.gross_pnl==Decimal('0.2')
    assert r.net_pnl < 0
