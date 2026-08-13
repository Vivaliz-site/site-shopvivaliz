from __future__ import annotations

import importlib.util
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = ROOT / "daemon-token-renewer.py"
SPEC = importlib.util.spec_from_file_location("olist_interval_cap", MODULE_PATH)
renewer = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(renewer)


def test_legacy_two_hour_unit_is_capped_to_five_minutes() -> None:
    check_interval, retry_interval = renewer.effective_loop_intervals(7200, 900)
    assert check_interval == 300
    assert retry_interval == 300


def test_requested_shorter_intervals_are_preserved() -> None:
    check_interval, retry_interval = renewer.effective_loop_intervals(120, 180)
    assert check_interval == 120
    assert retry_interval == 180


def test_intervals_never_drop_below_one_minute() -> None:
    check_interval, retry_interval = renewer.effective_loop_intervals(1, -10)
    assert check_interval == 60
    assert retry_interval == 60


def test_production_unit_declares_five_minute_check_and_thirty_minute_margin() -> None:
    unit = (ROOT / "deploy/systemd/shopvivaliz-token-renewer.service").read_text(encoding="utf-8")
    assert "--interval 300" in unit
    assert "--retry-interval 300" in unit
    assert "--refresh-margin 1800" in unit
