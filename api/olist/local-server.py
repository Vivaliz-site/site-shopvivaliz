#!/usr/bin/env python3
"""Local Olist-compatible mock for development only."""
from __future__ import annotations

import secrets
from datetime import datetime, timedelta

from flask import Flask, jsonify, request

app = Flask(__name__)

ORDERS_DB = {}
PRODUCTS_DB = {}
LOCAL_ACCESS_VALUE = "local_mock_" + secrets.token_urlsafe(18)
LOCAL_REFRESH_VALUE = "local_mock_" + secrets.token_urlsafe(18)

INITIAL_ORDERS = {
    "order-001": {
        "id": "order-001",
        "status": "waiting_payment",
        "customer_email": "cliente@example.com",
        "customer_name": "João Silva",
        "items": [
            {"sku": "SKU001", "name": "Produto A", "quantity": 2, "price": 50.00}
        ],
        "total": 100.00,
        "created_at": (datetime.now() - timedelta(days=2)).isoformat(),
    },
    "order-002": {
        "id": "order-002",
        "status": "payment_approved",
        "customer_email": "maria@example.com",
        "customer_name": "Maria Santos",
        "items": [
            {"sku": "SKU002", "name": "Produto B", "quantity": 1, "price": 150.00}
        ],
        "total": 150.00,
        "created_at": (datetime.now() - timedelta(days=1)).isoformat(),
    },
}

INITIAL_PRODUCTS = {
    "prod-001": {
        "id": "prod-001",
        "sku": "SKU001",
        "name": "Rodízio Duplo",
        "price": 50.00,
        "quantity": 100,
        "status": "active",
    },
    "prod-002": {
        "id": "prod-002",
        "sku": "SKU002",
        "name": "Parafuso Aço",
        "price": 150.00,
        "quantity": 500,
        "status": "active",
    },
}

ORDERS_DB.update(INITIAL_ORDERS)
PRODUCTS_DB.update(INITIAL_PRODUCTS)


@app.route("/auth/authorize", methods=["POST"])
def authorize():
    return jsonify({
        "authorize_url": "http://localhost:5000/oauth/authorize",
        "token_url": "http://localhost:5000/oauth/token",
    })


@app.route("/oauth/token", methods=["POST"])
def oauth_token():
    return jsonify({
        "access_token": LOCAL_ACCESS_VALUE,
        "token_type": "Bearer",
        "expires_in": 3600,
        "refresh_token": LOCAL_REFRESH_VALUE,
        "mock": True,
    })


@app.route("/v2/orders", methods=["GET"])
def get_orders():
    status = request.args.get("status")
    limit = request.args.get("limit", 100, type=int)
    orders = list(ORDERS_DB.values())
    if status:
        orders = [order for order in orders if order["status"] == status]
    return jsonify({
        "results": orders[:limit],
        "pagination": {"next": None, "previous": None, "count": len(orders)},
    })


@app.route("/v2/orders/<order_id>", methods=["GET"])
def get_order(order_id):
    if order_id not in ORDERS_DB:
        return jsonify({"error": "Order not found"}), 404
    return jsonify(ORDERS_DB[order_id])


@app.route("/v2/orders/<order_id>", methods=["PATCH"])
def update_order(order_id):
    if order_id not in ORDERS_DB:
        return jsonify({"error": "Order not found"}), 404
    data = request.get_json(silent=True) or {}
    order = ORDERS_DB[order_id]
    for field in ("status", "tracking_number", "estimated_delivery"):
        if field in data:
            order[field] = data[field]
    order["updated_at"] = datetime.now().isoformat()
    return jsonify(order)


@app.route("/v2/products", methods=["GET"])
def get_products():
    limit = request.args.get("limit", 100, type=int)
    products = list(PRODUCTS_DB.values())
    return jsonify({
        "results": products[:limit],
        "pagination": {"next": None, "previous": None, "count": len(products)},
    })


@app.route("/v2/products/<product_id>", methods=["GET"])
def get_product(product_id):
    if product_id not in PRODUCTS_DB:
        return jsonify({"error": "Product not found"}), 404
    return jsonify(PRODUCTS_DB[product_id])


@app.route("/v2/products", methods=["POST"])
def create_product():
    data = request.get_json(silent=True) or {}
    product_id = f"prod-{len(PRODUCTS_DB) + 1:03d}"
    product = {
        "id": product_id,
        "sku": data.get("sku"),
        "name": data.get("name"),
        "price": data.get("price", 0),
        "quantity": data.get("quantity", 0),
        "status": "active",
        "created_at": datetime.now().isoformat(),
    }
    PRODUCTS_DB[product_id] = product
    return jsonify(product), 201


@app.route("/v2/products/<product_id>", methods=["PATCH"])
def update_product(product_id):
    if product_id not in PRODUCTS_DB:
        return jsonify({"error": "Product not found"}), 404
    data = request.get_json(silent=True) or {}
    product = PRODUCTS_DB[product_id]
    for field in ("name", "price", "quantity", "status"):
        if field in data:
            product[field] = data[field]
    product["updated_at"] = datetime.now().isoformat()
    return jsonify(product)


@app.route("/webhooks", methods=["GET"])
def list_webhooks():
    return jsonify({
        "webhooks": [{
            "id": "hook-001",
            "url": "http://localhost/api/webhooks/order-status-update.php",
            "event": "orders.v2",
            "active": True,
        }]
    })


@app.route("/webhooks", methods=["POST"])
def register_webhook():
    data = request.get_json(silent=True) or {}
    return jsonify({
        "id": "hook-local",
        "url": data.get("url"),
        "event": data.get("event"),
        "active": True,
        "created_at": datetime.now().isoformat(),
    }), 201


@app.route("/health", methods=["GET"])
def health():
    return jsonify({
        "status": "healthy",
        "environment": "local-mock",
        "timestamp": datetime.now().isoformat(),
        "orders_count": len(ORDERS_DB),
        "products_count": len(PRODUCTS_DB),
    })


@app.route("/status", methods=["GET"])
def status():
    return jsonify({
        "api": "Olist Local Mock",
        "version": "2.1",
        "environment": "development",
        "timestamp": datetime.now().isoformat(),
        "external_operation_performed": False,
    })


@app.errorhandler(404)
def not_found(_error):
    return jsonify({"error": "Not found"}), 404


@app.errorhandler(500)
def internal_error(_error):
    return jsonify({"error": "Internal server error"}), 500


if __name__ == "__main__":
    print("Olist local mock: http://localhost:5000")
    print("Development only; no provider credentials are accepted.")
    app.run(debug=False, port=5000, host="localhost")
