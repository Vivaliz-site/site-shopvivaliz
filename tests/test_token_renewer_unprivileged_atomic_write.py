import importlib.util
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
