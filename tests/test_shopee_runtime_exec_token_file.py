#!/usr/bin/env python3

from __future__ import annotations

import importlib.util
import json
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = ROOT / "scripts" / "shopee_runtime_exec.py"
SPEC = importlib.util.spec_from_file_location("shopee_runtime_exec", MODULE_PATH)
assert SPEC and SPEC.loader
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


def main() -> None:
    with tempfile.TemporaryDirectory() as temp_dir:
        temp = Path(temp_dir)
        env_file = temp / ".env"
        env_file.write_text(
            "SHOPEE_PARTNER_ID=12345678\n"
            "SHOPEE_PARTNER_KEY=valid-partner-key\n"
            "SHOPEE_SHOP_ID=87654321\n",
            encoding="utf-8",
        )
        token_file = temp / "shopee-tokens.json"
        token_file.write_text(
            json.dumps(
                {
                    "access_token": "valid-access-token",
                    "refresh_token": "valid-refresh-token",
                }
            ),
            encoding="utf-8",
        )

        loaded = MODULE.load_shopee_env(env_file)
        loaded = MODULE.load_shopee_token_file(token_file, loaded)
        assert loaded["SHOPEE_ACCESS_TOKEN"] == "valid-access-token"
        assert loaded["SHOPEE_REFRESH_TOKEN"] == "valid-refresh-token"

        explicit = MODULE.load_shopee_token_file(
            token_file,
            {"SHOPEE_ACCESS_TOKEN": "explicit-env-token"},
        )
        assert explicit["SHOPEE_ACCESS_TOKEN"] == "explicit-env-token"
        assert "SHOPEE_REFRESH_TOKEN" not in explicit

    print("shopee_runtime_exec_token_file_tests=passed")


if __name__ == "__main__":
    main()
