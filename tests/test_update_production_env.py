import importlib.util
import stat
from pathlib import Path

import pytest

SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "update-production-env.py"
SPEC = importlib.util.spec_from_file_location("update_production_env", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)
merge_env = MODULE.merge_env


def test_merge_env_is_atomic_and_preserves_managed_tokens(tmp_path: Path) -> None:
    env_file = tmp_path / ".env"
    env_file.write_text(
        "OLIST_ACCESS_TOKEN=managed-by-renewer\n"
        "OLIST_CLIENT_SECRET=managed-by-oauth\n"
        "ML_CLIENT_ID=old\n",
        encoding="utf-8",
    )
    env_file.chmod(0o640)
    original_mode = stat.S_IMODE(env_file.stat().st_mode)

    changed = merge_env(env_file, {"ML_CLIENT_ID": "new", "ML_CLIENT_SECRET": "secret"})

    content = env_file.read_text(encoding="utf-8")
    assert "OLIST_ACCESS_TOKEN=managed-by-renewer" in content
    assert "OLIST_CLIENT_SECRET=managed-by-oauth" in content
    assert "ML_CLIENT_ID=new" in content
    assert "ML_CLIENT_SECRET=secret" in content
    assert changed == ["ML_CLIENT_ID", "ML_CLIENT_SECRET"]
    assert stat.S_IMODE(env_file.stat().st_mode) == original_mode


@pytest.mark.parametrize(
    "key",
    [
        "OLIST_ACCESS_TOKEN",
        "OLIST_REFRESH_TOKEN",
        "OLIST_CLIENT_ID",
        "OLIST_CLIENT_SECRET",
        "TINY_ACCESS_TOKEN",
        "TINY_REFRESH_TOKEN",
        "TINY_CLIENT_ID",
        "TINY_CLIENT_SECRET",
    ],
)
def test_merge_env_rejects_every_managed_oauth_key(tmp_path: Path, key: str) -> None:
    env_file = tmp_path / ".env"
    original = "OLIST_REFRESH_TOKEN=active-refresh\n"
    env_file.write_text(original, encoding="utf-8")

    with pytest.raises(ValueError, match="managed OAuth keys"):
        merge_env(env_file, {key: "must-not-overwrite"})

    assert env_file.read_text(encoding="utf-8") == original


def test_merge_env_rejects_unmanaged_keys(tmp_path: Path) -> None:
    with pytest.raises(ValueError, match="unsupported"):
        merge_env(tmp_path / ".env", {"UNAPPROVED_RUNTIME_KEY": "must-not-write"})


def test_merge_env_rejects_legacy_static_olist_token_as_unsupported(tmp_path: Path) -> None:
    env_file = tmp_path / ".env"
    original = "OLIST_REFRESH_TOKEN=active-refresh\n"
    env_file.write_text(original, encoding="utf-8")

    with pytest.raises(ValueError, match="unsupported environment keys"):
        legacy_key = "TOKEN_" + "API_OLIST"
        merge_env(env_file, {legacy_key: "legacy-token"})

    assert env_file.read_text(encoding="utf-8") == original


def test_merge_env_accepts_mercadopago_runtime_keys(tmp_path: Path) -> None:
    env_file = tmp_path / ".env"
    changed = merge_env(
        env_file,
        {
            "MERCADOPAGO_ACCESS_TOKEN": "access-token",
            "MERCADOPAGO_PUBLIC_KEY": "public-key",
            "MERCADOPAGO_WEBHOOK_SECRET": "webhook-secret",
        },
    )

    assert changed == [
        "MERCADOPAGO_ACCESS_TOKEN",
        "MERCADOPAGO_PUBLIC_KEY",
        "MERCADOPAGO_WEBHOOK_SECRET",
    ]
    content = env_file.read_text(encoding="utf-8")
    assert "MERCADOPAGO_ACCESS_TOKEN=access-token" in content
    assert "MERCADOPAGO_PUBLIC_KEY=public-key" in content
    assert "MERCADOPAGO_WEBHOOK_SECRET=webhook-secret" in content


def test_merge_env_sanitizes_malformed_existing_lines_even_without_updates(tmp_path: Path) -> None:
    env_file = tmp_path / ".env"
    env_file.write_text(
        "# keep comment\n"
        "symlink ../../shared/.env .env\n"
        "VALID_EXISTING=value\n"
        "stray-token-without-equals\n"
        "\n",
        encoding="utf-8",
    )
    env_file.chmod(0o640)

    changed = merge_env(env_file, {})
    content = env_file.read_text(encoding="utf-8")

    assert changed == []
    assert "# keep comment" in content
    assert "VALID_EXISTING=value" in content
    assert "symlink ../../shared/.env .env" not in content
    assert "stray-token-without-equals" not in content
    assert stat.S_IMODE(env_file.stat().st_mode) == 0o640


# ---------------------------------------------------------------------------
# Mercado Livre credential ownership (MLRR OAuth ownership plan, Task 9).
# ---------------------------------------------------------------------------

SNAPSHOT = "/home/ubuntu/shopvivaliz-deploy/shared/storage/private/ml-access-token.json"
LEGACY_TOKEN_LINES = (
    "ML_ACCESS_TOKEN=legacy-access\n"
    "ML_REFRESH_TOKEN=legacy-refresh\n"
    "MERCADO_LIVRE_ACCESS_TOKEN=legacy-access-alias\n"
    "MERCADO_LIVRE_REFRESH_TOKEN=legacy-refresh-alias\n"
)


