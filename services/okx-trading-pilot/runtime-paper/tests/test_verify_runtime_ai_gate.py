import importlib.util
import json
from datetime import datetime, timezone
from pathlib import Path

import pytest


SCRIPT = Path(__file__).parents[1] / "scripts" / "verify_runtime.py"


def load_verify_runtime():
    spec = importlib.util.spec_from_file_location("okx_verify_runtime", SCRIPT)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


def valid_status(release: Path):
    return {
        "updated_at": datetime.now(timezone.utc).isoformat(),
        "mode": "PAPER",
        "real_orders_enabled": False,
        "decision_provider": "CODEX_20_LAYER",
        "decision_model": "gpt-5.6-terra",
        "decision_effort": "medium",
        "decision_fallback_provider": "CHATGPT_BROWSER_20_LAYER",
        "decision_fallback_model": "gpt-5.6-sol",
        "decision_fallback_effort": "xhigh",
        "decision_login_only": True,
        "platform_api_fallback": False,
        "heuristic_fallback": False,
        "ai_20_layers_configured": True,
        "ai_20_layers_active": True,
        "run_id": "runtime-1",
        "errors": [],
        "entries_blocked": False,
        "markets": {"SPOT": 1, "SWAP": 1, "FUTURES": 1},
        "release": str(release.resolve()),
    }


def run_validation(tmp_path, monkeypatch, status):
    module = load_verify_runtime()
    release = tmp_path / "release"
    release.mkdir(exist_ok=True)
    (release / "SOURCE_COMMIT").write_text("a" * 40, encoding="utf-8")
    state = tmp_path / "state.json"
    state.write_text(json.dumps({"run_id": "runtime-1", "starting_equity": "100"}), encoding="utf-8")
    status_path = tmp_path / "status.json"
    status["release"] = str(release.resolve())
    status_path.write_text(json.dumps(status), encoding="utf-8")

    monkeypatch.setattr(module, "ROOT", release)
    monkeypatch.setattr(module, "STATE", state)
    monkeypatch.setattr(module, "STATUS", status_path)
    monkeypatch.setattr(module.subprocess, "run", lambda *args, **kwargs: None)
    monkeypatch.setattr(
        module.subprocess,
        "check_output",
        lambda *args, **kwargs: "ExecStart=python scripts/run.py --cycles 0 --state state --status status",
    )
    module.main()


def test_verify_runtime_rejects_configured_but_inactive_ai(tmp_path, monkeypatch):
    release = tmp_path / "placeholder"
    status = valid_status(release)
    status["ai_20_layers_active"] = False
    with pytest.raises(AssertionError, match="ai_20_layers_not_active"):
        run_validation(tmp_path, monkeypatch, status)


def test_verify_runtime_requires_exact_terra_model(tmp_path, monkeypatch):
    release = tmp_path / "placeholder"
    status = valid_status(release)
    status["decision_model"] = "gpt-5.6-sol"
    with pytest.raises(AssertionError, match="wrong_decision_model"):
        run_validation(tmp_path, monkeypatch, status)


def test_verify_runtime_requires_exact_sol_xhigh_fallback(tmp_path, monkeypatch):
    release = tmp_path / "placeholder"
    status = valid_status(release)
    status["decision_fallback_model"] = "gpt-5.6-terra"
    with pytest.raises(AssertionError, match="wrong_fallback_model"):
        run_validation(tmp_path, monkeypatch, status)
    status = valid_status(release)
    status["decision_fallback_effort"] = "high"
    with pytest.raises(AssertionError, match="wrong_fallback_effort"):
        run_validation(tmp_path, monkeypatch, status)


def test_verify_runtime_rejects_platform_or_heuristic_fallback(tmp_path, monkeypatch):
    release = tmp_path / "placeholder"
    for key, expected in (("platform_api_fallback", True), ("heuristic_fallback", True)):
        status = valid_status(release)
        status[key] = expected
        with pytest.raises(AssertionError):
            run_validation(tmp_path, monkeypatch, status)
