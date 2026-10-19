import importlib.util
from types import SimpleNamespace
from pathlib import Path


def load_renewer():
    path = Path(__file__).resolve().parents[1] / "daemon-token-renewer.py"
    spec = importlib.util.spec_from_file_location("token_renewer", path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


def test_atomic_write_does_not_chown_file_owner_as_unprivileged_user(tmp_path, monkeypatch):
    renewer = load_renewer()
    target = tmp_path / "tokens.json"
    calls = []

    def unprivileged_chown(path, uid, gid):
        calls.append((uid, gid))
        if uid != -1:
            raise PermissionError("unprivileged process cannot chown owner")

    monkeypatch.setattr(renewer.os, "chown", unprivileged_chown)
    renewer._atomic_write_private(target, "{}\n", 0o660, 1001, 33)

    assert target.read_text(encoding="utf-8") == "{}\n"
    assert calls == [(-1, 33)]


def test_atomic_write_survives_denied_group_chown_on_setgid_directory(tmp_path, monkeypatch):
    renewer = load_renewer()
    target = tmp_path / "tokens.json"

    def denied_group_chown(path, uid, gid):
        raise PermissionError("group change denied")

    real_os = renewer.os
    monkeypatch.setattr(
        renewer,
        "os",
        SimpleNamespace(
            name="posix",
            fdopen=real_os.fdopen,
            fsync=real_os.fsync,
            chmod=real_os.chmod,
            chown=denied_group_chown,
        ),
    )
    renewer._atomic_write_private(target, "{}\n", 0o660, 1001, 33)

    assert target.read_text(encoding="utf-8") == "{}\n"


def test_token_store_parent_survives_denied_ownership_reconciliation(tmp_path, monkeypatch):
    renewer = load_renewer()
    env_file = tmp_path / ".env"
    token_file = tmp_path / "private" / "olist-tokens.json"
    env_file.write_text("OLIST_CLIENT_ID=client\n", encoding="utf-8")
    monkeypatch.setattr(renewer, "ENV_PATH", env_file)
    monkeypatch.setattr(renewer, "TOKEN_STORE_PATH", token_file)

    real_os = renewer.os

    def denied_chown(path, uid, gid):
        raise PermissionError("directory ownership denied")

    monkeypatch.setattr(
        renewer,
        "os",
        SimpleNamespace(
            name="posix",
            chmod=real_os.chmod,
            chown=denied_chown,
        ),
    )
    renewer.ensure_token_store_parent()

    assert token_file.parent.is_dir()


def test_env_atomic_write_does_not_chown_file_owner_as_unprivileged_user(
    tmp_path, monkeypatch
):
    renewer = load_renewer()
    target = tmp_path / ".env"
    target.write_text(
        "OLIST_ACCESS_TOKEN=old\nOLIST_REFRESH_TOKEN=old-refresh\n",
        encoding="utf-8",
    )
    target.chmod(0o640)
    original = target.stat()
    calls = []

    def unprivileged_chown(path, uid, gid):
        calls.append((uid, gid))
        if uid != -1:
            raise PermissionError("unprivileged process cannot chown owner")

    monkeypatch.setattr(renewer, "ENV_PATH", target)
    monkeypatch.setattr(renewer.os, "chown", unprivileged_chown)

    renewer.update_env("new-access", "new-refresh")

    updated = target.stat()
    assert "OLIST_ACCESS_TOKEN=new-access" in target.read_text(encoding="utf-8")
    assert calls == [(-1, original.st_gid)]
    assert updated.st_uid == original.st_uid
    assert updated.st_gid == original.st_gid
    assert updated.st_mode & 0o777 == 0o640


def test_env_atomic_write_propagates_group_chown_permission_error(
    tmp_path, monkeypatch
):
    renewer = load_renewer()
    target = tmp_path / ".env"
    original_text = "OLIST_ACCESS_TOKEN=old\nOLIST_REFRESH_TOKEN=old-refresh\n"
    target.write_text(original_text, encoding="utf-8")

    def denied_chown(path, uid, gid):
        raise PermissionError("group change denied")

    monkeypatch.setattr(renewer, "ENV_PATH", target)
    monkeypatch.setattr(renewer.os, "chown", denied_chown)

    try:
        renewer.update_env("new-access", "new-refresh")
    except PermissionError as exc:
        assert str(exc) == "group change denied"
    else:
        raise AssertionError("PermissionError must remain fail-closed")

    assert target.read_text(encoding="utf-8") == original_text
