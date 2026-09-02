#!/usr/bin/env python3

from __future__ import annotations

import importlib.util
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MODULE_PATH = ROOT / "scripts" / "merge-runtime-credential-union.py"
WORKFLOW_PATH = ROOT / ".github" / "workflows" / "merge-runtime-credential-union.yml"
SPEC = importlib.util.spec_from_file_location("credential_union_scope", MODULE_PATH)
assert SPEC and SPEC.loader
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


def main() -> None:
    fields = [
        b"BREVO_API_KEY", b"valid-brevo-api-key",
        b"EMAIL_USER", b"atendimento@example.test",
        b"EMAIL_PASSWORD", b"valid-email-password",
        b"OLIST_CLIENT_SECRET", b"bad",
    ]
    email_values = MODULE.parse_payload_fields(fields, scope="email")
    assert set(email_values) == {"BREVO_API_KEY", "EMAIL_USER", "EMAIL_PASSWORD"}
    amazon_fields = [
        b"AMAZON_LWA_CLIENT_ID", b"amzn-client-id-valid",
        b"AMAZON_LWA_CLIENT_SECRET", b"amzn-client-secret-valid",
        b"AMAZON_LWA_REFRESH_TOKEN", b"Atzr-valid-refresh-token",
        b"OLIST_CLIENT_SECRET", b"bad",
    ]
    amazon_values = MODULE.parse_payload_fields(amazon_fields, scope="amazon")
    assert set(amazon_values) == {"AMAZON_LWA_CLIENT_ID", "AMAZON_LWA_CLIENT_SECRET", "AMAZON_LWA_REFRESH_TOKEN"}
    returns_fields = amazon_fields[:-2] + [
        b"GOOGLE_OAUTH_CLIENT_ID", b"google-client-id-valid",
        b"GOOGLE_OAUTH_CLIENT_SECRET", b"google-client-secret-valid",
        b"GOOGLE_OAUTH_REFRESH_TOKEN", b"google-refresh-token-valid",
        b"SELLER_CENTRAL_BRIDGE_TOKEN", b"seller-central-bridge-token-valid-0123456789",
        b"OLIST_CLIENT_SECRET", b"bad",
    ]
    returns_values = MODULE.parse_payload_fields(returns_fields, scope="amazon_returns")
    assert set(returns_values) == {
        "AMAZON_LWA_CLIENT_ID", "AMAZON_LWA_CLIENT_SECRET", "AMAZON_LWA_REFRESH_TOKEN",
        "GOOGLE_OAUTH_CLIENT_ID", "GOOGLE_OAUTH_CLIENT_SECRET", "GOOGLE_OAUTH_REFRESH_TOKEN",
        "SELLER_CENTRAL_BRIDGE_TOKEN",
    }
    try:
        MODULE.parse_payload_fields(fields, scope="all")
    except ValueError as exc:
        assert "OLIST_CLIENT_SECRET" in str(exc)
    else:
        raise AssertionError("full scope accepted the intentionally short Olist secret")

    workflow = WORKFLOW_PATH.read_text(encoding="utf-8")
    assert "scope:" in workflow
    assert "- all" in workflow and "- email" in workflow and "- amazon" in workflow and "- amazon_returns" in workflow
    assert "SCOPE: ${{ github.event.inputs.scope || 'all' }}" in workflow
    assert r'--scope \"$SCOPE\"' in workflow
    assert "EMAIL_SCOPE_VALIDATED=true" in workflow
    assert "AMAZON_SCOPE_VALIDATED=true" in workflow
    assert "AMAZON_RETURNS_SCOPE_VALIDATED=true" in workflow
    assert "SELLER_CENTRAL_BRIDGE_TOKEN_VALUE: ${{ secrets.SELLER_CENTRAL_BRIDGE_TOKEN }}" in workflow
    assert "SELLER_CENTRAL_BRIDGE_TOKEN" in workflow
    assert "if [ \"$SCOPE\" = email ]; then" in workflow

    print("merge_runtime_credential_scope_tests=passed")


if __name__ == "__main__":
    main()
