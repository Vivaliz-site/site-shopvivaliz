from __future__ import annotations

import importlib.util
import io
import json
import os
import urllib.error
import urllib.parse
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


def test_deploy_restarts_integrations_after_current_release_swap() -> None:
    deploy = (Path(__file__).resolve().parents[1] / "scripts" / "deploy-production.sh").read_text(
        encoding="utf-8"
    )
    switch = 'mv -Tf "$CURRENT_LINK.tmp" "$CURRENT_LINK"'
    production_switch = deploy.rfind(switch)
    restart = deploy.find("if ! restart_runtime_services; then", production_switch)
    apache_reload = deploy.find("if ! sudo systemctl reload apache2; then", production_switch)
    assert production_switch >= 0
    assert production_switch < restart < apache_reload
    assert "shopvivaliz-token-renewer.service" in deploy
    assert "shopvivaliz-shopee-token-renewer.service" in deploy
    assert "shopvivaliz-agent.service" in deploy


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
    assert "TINY_ACCESS_TOKEN=new-access" in content
    assert "TINY_REFRESH_TOKEN=new-refresh" in content
    assert "TOKEN_API_OLIST=new-access" in content
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


def test_http_error_logs_only_whitelisted_oauth_classification(monkeypatch, capsys) -> None:
    client_id = "sensitive-client-id"
    client_secret = "sensitive-client-secret"
    refresh_token = "sensitive-refresh-token"
    sensitive_description = "provider detail containing sensitive-refresh-token and other context"
    body = json.dumps(
        {"error": "invalid_grant", "error_description": sensitive_description}
    ).encode("utf-8")
    error = urllib.error.HTTPError(
        renewer.TOKEN_URL,
        400,
        "Bad Request",
        {},
        io.BytesIO(body),
    )

    def raise_http_error(*args, **kwargs):
        raise error

    monkeypatch.setattr(renewer.urllib.request, "urlopen", raise_http_error)

    result = renewer.renew_token(
        {
            "OLIST_CLIENT_ID": client_id,
            "OLIST_CLIENT_SECRET": client_secret,
            "OLIST_REFRESH_TOKEN": refresh_token,
        }
    )

    assert result is None
    output = capsys.readouterr().out
    assert "HTTP 400" in output
    assert "oauth_error=invalid_grant" in output
    assert sensitive_description not in output
    assert client_id not in output
    assert client_secret not in output
    assert refresh_token not in output


def test_http_error_never_logs_unknown_provider_error_text(monkeypatch, capsys) -> None:
    provider_text = "credential-shaped-provider-value-should-never-be-logged"
    body = json.dumps(
        {"error": provider_text, "error_description": "another-sensitive-description"}
    ).encode("utf-8")
    error = urllib.error.HTTPError(
        renewer.TOKEN_URL,
        401,
        "Unauthorized",
        {},
        io.BytesIO(body),
    )

    def raise_http_error(*args, **kwargs):
        raise error

    monkeypatch.setattr(renewer.urllib.request, "urlopen", raise_http_error)

    result = renewer.renew_token(
        {
            "OLIST_CLIENT_ID": "client",
            "OLIST_CLIENT_SECRET": "secret",
            "OLIST_REFRESH_TOKEN": "refresh",
        }
    )

    assert result is None
    output = capsys.readouterr().out
    assert "HTTP 401" in output
    assert provider_text not in output
    assert "another-sensitive-description" not in output
    assert "oauth_error=" not in output


def test_invalid_olist_client_falls_back_to_tiny_aliases(monkeypatch, capsys) -> None:
    calls: list[tuple[str, str]] = []

    class FakeResponse:
        def __enter__(self):
            return self

        def __exit__(self, exc_type, exc, tb):
            return False

        def read(self):
            return json.dumps(
                {"access_token": "new-access", "refresh_token": "new-refresh"}
            ).encode("utf-8")

    def oauth_error(status: int, code: str) -> urllib.error.HTTPError:
        return urllib.error.HTTPError(
            renewer.TOKEN_URL,
            status,
            "OAuth error",
            {},
            io.BytesIO(json.dumps({"error": code}).encode("utf-8")),
        )

    def fake_urlopen(request, timeout=30):
        payload = urllib.parse.parse_qs(request.data.decode("utf-8"))
        client_id = payload["client_id"][0]
        refresh_token = payload["refresh_token"][0]
        calls.append((client_id, refresh_token))
        if client_id == "olist-client-stale":
            raise oauth_error(401, "invalid_client")
        if refresh_token == "olist-refresh-stale":
            raise oauth_error(400, "invalid_grant")
        return FakeResponse()

    monkeypatch.setattr(renewer.urllib.request, "urlopen", fake_urlopen)

    result = renewer.renew_token(
        {
            "OLIST_CLIENT_ID": "olist-client-stale",
            "OLIST_CLIENT_SECRET": "olist-secret-stale",
            "OLIST_REFRESH_TOKEN": "olist-refresh-stale",
            "TINY_CLIENT_ID": "tiny-client-current",
            "TINY_CLIENT_SECRET": "tiny-secret-current",
            "TINY_REFRESH_TOKEN": "tiny-refresh-current",
        }
    )

    assert isinstance(result, dict)
    assert result["access_token"] == "new-access"
    assert result["refresh_token"] == "new-refresh"
    assert result["_sv_credential_alias"] == "tiny"
    assert result["_sv_refresh_alias"] == "tiny"
    assert calls == [
        ("olist-client-stale", "olist-refresh-stale"),
        ("tiny-client-current", "olist-refresh-stale"),
        ("tiny-client-current", "tiny-refresh-current"),
    ]
    output = capsys.readouterr().out
    assert output == ""


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
    assert "TINY_ACCESS_TOKEN=new-access" in content
    assert "TINY_REFRESH_TOKEN=new-refresh" in content
    assert "TOKEN_API_OLIST=new-access" in content
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
