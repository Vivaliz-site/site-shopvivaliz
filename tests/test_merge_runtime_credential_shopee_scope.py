#!/usr/bin/env python3

from __future__ import annotations

import importlib.util
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = ROOT / "scripts" / "merge-runtime-credential-union.py"
WORKFLOW_PATH = ROOT / ".github" / "workflows" / "merge-runtime-credential-union.yml"
SPEC = importlib.util.spec_from_file_location("credential_union_shopee_scope", MODULE_PATH)
assert SPEC and SPEC.loader
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


def main() -> None:
    fields = [
        b"SHOPEE_PARTNER_ID", b"12345678",
        b"SHOPEE_PARTNER_KEY", b"valid-shopee-partner-key",
        b"SHOPEE_SHOP_ID", b"87654321",
        b"OLIST_CLIENT_SECRET", b"bad",
    ]

    shopee_values = MODULE.parse_payload_fields(fields, scope="shopee")
    assert set(shopee_values) == {
        "SHOPEE_PARTNER_ID",
        "SHOPEE_PARTNER_KEY",
        "SHOPEE_SHOP_ID",
    }

    try:
        MODULE.parse_payload_fields(fields, scope="all")
    except ValueError as exc:
        assert "OLIST_CLIENT_SECRET" in str(exc)
    else:
        raise AssertionError("full scope unexpectedly accepted the short Olist secret")

    workflow = WORKFLOW_PATH.read_text(encoding="utf-8")
    assert "- shopee" in workflow
    assert "SHOPEE_SCOPE_VALIDATED=true" in workflow
    assert 'if [ "$SCOPE" = shopee ]; then' in workflow
    assert "/home/ubuntu/shopvivaliz-deploy/shared/shopee-tokens.json" in workflow

    print("merge_runtime_credential_shopee_scope_tests=passed")


if __name__ == "__main__":
    main()
