from __future__ import annotations

import importlib.util
import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = ROOT / "daemon-token-renewer.py"
SPEC = importlib.util.spec_from_file_location("olist_proactive_renewer", MODULE_PATH)
renewer = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(renewer)


def test_systemd_checks_every_five_minutes_with_thirty_minute_margin() -> None:
    service = (ROOT / "deploy/systemd/shopvivaliz-token-renewer.service").read_text(encoding="utf-8")
    assert "--interval 300" in service
    assert "--retry-interval 300" in service
    assert "--refresh-margin 1800" in service
    assert "/shared/private/olist-tokens.json" in service


def test_rotating_token_store_has_priority_over_stale_env(tmp_path: Path, monkeypatch) -> None:
    env_file = tmp_path / ".env"
    token_file = tmp_path / "private" / "olist-tokens.json"
    env_file.write_text(
        "OLIST_CLIENT_ID=current-client\n"
        "OLIST_CLIENT_SECRET=current-secret\n"
        "OLIST_ACCESS_TOKEN=stale-access\n"
        "OLIST_REFRESH_TOKEN=stale-refresh\n",
        encoding="utf-8",
    )
    token_file.parent.mkdir(parents=True)
    token_file.write_text(
        json.dumps(
            {
                "OLIST_ACCESS_TOKEN": "rotated-access",
                "OLIST_REFRESH_TOKEN": "rotated-refresh",
                "expires_at_epoch": 9999999999,
            }
        ),
        encoding="utf-8",
    )
    monkeypatch.setattr(renewer, "ENV_PATH", env_file)
    monkeypatch.setattr(renewer, "TOKEN_STORE_PATH", token_file)

    config = renewer.get_config()

    assert config["OLIST_CLIENT_ID"] == "current-client"
    assert config["OLIST_CLIENT_SECRET"] == "current-secret"
    assert config["OLIST_ACCESS_TOKEN"] == "rotated-access"
    assert config["OLIST_REFRESH_TOKEN"] == "rotated-refresh"


def test_refresh_is_due_before_expiration(monkeypatch) -> None:
    config = {"OLIST_ACCESS_TOKEN": "opaque-token"}
    monkeypatch.setattr(renewer, "token_expiry_epoch", lambda _config: 10_000)

    assert renewer.token_requires_refresh(config, refresh_margin=1800, now=8200) is True
    assert renewer.token_requires_refresh(config, refresh_margin=1800, now=8199) is False


def test_refresh_persists_provider_expiry_metadata(tmp_path: Path, monkeypatch) -> None:
    env_file = tmp_path / ".env"
    token_file = tmp_path / "private" / "olist-tokens.json"
    env_file.write_text("UNCHANGED=value\n", encoding="utf-8")
    monkeypatch.setattr(renewer, "ENV_PATH", env_file)
    monkeypatch.setattr(renewer, "TOKEN_STORE_PATH", token_file)
    monkeypatch.setattr(renewer.time, "time", lambda: 1000)

    renewer.update_token_store(
        "new-access",
        "new-refresh",
        {"expires_in": 14400},
    )

    payload = json.loads(token_file.read_text(encoding="utf-8"))
    assert payload["OLIST_ACCESS_TOKEN"] == "new-access"
    assert payload["OLIST_REFRESH_TOKEN"] == "new-refresh"
    assert payload["expires_in"] == 14400
    assert payload["expires_at_epoch"] == 15400


def test_php_runtime_refreshes_proactively_and_keeps_401_only_as_fallback() -> None:
    source = (ROOT / "includes/marketplace/TinyV3Runtime.php").read_text(encoding="utf-8")
    assert "SV_MARKET_TINY_REFRESH_MARGIN_SECONDS = 1800" in source
    assert "/shared/private/olist-tokens.json" in source
    assert "sv_market_tiny_ensure_access_token" in source
    assert "expires_at_epoch" in source
    assert "if ((int)$response['status'] !== 401)" in source
