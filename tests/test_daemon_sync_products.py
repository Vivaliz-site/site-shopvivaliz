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


def test_public_product_preserves_parent_and_sanitized_variation_grade():
    parent = daemon.public_product({
        "id": 395224182, "sku": "JVCD33-PAI", "descricao": "Decore 33",
        "tipo": "V", "tipoVariacao": "P",
        "estoque_disponivel": 0,
        "categoria": {"id": 361377079, "nome": "Cachepots", "caminhoCompleto": "Cachepots"},
        "marca": {"id": 138166, "nome": "JAPI"},
        "variacoes": [{
            "id": 352312277, "sku": "JVCDPA33", "descricao": "Decore 33",
            "grade": [{"chave": "Cor", "valor": "Amarelo"}],
            "precos": {"preco": 100, "precoCusto": 60},
            "estoque": {"quantidade": 300},
            "fornecedores": [{"nome": "NAO_PUBLICAR"}],
        }],
    })
    assert parent["tipo"] == "V"
    assert parent["tipoVariacao"] == "P"
    assert parent["produtoPai"] is None
    assert parent["estoque_disponivel"] == 0
    assert parent["categoria"]["id"] == 361377079
    assert parent["marca"]["id"] == 138166
    assert parent["variacoes"] == [{
        "id": 352312277, "sku": "JVCDPA33", "descricao": "Decore 33",
        "grade": [{"chave": "Cor", "valor": "Amarelo"}],
    }]
    assert parent["_cache_schema_version"] == daemon.PUBLIC_PRODUCT_SCHEMA_VERSION
    assert "NAO_PUBLICAR" not in str(parent)

    child = daemon.public_product({
        "id": 352312277, "sku": "JVCDPA33", "tipo": "S",
        "tipoVariacao": "V", "estoque_disponivel": 7,
        "produtoPai": {
            "id": 395224182, "sku": "JVCD33-PAI",
            "descricao": "Decore 33", "tipo": "V",
            "precos": {"precoCusto": 30},
            "client_secret": "NAO_PUBLICAR",
        },
    })
    assert child["produtoPai"] == {
        "id": 395224182, "sku": "JVCD33-PAI",
        "descricao": "Decore 33", "tipo": "V",
    }
    assert child["tipoVariacao"] == "V"
    assert child["estoque_disponivel"] == 7
    assert child["variacoes"] == []
    assert "NAO_PUBLICAR" not in str(child)


def test_legacy_cache_schema_triggers_detail_refetch_even_with_same_erp_marker(monkeypatch):
    from datetime import datetime, timezone
    now = datetime.now(timezone.utc)
    old = {"id": 352312277, "sku": "JVCDPA33",
           "_erp_base_prices": {"preco": 50},
           "_olist_updated_at": "same-erp-change-marker",
           "_detail_synced_at": now.isoformat(),
           "_cache_schema_version": daemon.PUBLIC_PRODUCT_SCHEMA_VERSION - 1,
           "estoque_disponivel": 3}
    assert not daemon.detail_cache_is_fresh(old, "same-erp-change-marker", now=now)
    current = {**old, "_cache_schema_version": daemon.PUBLIC_PRODUCT_SCHEMA_VERSION}
    assert daemon.detail_cache_is_fresh(current, "same-erp-change-marker", now=now)
    monkeypatch.setattr(daemon, "load_previous_cache", lambda: {"352312277": old})
    calls = []

    def api(path, token, **kwargs):
        calls.append(path)
        if path == "produtos/352312277":
            return {
                "id": 352312277, "sku": "JVCDPA33",
                "tipo": "S", "tipoVariacao": "V",
                "precos": {"preco": 50},
                "produtoPai": {"id": 395224182, "sku": "JVCD33-PAI"},
            }
        if path == "estoque/352312277":
            return {"disponivel": 3}
        raise AssertionError(path)

    monkeypatch.setattr(daemon, "api_get", api)
    products, failures = daemon.enrich_products(
        [{"id": 352312277, "dataAlteracao": "same-erp-change-marker"}],
        "fake-test-token", workers=1,
    )
    assert failures == 0
    assert calls == ["produtos/352312277", "estoque/352312277"]
    assert products[0]["tipoVariacao"] == "V"
    assert products[0]["produtoPai"]["id"] == 395224182
    assert products[0]["estoque_disponivel"] == 3
    assert products[0]["_cache_schema_version"] == daemon.PUBLIC_PRODUCT_SCHEMA_VERSION