def test_merge_env_accepts_only_the_two_non_secret_ownership_keys(tmp_path: Path) -> None:
    env_file = tmp_path / ".env"
    env_file.write_text("ML_CLIENT_ID=app\n", encoding="utf-8")

    changed = merge_env(
        env_file,
        {"ML_TOKEN_OWNER": "mlrr", "ML_ACCESS_SNAPSHOT_FILE": SNAPSHOT},
    )

    assert changed == ["ML_ACCESS_SNAPSHOT_FILE", "ML_TOKEN_OWNER"]
    content = env_file.read_text(encoding="utf-8")
    assert "ML_TOKEN_OWNER=mlrr" in content
    assert f"ML_ACCESS_SNAPSHOT_FILE={SNAPSHOT}" in content
    # Static application metadata is still required by publishing endpoints.
    assert "ML_CLIENT_ID=app" in content


def test_merge_env_drops_legacy_token_lines_when_ownership_becomes_mlrr(tmp_path: Path) -> None:
    env_file = tmp_path / ".env"
    env_file.write_text(
        LEGACY_TOKEN_LINES + "ML_CLIENT_ID=app\nML_CLIENT_SECRET=app-secret\nUNRELATED=keep\n",
        encoding="utf-8",
    )

    merge_env(env_file, {"ML_TOKEN_OWNER": "mlrr", "ML_ACCESS_SNAPSHOT_FILE": SNAPSHOT})

    content = env_file.read_text(encoding="utf-8")
    for key in ("ML_ACCESS_TOKEN", "ML_REFRESH_TOKEN", "MERCADO_LIVRE_ACCESS_TOKEN", "MERCADO_LIVRE_REFRESH_TOKEN"):
        assert f"{key}=" not in content
    assert "legacy-refresh" not in content
    assert "ML_CLIENT_ID=app" in content
    assert "ML_CLIENT_SECRET=app-secret" in content
    assert "UNRELATED=keep" in content


def test_merge_env_drops_legacy_token_lines_when_owner_is_already_mlrr(tmp_path: Path) -> None:
    env_file = tmp_path / ".env"
    env_file.write_text("ML_TOKEN_OWNER=mlrr\n" + LEGACY_TOKEN_LINES, encoding="utf-8")

    changed = merge_env(env_file, {})

    assert changed == []
    content = env_file.read_text(encoding="utf-8")
    assert "ML_TOKEN_OWNER=mlrr" in content
    assert "legacy-access" not in content
    assert "legacy-refresh" not in content


def test_merge_env_preserves_legacy_token_lines_while_owner_is_legacy(tmp_path: Path) -> None:
    env_file = tmp_path / ".env"
    env_file.write_text("ML_TOKEN_OWNER=legacy\n" + LEGACY_TOKEN_LINES, encoding="utf-8")

    merge_env(env_file, {"ML_CLIENT_ID": "app"})

    content = env_file.read_text(encoding="utf-8")
    assert "ML_ACCESS_TOKEN=legacy-access" in content
    assert "ML_REFRESH_TOKEN=legacy-refresh" in content
    assert "MERCADO_LIVRE_ACCESS_TOKEN=legacy-access-alias" in content
    assert "MERCADO_LIVRE_REFRESH_TOKEN=legacy-refresh-alias" in content


def test_merge_env_preserves_legacy_token_lines_when_no_owner_is_declared(tmp_path: Path) -> None:
    env_file = tmp_path / ".env"
    env_file.write_text(LEGACY_TOKEN_LINES, encoding="utf-8")

    merge_env(env_file, {"ML_CLIENT_ID": "app"})

    content = env_file.read_text(encoding="utf-8")
    assert "ML_ACCESS_TOKEN=legacy-access" in content
    assert "ML_REFRESH_TOKEN=legacy-refresh" in content


@pytest.mark.parametrize(
    "key",
    [
        "ML_ACCESS_TOKEN",
        "ML_REFRESH_TOKEN",
        "MERCADO_LIVRE_ACCESS_TOKEN",
        "MERCADO_LIVRE_REFRESH_TOKEN",
    ],
)
def test_merge_env_still_refuses_incoming_mercado_livre_token_values(tmp_path: Path, key: str) -> None:
    env_file = tmp_path / ".env"
    original = "ML_TOKEN_OWNER=mlrr\n" + LEGACY_TOKEN_LINES
    env_file.write_text(original, encoding="utf-8")

    with pytest.raises(ValueError, match="managed OAuth keys"):
        merge_env(env_file, {"ML_TOKEN_OWNER": "mlrr", key: "must-not-be-written"})

    assert env_file.read_text(encoding="utf-8") == original


def test_merge_env_rejects_an_unknown_ownership_value(tmp_path: Path) -> None:
    env_file = tmp_path / ".env"
    original = "ML_TOKEN_OWNER=legacy\n"
    env_file.write_text(original, encoding="utf-8")

    with pytest.raises(ValueError, match="ML_TOKEN_OWNER"):
        merge_env(env_file, {"ML_TOKEN_OWNER": "shopvivaliz"})

    assert env_file.read_text(encoding="utf-8") == original


def test_master_production_pipeline_runs_env_update_with_privilege() -> None:
    workflow = SCRIPT.parents[1] / ".github" / "workflows" / "master-production-pipeline.yml"
    text = workflow.read_text(encoding="utf-8")
    assert "sudo python3 /tmp/shopvivaliz-update-production-env.py" in text
