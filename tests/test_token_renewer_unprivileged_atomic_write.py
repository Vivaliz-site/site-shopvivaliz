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
