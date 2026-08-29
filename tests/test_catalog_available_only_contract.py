from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PRODUCTS = ROOT / "api" / "catalog" / "products.php"
AUDIT = ROOT / "scripts" / "production-functional-audit.sh"


def test() -> None:
    products = PRODUCTS.read_text(encoding="utf-8")
    audit = AUDIT.read_text(encoding="utf-8")

    assert "$_GET['available_only']" in products, (
        "catalog API must accept available_only=1; audit and external clients use this explicit contract"
    )
    assert "available_only=1" in audit, (
        "production audit must request only products that can actually be sold"
    )
    assert "(int)($p['stock'] ?? 0) > 0" in products, (
        "available-only filtering must reject zero/negative stock"
    )


if __name__ == "__main__":
    test()
    print("catalog available-only contract: PASS")
