from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WF = ROOT / '.github' / 'workflows' / 'configure-production-runtime.yml'


def test() -> None:
    text = WF.read_text(encoding='utf-8')
    assert 'scripts/configure-production-runtime.py' in text
    assert 'scripts/materialize-runtime-secrets.php' in text
    assert '/tmp/shopvivaliz-configure-production-runtime.py' in text
    assert '/tmp/shopvivaliz-materialize-runtime-secrets.php' in text
    assert 'scp ' in text
    assert "python3 /tmp/shopvivaliz-configure-production-runtime.py" in text
    assert "php /tmp/shopvivaliz-materialize-runtime-secrets.php" in text
    assert 'rm -f /tmp/shopvivaliz-configure-production-runtime.py /tmp/shopvivaliz-materialize-runtime-secrets.php' in text


if __name__ == '__main__':
    test()
    print('runtime workflow checked-in scripts contract: PASS')
