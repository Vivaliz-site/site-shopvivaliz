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


def test_legacy_service_intervals_cannot_weaken_proactive_refresh() -> None:
    assert renewer.MAX_CHECK_INTERVAL == 300
    assert renewer.MAX_RETRY_INTERVAL == 300
    assert renewer.effective_loop_intervals(7200, 900) == (300, 300)
    assert renewer.effective_loop_intervals(300, 300) == (300, 300)
    assert renewer.effective_loop_intervals(30, 30) == (60, 60)


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


def test_daemon_owns_cross_process_rotation_lock() -> None:
    source = MODULE_PATH.read_text(encoding="utf-8")
    assert "def oauth_rotation_lock()" in source
    assert "olist-token-rotation.lock" in source
    assert "with oauth_rotation_lock():" in source
    assert "update_token_store(access_token, refresh_token, result)" in source


def test_php_runtime_is_consumer_only_and_retries_only_after_store_rotation() -> None:
    source = (ROOT / "includes/marketplace/TinyV3Runtime.php").read_text(encoding="utf-8")
    endpoint = "accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token"
    assert "SV_MARKET_TINY_REFRESH_MARGIN_SECONDS = 1800" in source
    assert "/shared/private/olist-tokens.json" in source
    assert "sv_market_tiny_ensure_access_token" in source
    assert "expires_at_epoch" in source
    assert endpoint not in source
    assert "sv_market_tiny_refresh_access_token" not in source
    assert "if ((int)$response['status'] !== 401)" in source
    assert "!hash_equals($before, $after)" in source


def test_business_consumers_cannot_rotate_refresh_tokens() -> None:
    endpoint = "accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token"
    for relative in (
        "includes/tiny-order-push.php",
        "olist/sync-products.php",
    ):
        source = (ROOT / relative).read_text(encoding="utf-8")
        assert endpoint not in source, relative
    assert "svtop_olist_token_store" in (ROOT / "includes/tiny-order-push.php").read_text(encoding="utf-8")
    assert "svs_read_token_store" in (ROOT / "olist/sync-products.php").read_text(encoding="utf-8")


def test_callback_never_returns_token_material_and_shares_rotation_lock() -> None:
    source = (ROOT / "olist/callback.php").read_text(encoding="utf-8")
    assert "substr($accessToken" not in source
    assert "'access_token' =>" not in source
    assert "'refresh_token' =>" not in source
    assert "'token_salvo' => true" in source
    assert "'refresh_preventivo' => true" in source
    assert "/shared/private/olist-tokens.json" in source
    assert "expires_at_epoch" in source
    assert "realpath($envFile)" in source
    assert "is_writable($envTarget)" in source
    assert "'env_sincronizado' => $envSynced" in source
    assert "olist-token-rotation.lock" in source
    assert "flock($rotationLock, LOCK_EX)" in source


def test_legacy_oauth_executors_are_removed() -> None:
    for relative in (
        "api/olist/login.php",
        "api/olist/callback.php",
        "api/olist/refresh-token.php",
        "scripts/get-tiny-oauth-token.py",
        "scripts/playwright-get-token.py",
        ".github/workflows/one-time-olist-refresh.yml",
        ".github/workflows/recover-olist-runtime.yml",
        ".github/workflows/recover-olist-oauth-from-backups.yml",
    ):
        assert not (ROOT / relative).exists(), relative
