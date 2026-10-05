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
