import importlib.util
from pathlib import Path
from unittest.mock import patch

import pytest

MODULE_PATH = Path(__file__).resolve().parents[1] / "daemon-sync-products.py"
spec = importlib.util.spec_from_file_location("amazon_sync_daemon", MODULE_PATH)
daemon = importlib.util.module_from_spec(spec)
assert spec and spec.loader
spec.loader.exec_module(daemon)


def table_fixture():
    return {
        "id": 991, "descricao": "Amazon", "acrescimoDesconto": 10,
        "excecoes": [
            {"idProduto": 20, "codigo": "EX-20", "preco": 37.89, "precoPromocional": 32.0}
        ],
    }


def test_amazon_price_table_general_exception_and_idempotent(monkeypatch):
    monkeypatch.setattr(daemon, "api_get", lambda *args, **kwargs: table_fixture())
    table = daemon.fetch_amazon_table("fake-token")
    products = [
        {"id": 10, "sku": "N-10", "_erp_base_prices": {"preco": 15, "precoPromocional": 12}, "precos": {"preco": 999}},
        {"id": 20, "sku": "EX-20", "_erp_base_prices": {"preco": 15, "precoPromocional": 0}, "precos": {"preco": 999}},
    ]
    assert daemon.apply_amazon_prices(products, table) == 1
    assert products[0]["precos"] == {"preco": 16.5, "precoPromocional": 13.2}
    assert products[1]["precos"] == {"preco": 37.89, "precoPromocional": 32.0}
    assert all(p["_price_list_id"] == 991 and p["sync_source"] == "tiny_v3" for p in products)
    assert daemon.apply_amazon_prices(products, table) == 1
    assert products[0]["precos"]["preco"] == 16.5


def test_removed_exception_returns_to_original_erp_base(monkeypatch):
    fixture = table_fixture()
    fixture["excecoes"] = []
    monkeypatch.setattr(daemon, "api_get", lambda *args, **kwargs: fixture)
    products = [{"id": 20, "_erp_base_prices": {"preco": 20.15, "precoPromocional": 0},
                 "precos": {"preco": 37.89}}]
    assert daemon.apply_amazon_prices(products, daemon.fetch_amazon_table("fake-token")) == 0
    assert products[0]["precos"]["preco"] == 22.17


def test_amazon_price_table_invalid_fails_closed(monkeypatch):
    for bad in (
        {"id": 982, "descricao": "PDV", "acrescimoDesconto": 45, "excecoes": []},
        {"id": 991, "descricao": "Amazon", "acrescimoDesconto": 0},
        {"id": 991, "descricao": "Amazon", "acrescimoDesconto": 0, "excecoes": [{"idProduto": 2, "preco": 0}]},
    ):
        monkeypatch.setattr(daemon, "api_get", lambda *args, **kwargs: bad)
        with pytest.raises(RuntimeError):
            daemon.fetch_amazon_table("fake-token")
    with pytest.raises(RuntimeError, match="erp_base_price_unavailable"):
        daemon.apply_amazon_prices([{"id": 1, "precos": {"preco": 99}}], (0, {}))


def test_erp_table_failure_does_not_overwrite_storefront_cache(monkeypatch):
    monkeypatch.setattr(daemon, "get_token", lambda: "fake-token")
    monkeypatch.setattr(daemon, "fetch_amazon_table", lambda token: (_ for _ in ()).throw(RuntimeError("ERP unavailable")))
    monkeypatch.setattr(daemon, "save_products", lambda products: pytest.fail("must not write"))
    with pytest.raises(RuntimeError, match="ERP unavailable"):
        daemon.sync_once(workers=1)
