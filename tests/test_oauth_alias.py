from pathlib import Path

def test_callback_credential_aliases_present():
    text = Path('olist/callback.php').read_text(encoding='utf-8')
    assert 'clientSecret' in text
