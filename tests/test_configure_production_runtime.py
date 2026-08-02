from __future__ import annotations

import subprocess
import sys
from pathlib import Path


SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "configure-production-runtime.py"
KEYS = (
    "DB_HOST",
    "DB_PORT",
    "DB_NAME",
    "DB_USER",
    "DB_PASS",
    "OLIST_ACCESS_TOKEN",
    "OLIST_REFRESH_TOKEN",
    "OLIST_CLIENT_ID",
    "OLIST_CLIENT_SECRET",
    "TINY_ACCESS_TOKEN",
    "TINY_REFRESH_TOKEN",
    "TINY_CLIENT_ID",
    "TINY_CLIENT_SECRET",
    "SHOPVIVALIZ_AGENT_KEY",
)


def payload(overrides: dict[str, str]) -> bytes:
    values = {
        "DB_HOST": "db.internal",
        "DB_PORT": "3306",
        "DB_NAME": "shopvivaliz",
        "DB_USER": "shop_runtime",
        "DB_PASS": "database-password",
        "OLIST_ACCESS_TOKEN": "olist-access",
        "OLIST_REFRESH_TOKEN": "olist-refresh",
        "OLIST_CLIENT_ID": "olist-client",
        "OLIST_CLIENT_SECRET": "olist-secret",
        "TINY_ACCESS_TOKEN": "tiny-access",
        "TINY_REFRESH_TOKEN": "tiny-refresh",
        "TINY_CLIENT_ID": "tiny-client",
        "TINY_CLIENT_SECRET": "tiny-secret",
        "SHOPVIVALIZ_AGENT_KEY": "a" * 40,
    }
    values.update(overrides)
    return b"\0".join(values[key].encode() for key in KEYS) + b"\0"


def run_script(target: Path, data: bytes) -> subprocess.CompletedProcess[bytes]:
    return subprocess.run(
        [sys.executable, str(SCRIPT), str(target)],
        input=data,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )


def test_rejects_root_without_modifying_file(tmp_path: Path) -> None:
    target = tmp_path / ".env"
    original = "DB_USER=working_user\nDB_PASS=working-password\n"
    target.write_text(original, encoding="utf-8")

    result = run_script(target, payload({"DB_USER": "root"}))

    assert result.returncode == 2
    assert b"root database user is forbidden" in result.stderr
    assert target.read_text(encoding="utf-8") == original
    assert not list(tmp_path.glob(".env.backup.*"))


def test_writes_safe_tuple_atomically(tmp_path: Path) -> None:
    target = tmp_path / ".env"
    target.write_text("EXISTING=value\n", encoding="utf-8")

    result = run_script(target, payload({}))

    assert result.returncode == 0, result.stderr.decode()
    updated = target.read_text(encoding="utf-8")
    assert "DB_USER=shop_runtime" in updated
    assert "DB_NAME=shopvivaliz" in updated
    assert "database-password" in updated
    assert "database_user_safe=true" in result.stdout.decode()
    assert target.stat().st_mode & 0o777 == 0o600
    assert len(list(tmp_path.glob(".env.backup.*"))) == 1
