from decimal import Decimal
import pytest
from okx_pilot.domain import Mode, DecisionKind, InstrumentType, LifecycleState
from okx_pilot.config import PilotLimits, PilotConfig

def test_modes_are_exact():
    assert [m.value for m in Mode] == ['SHADOW','PAPER','LIVE_PILOT']

def test_domain_enums_are_expected():
    assert DecisionKind.TRADE.value=='TRADE'
    assert InstrumentType.SWAP.value=='swap'
    assert LifecycleState.RECONCILED.value=='RECONCILED'

def test_default_limits_match_approved_spec():
    limits=PilotLimits()
    assert limits.pilot_capital==Decimal('100')
    assert limits.max_risk_per_trade==Decimal('10')
    assert limits.max_open_risk==Decimal('30')
    assert limits.max_correlated_risk==Decimal('20')
    assert limits.daily_loss_stop==Decimal('15')
    assert limits.cumulative_kill_switch==Decimal('30')
    assert limits.max_leverage==Decimal('20')
    assert limits.min_confidence==70
    assert limits.min_rr==Decimal('1.5')
    assert limits.timezone=='America/Sao_Paulo'

def test_config_rejects_withdrawal_capability():
    with pytest.raises(ValueError):
        PilotConfig(limits=PilotLimits(),withdrawal_enabled=True)

def test_config_rejects_invalid_limits():
    with pytest.raises(ValueError):
        PilotLimits(max_risk_per_trade=Decimal('-1'))
