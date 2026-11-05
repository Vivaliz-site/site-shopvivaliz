import json
from datetime import datetime, timedelta, timezone
from pathlib import Path
from scripts.provider_watchdog import evaluate, check_and_alert

def sample(path, now, **extra):
    data = {"updated_at": now.isoformat(), "mode": "PAPER",
            "real_orders_enabled": False, "ai_20_layers_active": True,
            "decision_provider": "CODEX_20_LAYER", "cycle": 10,
            "closed_trades": 5}
    data.update(extra)
    path.write_text(json.dumps(data))

def test_green_only_after_valid_ai(tmp_path):
    now = datetime.now(timezone.utc)
    p = tmp_path / "status.json"
    sample(p, now)
    assert evaluate(p, now)[0] == "HEALTHY"
    sample(p, now, ai_20_layers_active=False,
           decision_provider_last_error="decision_bridge:paid_provider_disabled")
    state, reason, _ = evaluate(p, now)
    assert state == "DEGRADED" and "paid_provider_disabled" in reason
    sample(p, now - timedelta(minutes=20))
    assert evaluate(p, now)[1] == "status_stale"

def test_live_mode_is_critical(tmp_path):
    now = datetime.now(timezone.utc)
    p = tmp_path / "status.json"
    sample(p, now, real_orders_enabled=True)
    assert evaluate(p, now)[:2] == ("CRITICAL", "paper_only_boundary_invalid")

def test_transition_deduplicates_and_sends_recovery(tmp_path):
    now = datetime.now(timezone.utc)
    p = tmp_path / "status.json"
    state = tmp_path / "state.json"
    seen = []
    send = lambda *args: seen.append(args[0])
    sample(p, now, ai_20_layers_active=False)
    assert check_and_alert(p, state, now, notify_fn=send) == "DEGRADED"
    assert check_and_alert(p, state, now + timedelta(minutes=1), notify_fn=send) == "DEGRADED"
    assert seen == ["DEGRADED"]
    assert check_and_alert(p, state, now + timedelta(hours=6, seconds=1), notify_fn=send) == "DEGRADED"
    assert seen == ["DEGRADED", "DEGRADED"]
    sample(p, now + timedelta(hours=6, minutes=2))
    assert check_and_alert(p, state, now + timedelta(hours=6, minutes=2), notify_fn=send) == "HEALTHY"
    assert seen[-1] == "HEALTHY"
    assert json.loads(state.read_text())["health"] == "HEALTHY"

def test_dry_run_never_notifies(tmp_path):
    now = datetime.now(timezone.utc)
    p = tmp_path / "status.json"
    sample(p, now, ai_20_layers_active=False)
    assert check_and_alert(p, tmp_path / "state.json", now, dry_run=True,
                           notify_fn=lambda *args: (_ for _ in ()).throw(ValueError("called"))) == "DEGRADED"
    assert not (tmp_path / "state.json").exists()


def test_incident_never_leaks_provider_error_text(tmp_path):
    now = datetime.now(timezone.utc)
    p = tmp_path / "status.json"
    sample(p, now, ai_20_layers_active=False,
           decision_provider_last_error="Bearer sensitive-secret-in-error")
    state, reason, _ = evaluate(p, now)
    assert state == "DEGRADED"
    assert "sensitive" not in reason
    assert "redacted" in reason
