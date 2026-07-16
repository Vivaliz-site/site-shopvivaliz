from __future__ import annotations

import importlib.util
from pathlib import Path


MODULE_PATH = Path(__file__).resolve().parents[1] / "daemon-token-renewer.py"
SPEC = importlib.util.spec_from_file_location("token_renewer", MODULE_PATH)
renewer = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(renewer)


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
