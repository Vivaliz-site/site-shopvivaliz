from pathlib import Path
ROOT=Path(__file__).parents[1]

def test_service_has_no_literal_secret_and_defaults_shadow():
    txt=(ROOT/'systemd'/'shopvivaliz-okx-pilot.service').read_text()
    assert 'EnvironmentFile=' in txt
    assert 'LIVE_PILOT' not in txt
    assert 'API_KEY=' not in txt and 'SECRET=' not in txt and 'PASSPHRASE=' not in txt

def test_pin_is_exact_not_latest():
    version=(ROOT/'agent-trade-kit.version').read_text().strip()
    assert version and version!='latest' and version[0].isdigit()

def test_mcp_service_is_pinned_live_read_only():
    txt=(ROOT/'systemd'/'shopvivaliz-okx-mcp.service').read_text()
    assert '@okx_ai/okx-trade-mcp@1.4.8' in txt
    assert '--profile live' in txt
    assert '--read-only' in txt
    assert '--modules all' in txt
    assert 'EnvironmentFile=/home/ubuntu/shopvivaliz-deploy/shared/okx-pilot.env' in txt

def test_installer_never_edits_active_current_release():
    txt=(ROOT/'scripts'/'install.sh').read_text()
    assert '/home/ubuntu/shopvivaliz-deploy/current' not in txt
    assert '/home/ubuntu/shopvivaliz-deploy/shared/okx-pilot' in txt
    assert 'shopvivaliz-okx-mcp.service' in txt
    assert 'shopvivaliz-okx-pilot.service' in txt
