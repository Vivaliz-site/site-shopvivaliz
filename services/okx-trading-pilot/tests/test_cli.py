import json
from okx_pilot.cli import main

def test_status_defaults_to_shadow(capsys):
    assert main(['status'])==0
    out=json.loads(capsys.readouterr().out)
    assert out['mode']=='SHADOW'
    assert out['live_execution_enabled'] is False

def test_cli_does_not_offer_live_execution_command():
    try:
        main(['promote-live-pilot'])
    except SystemExit as e:
        assert e.code != 0
    else:
        raise AssertionError('live promotion command must not be exposed')
