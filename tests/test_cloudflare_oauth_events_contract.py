"""Fail-closed contract for read-only Cloudflare OAuth callback event diagnostics."""
from pathlib import Path

WORKFLOW = (
    Path(__file__).resolve().parents[1]
    / ".github/workflows/cloudflare-zone-settings-check.yml"
)


def test_callback_events_are_read_only_and_sanitized():
    source = WORKFLOW.read_text(encoding="utf-8")
    assert "https://api.cloudflare.com/client/v4/graphql" in source
    assert "firewallEventsAdaptive" in source
    assert "CF_OAUTH_SECURITY_EVENTS_BEGIN" in source
    assert '"/api/mlrr/oauth/callback"' in source
    assert "clientRequestPath" in source
    assert "clientRequestQuery" not in source
    assert "clientIP" not in source
    assert "userAgent" not in source
    assert "CF_OAUTH_SECURITY_EVENTS_END" in source
    assert "CF_OAUTH_EVENTS_STATUS=" in source
    assert "CF_OAUTH_EVENTS_STATUS=DENIED" in source
    assert "CF_OAUTH_EVENTS_STATUS=QUERY_FAILED" in source
    assert "Cloudflare zone cache/performance settings (read-only)" in source


if __name__ == "__main__":
    test_callback_events_are_read_only_and_sanitized()
    print("Cloudflare OAuth Security Events contract: PASS")
