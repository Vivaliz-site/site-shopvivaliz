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


def test_erp_cadastro_survives_canonical_daemon_projection_without_private_costs():
    product = daemon.public_product({
        "id": 353732318,
        "sku": "ved-70",
        "descricao": "Vedante aluminio 70 cm",
        "ncm": "7604.10.29",
        "origem": 1,
        "unidade": "UN",
        "unidadePorCaixa": "6",
        "garantia": "3 meses",
        "gtin": "7898465422498",
        "fornecedores": [{"id": 999, "nome": "PRIVADO"}],
        "precos": {"preco": 34.99, "precoCusto": 10},
        "dimensoes": {
            "largura": 5, "altura": 3, "comprimento": 70,
            "pesoLiquido": 0.199, "pesoBruto": 0.249,
            "diametro": 0, "quantidadeVolumes": 1,
        },
    })
    assert product["ncm"] == "7604.10.29"
    assert product["origem"] == "1"
    assert product["unidadePorCaixa"] == "6"
    assert product["garantia"] == "3 meses"
    assert product["dimensoes"]["quantidadeVolumes"] == 1
    assert product["dimensoes"]["pesoBruto"] == 0.249
    assert product["_cache_schema_version"] == 3
    assert "fornecedores" not in product
    assert "precoCusto" not in product["precos"]
    assert "PRIVADO" not in str(product)


if __name__ == "__main__":
    test_dimension_fields_survive_public_projection()
    test_erp_cadastro_survives_canonical_daemon_projection_without_private_costs()
    print("DAEMON_SHIPPING_FIELDS_OK")
