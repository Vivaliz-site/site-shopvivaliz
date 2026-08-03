from __future__ import annotations

import importlib.util
import os
from pathlib import Path

import pytest


MODULE_PATH = Path(__file__).resolve().parents[1] / "daemon-token-renewer.py"
SPEC = importlib.util.spec_from_file_location("token_renewer", MODULE_PATH)
renewer = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(renewer)


def load_daemon(filename: str, module_name: str):
    path = Path(__file__).resolve().parents[1] / filename
    spec = importlib.util.spec_from_file_location(module_name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec and spec.loader
    spec.loader.exec_module(module)
    return module


shopee_renewer = load_daemon("daemon-shopee-token-renewer.py", "shopee_token_renewer")
google_renewer = load_daemon("daemon-google-token-renewer.py", "google_token_renewer")


def test_linux_daemons_follow_the_dynamic_current_release_path() -> None:
    if os.name == "nt":
        pytest.skip("Contrato de runtime Linux")
    expected = Path("/home/ubuntu/shopvivaliz-deploy/current/.env")
    assert renewer.ENV_PATH == expected
    assert shopee_renewer.ENV_PATH == expected
    assert google_renewer.ENV_PATH == expected


def test_atomic_env_update_preserves_unrelated_values(tmp_path: Path, monkeypatch) -> None:
    env_file = tmp_path / ".env"
    env_file.write_text(
        "UNCHANGED=value\nOLIST_ACCESS_TOKEN=old\nOLIST_REFRESH_TOKEN=old-refresh\n",
        encoding="utf-8",
    )
    monkeypatch.setattr(renewer, "ENV_PATH", env_file)

    renewer.update_env("new-access", "new-refresh")

    content = env_file.read_text(encoding="utf-8")
    assert "UNCHANGED=value" in content
    assert "OLIST_ACCESS_TOKEN=new-access" in content
    assert "OLIST_REFRESH_TOKEN=new-refresh" in content
    assert not list(tmp_path.glob(".env.*"))


def test_renew_once_never_logs_token_values(tmp_path: Path, monkeypatch, capsys) -> None:
    env_file = tmp_path / ".env"
    env_file.write_text(
        "OLIST_CLIENT_ID=id\nOLIST_CLIENT_SECRET=secret\n"
        "OLIST_ACCESS_TOKEN=old\nOLIST_REFRESH_TOKEN=old-refresh\n",
        encoding="utf-8",
    )
    monkeypatch.setattr(renewer, "ENV_PATH", env_file)
    monkeypatch.setattr(
        renewer,
        "renew_token",
        lambda config: {"access_token": "sensitive-access", "refresh_token": "sensitive-refresh"},
    )

    assert renewer.renew_once()
    output = capsys.readouterr().out
    assert "sensitive-access" not in output
    assert "sensitive-refresh" not in output


def test_atomic_env_update_writes_symlink_target_without_replacing_link(tmp_path: Path, monkeypatch) -> None:
    if os.name == "nt":
        pytest.skip("Windows sem privilégio de symlink; o runtime de produção é Linux")
    shared_env = tmp_path / "shared.env"
    release_env = tmp_path / ".env"
    shared_env.write_text(
        "OLIST_ACCESS_TOKEN=old\nOLIST_REFRESH_TOKEN=old-refresh\n",
        encoding="utf-8",
    )
    release_env.symlink_to(shared_env)
    monkeypatch.setattr(renewer, "ENV_PATH", release_env)

    renewer.update_env("new-access", "new-refresh")

    assert release_env.is_symlink()
    assert release_env.resolve() == shared_env
    content = shared_env.read_text(encoding="utf-8")
    assert "OLIST_ACCESS_TOKEN=new-access" in content
    assert "OLIST_REFRESH_TOKEN=new-refresh" in content
    assert not list(tmp_path.glob(".env.*"))


@pytest.mark.parametrize(
    ("module", "update", "expected"),
    [
        (shopee_renewer, lambda module: module.update_env("new-access", "new-refresh"), "SHOPEE_ACCESS_TOKEN=new-access"),
        (google_renewer, lambda module: module.write_env({"GOOGLE_ADS_ACCESS_TOKEN": "new-access"}), "GOOGLE_ADS_ACCESS_TOKEN=new-access"),
    ],
)
def test_other_renewers_preserve_shared_env_symlink(tmp_path: Path, monkeypatch, module, update, expected) -> None:
    if os.name == "nt":
        pytest.skip("Windows sem privilégio de symlink; o runtime de produção é Linux")
    shared_env = tmp_path / "shared.env"
    release_env = tmp_path / ".env"
    shared_env.write_text("UNCHANGED=value\n", encoding="utf-8")
    release_env.symlink_to(shared_env)
    monkeypatch.setattr(module, "ENV_PATH", release_env)

    assert update(module) is not False

    assert release_env.is_symlink()
    assert release_env.resolve() == shared_env
    assert expected in shared_env.read_text(encoding="utf-8")
