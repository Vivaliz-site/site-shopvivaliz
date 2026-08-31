#!/usr/bin/env python3

from __future__ import annotations

import importlib.util
import os
import tempfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = ROOT / "scripts" / "merge-runtime-credential-union.py"
SPEC = importlib.util.spec_from_file_location("credential_union", MODULE_PATH)
assert SPEC and SPEC.loader
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


def main() -> None:
    with tempfile.TemporaryDirectory() as temporary_directory:
        env_path = Path(temporary_directory) / ".env"
        env_path.write_text("KEEP=existing\nEMPTY=\n# retained\n", encoding="utf-8")
        os.chmod(env_path, 0o640)

        added, preserved, backup = MODULE.merge_missing(
            env_path,
            {
                "OPENAI_API_KEY": "new-openai-value",
                "DB_HOST": "database.internal",
                "KEEP": "must-not-overwrite",
            },
        )
        assert added == ["DB_HOST", "OPENAI_API_KEY"]
        assert preserved == ["KEEP"]
        assert backup and backup.is_file()
        if os.name != "nt":
            assert backup.stat().st_mode & 0o777 == 0o600
        text = env_path.read_text(encoding="utf-8")
        assert "KEEP=existing" in text
        assert "must-not-overwrite" not in text
        assert "DB_HOST=database.internal" in text
        assert "OPENAI_API_KEY=new-openai-value" in text
        if os.name != "nt":
            assert env_path.stat().st_mode & 0o777 == 0o640

        added_again, preserved_again, second_backup = MODULE.merge_missing(
            env_path, {"OPENAI_API_KEY": "rotated-value"}
        )
        assert added_again == []
        assert preserved_again == ["OPENAI_API_KEY"]
        assert second_backup is None
        assert "rotated-value" not in env_path.read_text(encoding="utf-8")

    try:
        MODULE.validate_value("GEMINI_API_KEY", "changeme")
    except ValueError:
        pass
    else:
        raise AssertionError("placeholder credential was accepted")

    print("merge_runtime_credential_union_tests=passed")


if __name__ == "__main__":
    main()
