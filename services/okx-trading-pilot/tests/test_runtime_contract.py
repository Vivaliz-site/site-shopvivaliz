from pathlib import Path

ROOT=Path(__file__).parents[1]

def test_service_has_no_literal_secret_and_defaults_shadow():
    txt=(ROOT/'systemd'/'shopvivaliz-okx-pilot.service').read_text()
    assert 'EnvironmentFile=' in txt
    assert 'LIVE_PILOT' not in txt
    assert 'API_KEY=' not in txt and 'SECRET=' not in txt and 'PASSPHRASE=' not in txt

def test_pin_is_exact_not_latest():
    version=(ROOT/'agent-trade-kit.version').read_text().strip()
    assert version and version != 'latest' and version[0].isdigit()

def test_mcp_service_is_pinned_live_read_only():
    import json
    txt=(ROOT/'systemd'/'shopvivaliz-okx-mcp.service').read_text()
    repo_root=ROOT.parent.parent
    pkg=json.loads((repo_root/'ops'/'okx-trading-pilot'/'package.json').read_text())
    bridge=(repo_root/'ops'/'okx-trading-pilot'/'mcp-bridge.mjs').read_text()
    assert pkg['dependencies']['@okx_ai/okx-trade-mcp']=='1.4.8'
    assert "['--profile',PROFILE,'--modules','all','--read-only']" in bridge
    assert 'EnvironmentFile=-/home/ubuntu/shopvivaliz-deploy/shared/okx-pilot.env' in txt

def test_installer_refuses_active_release_edit_and_uses_shared_state():
    txt=(ROOT/'scripts'/'install.sh').read_text()
    assert '/home/ubuntu/shopvivaliz-deploy/current' not in txt
    assert '/home/ubuntu/shopvivaliz-deploy/shared/okx-pilot' in txt
    assert 'shopvivaliz-okx-mcp.service' in txt
    assert 'shopvivaliz-okx-pilot.service' in txt

def test_mcp_service_runs_shared_read_bridge_not_raw_stdio():
    txt=(ROOT/'systemd'/'shopvivaliz-okx-mcp.service').read_text()
    assert 'node /home/ubuntu/shopvivaliz-deploy/shared/okx-pilot/runtime/mcp-bridge.mjs' in txt
    assert 'okx-trade-mcp@' not in txt

def test_installer_materializes_pinned_bridge_runtime():
    txt=(ROOT/'scripts'/'install.sh').read_text()
    assert 'ops/okx-trading-pilot' in txt
    assert 'STATE_DIR=/home/ubuntu/shopvivaliz-deploy/shared/okx-pilot' in txt
    assert 'RUNTIME_DIR="$STATE_DIR/runtime"' in txt
    assert 'npm install --omit=dev' in txt
