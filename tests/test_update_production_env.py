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
