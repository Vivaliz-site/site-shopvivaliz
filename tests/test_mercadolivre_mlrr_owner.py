"""Contract tests for ShopVivaLiz as a consumer-only Mercado Livre credential client.

Task 8 of the MLRR OAuth ownership plan: when ``ML_TOKEN_OWNER=mlrr`` ShopVivaLiz
may only consume the access-only snapshot published by MLRR. No ShopVivaLiz code
path may refresh or persist Mercado Livre refresh credentials.

Every PHP assertion runs in an isolated subprocess because ``ml_env()`` and
``ml_load_runtime_secrets()`` keep static caches that would otherwise leak
between cases.
"""

from __future__ import annotations

import json
import os
import subprocess
import uuid
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
CLIENT = ROOT / "api" / "ml" / "client.php"
HEALTH = ROOT / "includes" / "integration-health.php"
LOGIN = ROOT / "api" / "ml" / "login.php"
CALLBACK = ROOT / "api" / "ml" / "callback.php"
DAEMON = ROOT / "daemon-mercadolivre-token-renewer.php"

# Inherited Mercado Livre configuration must never bleed into a case.
ML_ENV_KEYS = (
    "ML_TOKEN_OWNER",
    "ML_ACCESS_SNAPSHOT_FILE",
    "ML_TOKEN_FILE",
    "ML_CLIENT_ID",
    "ML_CLIENT_SECRET",
    "ML_REDIRECT_URI",
    "ML_ACCESS_TOKEN",
    "ML_REFRESH_TOKEN",
    "MERCADO_LIVRE_ACCESS_TOKEN",
    "MERCADO_LIVRE_REFRESH_TOKEN",
)

PREAMBLE = """<?php
declare(strict_types=1);
require %s;

function ml_test_emit(callable $fn): void
{
    try {
        echo json_encode(['ok' => true, 'value' => $fn()], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        echo json_encode([
            'ok' => false,
            'class' => get_class($e),
            'error' => $e->getMessage(),
        ], JSON_UNESCAPED_SLASHES);
    }
}
"""


def run_php(tmp_path: Path, include: Path, body: str, env: dict[str, str]) -> dict:
    """Execute ``body`` in a fresh PHP process and decode its single JSON line."""
    script = tmp_path / f"driver-{uuid.uuid4().hex}.php"
    script.write_text(PREAMBLE % json.dumps(str(include)) + body, encoding="utf-8")

    process_env = {k: v for k, v in os.environ.items() if k not in ML_ENV_KEYS}
    process_env.update(env)
    completed = subprocess.run(
        ["php", str(script)],
        capture_output=True,
        text=True,
        env=process_env,
        timeout=60,
        check=False,
    )
    assert completed.returncode == 0, completed.stderr
    return json.loads(completed.stdout.strip())


def write_snapshot(path: Path, access_token: str, expires_at_ms: int) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps(
            {
                "schema_version": 1,
                "access_token": access_token,
                "token_type": "Bearer",
                "provider_user_id": 112962856,
                "site_id": "MLB",
                "scopes": ["offline_access", "read"],
                "expires_at": "2099-01-01T00:00:00+00:00",
                "expires_at_ms": expires_at_ms,
                "published_at": "2026-09-16T02:00:00+00:00",
            }
        ),
        encoding="utf-8",
    )


def write_legacy_store(path: Path, access_token: str, refresh_token: str, expires_at_ms: int) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps(
            {
                "access_token": access_token,
                "refresh_token": refresh_token,
                "user_id": 112962856,
                "expires_at_ms": expires_at_ms,
            }
        ),
        encoding="utf-8",
    )


FUTURE_MS = 4102444800000  # 2100-01-01
PAST_MS = 1577836800000  # 2020-01-01


# --------------------------------------------------------------------------
# Legacy mode stays byte-for-byte compatible until the production cutover.
# --------------------------------------------------------------------------


def test_default_owner_is_legacy_and_reads_the_existing_token_store(tmp_path: Path) -> None:
    store = tmp_path / "ml-tokens.json"
    write_legacy_store(store, "legacy-access", "legacy-refresh", FUTURE_MS)

    result = run_php(
        tmp_path,
        CLIENT,
        "ml_test_emit(static fn() => ['owner' => ml_token_owner(), 'tokens' => ml_refresh_if_needed()]);",
        {"ML_TOKEN_FILE": str(store)},
    )

    assert result["ok"] is True, result
    assert result["value"]["owner"] == "legacy"
    assert result["value"]["tokens"]["access_token"] == "legacy-access"
    assert result["value"]["tokens"]["refresh_token"] == "legacy-refresh"


def test_explicit_legacy_owner_ignores_the_mlrr_snapshot(tmp_path: Path) -> None:
    store = tmp_path / "ml-tokens.json"
    snapshot = tmp_path / "ml-access-token.json"
    write_legacy_store(store, "legacy-access", "legacy-refresh", FUTURE_MS)
    write_snapshot(snapshot, "snapshot-access", FUTURE_MS)

    result = run_php(
        tmp_path,
        CLIENT,
        "ml_test_emit(static fn() => ml_refresh_if_needed());",
        {
            "ML_TOKEN_OWNER": "legacy",
            "ML_TOKEN_FILE": str(store),
            "ML_ACCESS_SNAPSHOT_FILE": str(snapshot),
        },
    )

    assert result["ok"] is True, result
    assert result["value"]["access_token"] == "legacy-access"


