"""Authoritative Olist/Tiny v3 PDV pricing for the published catalog.

Read-only API calls. Never replace PDV with unqualified product registration
prices, guess a price-list ID, or update ERP commercial values.
"""
from __future__ import annotations

from decimal import Decimal, InvalidOperation, ROUND_HALF_UP
from typing import Any, Callable

ApiGet = Callable[[str, str], dict[str, Any]]


def _number(value: Any, code: str) -> Decimal:
    if value is None or isinstance(value, bool):
        raise ValueError(code)
    try:
        amount = Decimal(str(value))
    except (TypeError, ValueError, InvalidOperation) as exc:
        raise ValueError(code) from exc
    if not amount.is_finite():
        raise ValueError(code)
    return amount


def _money(amount: Decimal) -> float:
    return float(amount.quantize(Decimal("0.01"), rounding=ROUND_HALF_UP))


def discover_pdv(api_get: ApiGet, token: str) -> dict[str, Any]:
    """Discover exactly one PDV price list using paginated *live* v3 results."""
    if not token:
        raise ValueError("pdv_token_missing")
    matches: dict[int, dict[str, Any]] = {}
    for offset in range(0, 10100, 100):
        data = api_get(f"listas-precos?limit=100&offset={offset}", token)
        if not isinstance(data, dict) or not isinstance(data.get("itens"), list):
            raise ValueError("pdv_list_schema_invalid")
        for row in data["itens"]:
            if not isinstance(row, dict):
                raise ValueError("pdv_list_row_invalid")
            if str(row.get("descricao") or "").strip().casefold() != "pdv":
                continue
            try:
                list_id = int(str(row["id"]))
            except (KeyError, TypeError, ValueError) as exc:
                raise ValueError("pdv_list_id_invalid") from exc
            if list_id <= 0:
                raise ValueError("pdv_list_id_invalid")
            matches[list_id] = row
        pagination = data.get("paginacao")
        if not isinstance(pagination, dict) or type(pagination.get("total")) is not int:
            raise ValueError("pdv_list_pagination_missing")
        if offset + 100 >= pagination["total"]:
            break
    if len(matches) != 1:
        raise ValueError("pdv_list_not_unique")
    return dict(next(iter(matches.values())))


def _exception(raw: Any, product_id: int) -> dict[str, Any] | None:
    if raw is None or raw == [] or raw == {}:
        return None
    if isinstance(raw, dict):
        entries = [raw]
    elif isinstance(raw, list):
        entries = raw
    else:
        raise ValueError("pdv_exceptions_schema_invalid")
    if len(entries) != 1:
        raise ValueError("pdv_exception_ambiguous")
    row = entries[0]
    if not isinstance(row, dict):
        raise ValueError("pdv_exception_invalid")
    if str(row.get("idProduto") or "").strip() != str(product_id):
        raise ValueError("pdv_exception_wrong_product")
    return row


def pdv_product_prices(api_get: ApiGet, token: str, product_id: int, product_prices: dict[str, Any], pdv: dict[str, Any]) -> dict[str, Any]:
    """Return the only permitted storefront price values, or fail closed."""
    if not token or type(product_id) is not int or product_id < 1:
        raise ValueError("pdv_product_id_missing")
    if not isinstance(pdv, dict) or str(pdv.get("descricao") or "").strip().casefold() != "pdv":
        raise ValueError("pdv_list_not_verified")
    try:
        list_id = int(str(pdv["id"]))
    except (KeyError, TypeError, ValueError) as exc:
        raise ValueError("pdv_list_id_invalid") from exc
    if list_id < 1:
        raise ValueError("pdv_list_id_invalid")
    detail = api_get(f"listas-precos/{list_id}?idProduto={product_id}", token)
    if not isinstance(detail, dict) or str(detail.get("id") or "") != str(list_id) or str(detail.get("descricao") or "").strip().casefold() != "pdv":
        raise ValueError("pdv_detail_identity_mismatch")
    listed_percent = pdv.get("acrescimoDesconto")
    detail_percent = detail.get("acrescimoDesconto")
    if listed_percent is not None and detail_percent is not None and _number(listed_percent, "pdv_percent_invalid") != _number(detail_percent, "pdv_percent_invalid"):
        raise ValueError("pdv_percent_changed_during_sync")
    percent = detail_percent if detail_percent is not None else listed_percent
    exception = _exception(detail.get("excecoes"), product_id)
    if percent is None and (not exception or exception.get("preco") is None):
        raise ValueError("pdv_percent_missing_without_fixed_price")
    factor = Decimal("1") + _number(percent, "pdv_percent_invalid") / Decimal("100") if percent is not None else None
    if factor is not None and factor <= 0:
        raise ValueError("pdv_percent_out_of_range")
    base = product_prices.get("preco") if product_prices.get("preco") is not None else product_prices.get("preco_venda")
    promo = product_prices.get("precoPromocional") if product_prices.get("precoPromocional") is not None else product_prices.get("preco_promocional")
    if promo is None:
        promo = 0
    if exception and exception.get("preco") is not None:
        actual_price = _number(exception["preco"], "pdv_fixed_price_invalid")
    else:
        if base is None or factor is None:
            raise ValueError("pdv_base_unverifiable")
        actual_price = _number(base, "pdv_base_invalid") * factor
    if exception is not None and "precoPromocional" in exception:
        actual_promo = _number(exception["precoPromocional"], "pdv_promo_invalid") if exception["precoPromocional"] is not None else Decimal("0")
    else:
        original_promo = _number(promo, "pdv_promo_invalid")
        if factor is None and original_promo > 0:
            raise ValueError("pdv_promo_percent_unverifiable")
        actual_promo = original_promo * (factor or Decimal("1"))
    price_rounded = _money(actual_price)
    promo_rounded = _money(actual_promo)
    if price_rounded <= 0 or promo_rounded < 0 or (promo_rounded > 0 and promo_rounded >= price_rounded):
        raise ValueError("pdv_commercial_amount_invalid")
    return {
        "preco": price_rounded,
        "precoPromocional": promo_rounded,
        "pdv_list_id": list_id,
        "price_source": "olist_pdv",
    }
