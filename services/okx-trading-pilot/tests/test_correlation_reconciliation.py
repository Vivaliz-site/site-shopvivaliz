from decimal import Decimal
from okx_pilot.correlation import cluster_id
from okx_pilot.reconciliation import reconcile_positions

def test_correlation_groups_same_underlying_and_direction():
    assert cluster_id('BTC-USDT-SWAP','long') == cluster_id('BTC-USDT-240628','long')
    assert cluster_id('BTC-USDT-SWAP','long') != cluster_id('BTC-USDT-SWAP','short')

def test_reconciliation_exchange_wins_and_blocks_on_divergence():
    local={'BTC-USDT-SWAP':Decimal('1')}
    exchange={'BTC-USDT-SWAP':Decimal('2')}
    r=reconcile_positions(local,exchange)
    assert not r.consistent
    assert not r.new_exposure_allowed
    assert r.positions==exchange

def test_reconciliation_allows_when_equal():
    p={'BTC-USDT-SWAP':Decimal('1')}
    r=reconcile_positions(p,p)
    assert r.consistent and r.new_exposure_allowed
