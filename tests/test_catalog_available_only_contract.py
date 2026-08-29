from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PRODUCTS = ROOT / "api" / "catalog" / "products.php"
AUDIT = ROOT / "scripts" / "production-functional-audit.sh"


def test() -> None:
    products = PRODUCTS.read_text(encoding="utf-8")
    audit = AUDIT.read_text(encoding="utf-8")

    assert "$_GET['available']" in products, (
        "catalog API must expose an explicit available=1 filter"
    )
    assert "available=1" in audit, (
        "production audit must request only products that can actually be sold"
    )
    assert "(int)($p['stock'] ?? 0) > 0" in products, (
        "availability filtering must reject zero/negative stock"
    )


if __name__ == "__main__":
    test()
    print("catalog available-only contract: PASS")
