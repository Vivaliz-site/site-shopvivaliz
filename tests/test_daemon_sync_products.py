import importlib.util
from pathlib import Path


MODULE_PATH = Path(__file__).resolve().parents[1] / "daemon-sync-products.py"
SPEC = importlib.util.spec_from_file_location("daemon_sync_products", MODULE_PATH)
daemon = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(daemon)


def test_enrich_products_uses_detail_stock_and_attachments(monkeypatch):
    monkeypatch.setattr(daemon, "load_previous_cache", lambda: {})
    monkeypatch.setattr(
        daemon,
        "api_get",
        lambda path, token: {
            "id": 10,
            "sku": "SKU-10",
            "situacao": "A",
            "estoque": {"quantidade": 7},
            "anexos": [{"url": "https://cdn.example.test/image.jpg"}],
        },
    )
    products, failures = daemon.enrich_products([{"id": 10, "situacao": "A"}], "token", workers=1)
    assert failures == 0
    assert products[0]["estoque_disponivel"] == 7
    assert products[0]["anexos"][0]["url"].startswith("https://")


def test_enrich_products_preserves_previous_detail_on_failure(monkeypatch):
    previous = {"10": {"id": 10, "sku": "SKU-10", "estoque_disponivel": 4}}
    monkeypatch.setattr(daemon, "load_previous_cache", lambda: previous)
    monkeypatch.setattr(daemon, "api_get", lambda path, token: (_ for _ in ()).throw(RuntimeError("offline")))
    products, failures = daemon.enrich_products([{"id": 10, "situacao": "A"}], "token", workers=1)
    assert failures == 1
    assert products[0]["estoque_disponivel"] == 4


def test_public_product_removes_internal_cost_and_supplier_data():
    product = daemon.public_product({
        "id": 1,
        "sku": "SKU-1",
        "descricao": "Produto",
        "situacao": "A",
        "precos": {"preco": 10, "precoCusto": 3},
        "estoque": {"quantidade": 2},
        "fornecedores": [{"nome": "Interno"}],
        "anexos": [{"url": "https://example.test/image.jpg", "id": "internal"}],
    })

    assert product["precos"] == {"preco": 10.0, "precoPromocional": 0.0}
    assert "fornecedores" not in product
    assert product["anexos"] == [{"url": "https://example.test/image.jpg"}]
