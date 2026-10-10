from decimal import Decimal
from pathlib import Path
import tempfile
import pytest

from okx_pilot.domain import Mode, DecisionKind, Direction, InstrumentType, DecisionIntent, PilotLimits
from okx_pilot.config import RuntimeConfig


def test_paper_only_modes_and_hard_limits():
    assert set(Mode) == {Mode.SHADOW, Mode.PAPER}
    limits = PilotLimits()
    assert limits.reference_capital == Decimal("100")
    assert limits.max_risk_per_trade == Decimal("10")
    assert limits.max_total_open_risk == Decimal("30")
    assert limits.max_correlated_risk == Decimal("20")
    assert limits.daily_loss_stop == Decimal("15")
    assert limits.cumulative_loss_stop == Decimal("30")
    assert limits.max_leverage == Decimal("20")
    assert limits.min_confidence == Decimal("70")
    assert limits.min_rr == Decimal("1.5")


def test_decision_rejects_float_financial_values():
    with pytest.raises(TypeError):
        DecisionIntent(
            decision_id="d1", decision=DecisionKind.TRADE, instrument="BTC-USDT",
            instrument_type=InstrumentType.SPOT, direction=Direction.LONG,
            entry_low=100.0, entry_high=Decimal("101"), stop=Decimal("95"),
            targets=(Decimal("110"),), confidence=Decimal("80"), expected_rr=Decimal("2"),
            suggested_risk=Decimal("5"), suggested_leverage=Decimal("1"),
            created_at=None, expires_at=None, layers=tuple(), thesis="x", invalidation="y"
        )


def test_runtime_config_defaults_to_paper_and_has_no_live_or_withdraw_fields():
    with tempfile.TemporaryDirectory() as td:
        p = Path(td) / "pilot.toml"
        p.write_text('[runtime]\nmode="PAPER"\n', encoding="utf-8")
        cfg = RuntimeConfig.load(p)
    assert cfg.mode is Mode.PAPER
    assert not hasattr(cfg, "withdraw")
    assert not hasattr(cfg, "live_marker")