def test_legacy_owner_still_persists_tokens(tmp_path: Path) -> None:
    store = tmp_path / "ml-tokens.json"
    write_legacy_store(store, "legacy-access", "legacy-refresh", FUTURE_MS)

    result = run_php(
        tmp_path,
        CLIENT,
        "ml_test_emit(static fn() => ml_save_tokens(['access_token' => 'rotated', 'expires_in' => 21600]));",
        {"ML_TOKEN_FILE": str(store)},
    )

    assert result["ok"] is True, result
    assert json.loads(store.read_text(encoding="utf-8"))["access_token"] == "rotated"


# --------------------------------------------------------------------------
# MLRR mode: access-only snapshot, no refresh, no persistence.
# --------------------------------------------------------------------------


def test_mlrr_owner_reads_a_fresh_access_only_snapshot(tmp_path: Path) -> None:
    snapshot = tmp_path / "ml-access-token.json"
    write_snapshot(snapshot, "snapshot-access", FUTURE_MS)

    result = run_php(
        tmp_path,
        CLIENT,
        "ml_test_emit(static fn() => ['owner' => ml_token_owner(), 'tokens' => ml_refresh_if_needed()]);",
        {"ML_TOKEN_OWNER": "MLRR", "ML_ACCESS_SNAPSHOT_FILE": str(snapshot)},
    )

    assert result["ok"] is True, result
    assert result["value"]["owner"] == "mlrr"
    tokens = result["value"]["tokens"]
    assert tokens["access_token"] == "snapshot-access"
    assert tokens["expires_at_ms"] == FUTURE_MS
    assert tokens["user_id"] == 112962856
    assert "refresh_token" not in tokens


def test_mlrr_owner_never_reads_the_legacy_token_store(tmp_path: Path) -> None:
    store = tmp_path / "ml-tokens.json"
    snapshot = tmp_path / "ml-access-token.json"
    write_legacy_store(store, "legacy-access", "legacy-refresh", FUTURE_MS)
    write_snapshot(snapshot, "snapshot-access", FUTURE_MS)

    result = run_php(
        tmp_path,
        CLIENT,
        "ml_test_emit(static fn() => ml_read_tokens());",
        {
            "ML_TOKEN_OWNER": "mlrr",
            "ML_TOKEN_FILE": str(store),
            "ML_ACCESS_SNAPSHOT_FILE": str(snapshot),
        },
    )

    assert result["ok"] is True, result
    assert result["value"]["access_token"] == "snapshot-access"
    assert "refresh_token" not in result["value"]


def test_mlrr_owner_rejects_an_expired_snapshot_without_any_provider_request(tmp_path: Path) -> None:
    store = tmp_path / "ml-tokens.json"
    snapshot = tmp_path / "ml-access-token.json"
    write_legacy_store(store, "legacy-access", "legacy-refresh", FUTURE_MS)
    write_snapshot(snapshot, "snapshot-access", PAST_MS)
    store_before = store.read_bytes()
    snapshot_before = snapshot.read_bytes()

    result = run_php(
        tmp_path,
        CLIENT,
        "ml_test_emit(static fn() => ml_refresh_if_needed());",
        {
            "ML_TOKEN_OWNER": "mlrr",
            "ML_TOKEN_FILE": str(store),
            "ML_ACCESS_SNAPSHOT_FILE": str(snapshot),
            "ML_CLIENT_ID": "4695185185661070",
            "ML_CLIENT_SECRET": "must-never-be-used",
        },
    )

    assert result["ok"] is False, result
    assert result["class"] == "RuntimeException"
    assert "MLRR" in result["error"]
    assert "must-never-be-used" not in result["error"]
    assert store.read_bytes() == store_before
    assert snapshot.read_bytes() == snapshot_before


def test_mlrr_owner_rejects_a_missing_snapshot(tmp_path: Path) -> None:
    snapshot = tmp_path / "absent" / "ml-access-token.json"

    result = run_php(
        tmp_path,
        CLIENT,
        "ml_test_emit(static fn() => ml_refresh_if_needed());",
        {"ML_TOKEN_OWNER": "mlrr", "ML_ACCESS_SNAPSHOT_FILE": str(snapshot)},
    )

    assert result["ok"] is False, result
    assert result["class"] == "RuntimeException"
    assert "MLRR" in result["error"]
    assert not snapshot.exists()


