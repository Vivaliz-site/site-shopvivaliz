import importlib.util
from pathlib import Path


MODULE_PATH = Path(__file__).resolve().parents[1] / "daemon-sync-products.py"
SPEC = importlib.util.spec_from_file_location("daemon_sync_products", MODULE_PATH)
daemon = importlib.util.module_from_spec(SPEC)
assert SPEC and SPEC.loader
SPEC.loader.exec_module(daemon)


def test_enrich_products_uses_authoritative_availability_and_detail_attachments(monkeypatch):
    monkeypatch.setattr(daemon, "load_previous_cache", lambda: {})
    calls = []

    def api(path, token, **kwargs):
        calls.append(path)
        if path == "produtos/10":
            return {
                "id": 10, "sku": "SKU-10", "situacao": "A",
                "estoque": {"quantidade": 7},
                "anexos": [{"url": "https://cdn.example.test/image.jpg"}],
            }
        if path == "estoque/10":
            return {"saldo": 7, "reservado": 2, "disponivel": 5}
        raise AssertionError(path)

    monkeypatch.setattr(daemon, "api_get", api)
    products, failures = daemon.enrich_products([{"id": 10, "situacao": "A"}], "token", workers=1)
    assert failures == 0
    assert calls == ["produtos/10", "estoque/10"]
    assert products[0]["estoque_disponivel"] == 5
    assert products[0]["anexos"][0]["url"].startswith("https://")


def test_enrich_products_preserves_metadata_but_blocks_sales_when_availability_fails(monkeypatch):
    previous = {"10": {"id": 10, "sku": "SKU-10", "estoque_disponivel": 4,
                       "precos": {"preco": 25.0},
                       "anexos": [{"url": "https://cdn.example.test/image.jpg"}]}}
    monkeypatch.setattr(daemon, "load_previous_cache", lambda: previous)

    def offline(path, token, **kwargs):
        raise RuntimeError("offline")

    monkeypatch.setattr(daemon, "api_get", offline)
    products, failures = daemon.enrich_products([{"id": 10, "situacao": "A"}], "token", workers=1)
    assert failures == 1
    assert products[0]["estoque_disponivel"] == 0
    assert products[0]["_stock_sync_status"] == "unavailable"
    assert products[0]["sku"] == "SKU-10"
    assert products[0]["precos"] == previous["10"]["precos"]
    assert products[0]["anexos"] == previous["10"]["anexos"]


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

def test_public_product_extracts_nested_olist_seo():
    product = daemon.public_product({
        "id": 1,
        "sku": "SKU-1",
        "descricao": "Produto",
        "situacao": "A",
        "seo": {
            "titulo": "Titulo SEO",
            "descricao": "Descricao SEO",
            "keywords": ["vaso japi", "decoracao"],
            "slug": "produto-japi",
            "linkVideo": "https://example.test/video",
        },
    })

    assert product["seo_title"] == "Titulo SEO"
    assert product["seo_description"] == "Descricao SEO"
    assert product["keywords"] == ["vaso japi", "decoracao"]
    assert product["slug"] == "produto-japi"
    assert product["video_url"] == "https://example.test/video"
