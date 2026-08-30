from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
HEALTH = ROOT / "includes" / "integration-health.php"


def test() -> None:
    text = HEALTH.read_text(encoding="utf-8")
    assert "/api/v2/me/shipment/calculate" in text, (
        "Melhor Envio health must probe the same safe quote capability used by checkout"
    )
    assert "health-probe" in text
    assert "usable_options" in text
    assert "me_api_base() . '/api/v2/me'" not in text, (
        "account-profile endpoint can return 403 even while shipping quotes are valid"
    )


if __name__ == "__main__":
    test()
    print("melhor envio integration-health contract: PASS")
