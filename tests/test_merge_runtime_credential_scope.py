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
    try:
        MODULE.parse_payload_fields(fields, scope="all")
    except ValueError as exc:
        assert "OLIST_CLIENT_SECRET" in str(exc)
    else:
        raise AssertionError("full scope accepted the intentionally short Olist secret")

    workflow = WORKFLOW_PATH.read_text(encoding="utf-8")
    assert "scope:" in workflow
    assert "- all" in workflow and "- email" in workflow
    assert "SCOPE: ${{ github.event.inputs.scope || 'all' }}" in workflow
    assert r'--scope \"$SCOPE\"' in workflow
    assert "EMAIL_SCOPE_VALIDATED=true" in workflow
    assert "if [ \"$SCOPE\" = email ]; then" in workflow

    print("merge_runtime_credential_scope_tests=passed")


if __name__ == "__main__":
    main()