def test_mlrr_owner_rejects_token_persistence(tmp_path: Path) -> None:
    store = tmp_path / "ml-tokens.json"
    snapshot = tmp_path / "ml-access-token.json"
    write_legacy_store(store, "legacy-access", "legacy-refresh", FUTURE_MS)
    write_snapshot(snapshot, "snapshot-access", FUTURE_MS)
    store_before = store.read_bytes()
    snapshot_before = snapshot.read_bytes()

    result = run_php(
        tmp_path,
        CLIENT,
        "ml_test_emit(static fn() => ml_save_tokens(['access_token' => 'x', 'refresh_token' => 'y']));",
        {
            "ML_TOKEN_OWNER": "mlrr",
            "ML_TOKEN_FILE": str(store),
            "ML_ACCESS_SNAPSHOT_FILE": str(snapshot),
        },
    )

    assert result["ok"] is False, result
    assert result["error"] == "Mercado Livre credentials are owned by MLRR."
    assert store.read_bytes() == store_before
    assert snapshot.read_bytes() == snapshot_before


def test_default_snapshot_path_is_the_shared_private_store(tmp_path: Path) -> None:
    result = run_php(
        tmp_path,
        CLIENT,
        "ml_test_emit(static fn() => ml_access_snapshot_path());",
        {"ML_TOKEN_OWNER": "mlrr"},
    )

    assert result["ok"] is True, result
    assert result["value"] == "/home/ubuntu/shopvivaliz-deploy/shared/storage/private/ml-access-token.json"


def test_client_refresh_grant_is_unreachable_when_owner_is_mlrr() -> None:
    source = CLIENT.read_text(encoding="utf-8")
    grant = source.index("'grant_type'    => 'refresh_token'")
    guard = source.index("ml_token_owner() === 'mlrr'")
    assert guard < grant, "ownership guard must precede the refresh grant"


# --------------------------------------------------------------------------
# Health, login, callback and daemon.
# --------------------------------------------------------------------------


def test_health_in_mlrr_mode_delegates_refresh_and_never_fixes(tmp_path: Path) -> None:
    snapshot = tmp_path / "absent" / "ml-access-token.json"

    result = run_php(
        tmp_path,
        HEALTH,
        "ml_test_emit(static fn() => svih_ml(true));",
        {
            "ML_TOKEN_OWNER": "mlrr",
            "ML_ACCESS_SNAPSHOT_FILE": str(snapshot),
            # Legacy credentials remain configured but must be ignored entirely.
            "ML_ACCESS_TOKEN": "legacy-env-access",
            "ML_REFRESH_TOKEN": "legacy-env-refresh",
            "ML_CLIENT_ID": "4695185185661070",
            "ML_CLIENT_SECRET": "must-never-be-used",
        },
    )

    assert result["ok"] is True, result
    item = result["value"]
    assert item["token_owner"] == "mlrr"
    assert item["status"] == "failed"
    assert item["fixes"] == []
    assert item["tokens"]["refresh_token"]["configured"] is False
    assert item["tokens"]["refresh_token"]["source"] == "mlrr-delegated"
    assert item["tokens"]["access_token"]["configured"] is False
    assert item["tokens"]["access_token"]["source"] == "mlrr-snapshot"
    assert json.dumps(item).find("legacy-env-refresh") == -1


def test_health_in_legacy_mode_keeps_reporting_the_legacy_owner(tmp_path: Path) -> None:
    result = run_php(
        tmp_path,
        HEALTH,
        "ml_test_emit(static fn() => svih_ml(false));",
        {"ML_TOKEN_OWNER": "legacy"},
    )

    assert result["ok"] is True, result
    item = result["value"]
    assert item["token_owner"] == "legacy"
    assert item["status"] == "failed"
    assert item["tokens"]["refresh_token"]["source"] != "mlrr-delegated"


def test_health_never_builds_a_refresh_closure_in_mlrr_mode() -> None:
    source = HEALTH.read_text(encoding="utf-8")
    start = source.index("function svih_ml(")
    body = source[start : source.index("\nfunction svih_melhor_envio(", start)]
    guard = body.index("ml_token_owner() === 'mlrr'")
    closure = body.index("$refreshMl = static function")
    assert guard < closure, "MLRR branch must return before the refresh closure exists"


@pytest.mark.parametrize("entrypoint", [LOGIN, CALLBACK])
def test_login_and_callback_refuse_mlrr_ownership(entrypoint: Path) -> None:
    source = entrypoint.read_text(encoding="utf-8")
    require_client = source.index("require_once __DIR__ . '/client.php';")
    guard = source.index("if (ml_token_owner() === 'mlrr') {")
    assert require_client < guard

    assert "http_response_code(409);" in source[guard:]
    assert "Mercado Livre OAuth is managed by the MLRR service." in source[guard:]

    # The guard must precede every credential-authority side effect.
    for marker in ("ml_create_pkce(", "ml_http_post(", "ml_save_tokens(", "setcookie("):
        position = source.find(marker, require_client)
        if position >= 0:
            assert guard < position, f"{marker} must not run before the ownership guard"


def test_daemon_health_fix_flag_follows_ownership() -> None:
    source = DAEMON.read_text(encoding="utf-8")
    assert "svih_ml(ml_token_owner() !== 'mlrr')" in source
    assert "svih_ml(true)" not in source
    assert "svih_check_all(true)" not in source
