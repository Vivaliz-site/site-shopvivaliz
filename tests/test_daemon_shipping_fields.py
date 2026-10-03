import importlib.util
from pathlib import Path

MODULE_PATH = Path(__file__).resolve().parents[1] / "daemon-sync-products.py"
SPEC = importlib.util.spec_from_file_location("daemon_sync_products_shipping", MODULE_PATH)
daemon = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(daemon)

def test_dimension_fields_survive_public_projection():
    row = daemon.public_product({
        "id": 2,
        "sku": "SHIP-2",
        "descricao": "Produto",
        "situacao": "A",
        "dimensoes": {
            "largura": 15,
            "altura": 10,
            "comprimento": 20,
            "pesoLiquido": 0.8,
            "pesoBruto": 1.05,
        },
    })
    assert row["dimensoes"]["pesoLiquido"] == 0.8
    assert row["dimensoes"]["pesoBruto"] == 1.05
    assert row["dimensoes"]["largura"] == 15.0
    assert row["dimensoes"]["altura"] == 10.0
    assert row["dimensoes"]["comprimento"] == 20.0


if __name__ == "__main__":
    test_dimension_fields_survive_public_projection()
    print("DAEMON_SHIPPING_FIELDS_OK")
