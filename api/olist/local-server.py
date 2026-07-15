#!/usr/bin/env python3
"""
API Olist Local - Para testes sem depender de servidor remoto
Simula endpoints reais do Olist para desenvolvimento/testes
"""

from flask import Flask, request, jsonify
from datetime import datetime, timedelta
import json
import os

app = Flask(__name__)

# Simular banco de dados local
ORDERS_DB = {}
PRODUCTS_DB = {}

# Dados de teste iniciais
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

ORDERS_DB = INITIAL_ORDERS.copy()
PRODUCTS_DB = INITIAL_PRODUCTS.copy()


# ============================================================================
# ENDPOINTS DE AUTENTICAÇÃO
# ============================================================================

@app.route('/auth/authorize', methods=['POST'])
def authorize():
    """OAuth authorize endpoint"""
    return jsonify({
        "authorize_url": "http://localhost:5000/oauth/authorize",
        "token_url": "http://localhost:5000/oauth/token"
    })


@app.route('/oauth/token', methods=['POST'])
def oauth_token():
    """Retorna token de acesso"""
    return jsonify({
        "access_token": "test-token-12345",
        "token_type": "Bearer",
        "expires_in": 3600,
        "refresh_token": "refresh-token-12345"
    })


# ============================================================================
# ENDPOINTS DE PEDIDOS (ORDERS)
# ============================================================================

@app.route('/v2/orders', methods=['GET'])
def get_orders():
    """Lista todos os pedidos"""
    status = request.args.get('status')
    limit = request.args.get('limit', 100, type=int)

    orders = list(ORDERS_DB.values())

    if status:
        orders = [o for o in orders if o['status'] == status]

    return jsonify({
        "results": orders[:limit],
        "pagination": {
            "next": None,
            "previous": None,
            "count": len(orders)
        }
    })


@app.route('/v2/orders/<order_id>', methods=['GET'])
def get_order(order_id):
    """Obter detalhes de um pedido"""
    if order_id not in ORDERS_DB:
        return jsonify({"error": "Order not found"}), 404

    return jsonify(ORDERS_DB[order_id])


@app.route('/v2/orders/<order_id>', methods=['PATCH'])
def update_order(order_id):
    """Atualizar status do pedido"""
    if order_id not in ORDERS_DB:
        return jsonify({"error": "Order not found"}), 404

    data = request.json
    order = ORDERS_DB[order_id]

    if 'status' in data:
        order['status'] = data['status']
    if 'tracking_number' in data:
        order['tracking_number'] = data['tracking_number']
    if 'estimated_delivery' in data:
        order['estimated_delivery'] = data['estimated_delivery']

    order['updated_at'] = datetime.now().isoformat()

    return jsonify(order)


# ============================================================================
# ENDPOINTS DE PRODUTOS
# ============================================================================

@app.route('/v2/products', methods=['GET'])
def get_products():
    """Lista todos os produtos"""
    limit = request.args.get('limit', 100, type=int)

    products = list(PRODUCTS_DB.values())

    return jsonify({
        "results": products[:limit],
        "pagination": {
            "next": None,
            "previous": None,
            "count": len(products)
        }
    })


@app.route('/v2/products/<product_id>', methods=['GET'])
def get_product(product_id):
    """Obter detalhes de um produto"""
    if product_id not in PRODUCTS_DB:
        return jsonify({"error": "Product not found"}), 404

    return jsonify(PRODUCTS_DB[product_id])


@app.route('/v2/products', methods=['POST'])
def create_product():
    """Criar novo produto"""
    data = request.json
    product_id = f"prod-{len(PRODUCTS_DB) + 1:03d}"

    product = {
        "id": product_id,
        "sku": data.get('sku'),
        "name": data.get('name'),
        "price": data.get('price', 0),
        "quantity": data.get('quantity', 0),
        "status": "active",
        "created_at": datetime.now().isoformat(),
    }

    PRODUCTS_DB[product_id] = product
    return jsonify(product), 201


@app.route('/v2/products/<product_id>', methods=['PATCH'])
def update_product(product_id):
    """Atualizar produto"""
    if product_id not in PRODUCTS_DB:
        return jsonify({"error": "Product not found"}), 404

    data = request.json
    product = PRODUCTS_DB[product_id]

    if 'name' in data:
        product['name'] = data['name']
    if 'price' in data:
        product['price'] = data['price']
    if 'quantity' in data:
        product['quantity'] = data['quantity']
    if 'status' in data:
        product['status'] = data['status']

    product['updated_at'] = datetime.now().isoformat()

    return jsonify(product)


# ============================================================================
# ENDPOINTS DE WEBHOOKS
# ============================================================================

@app.route('/webhooks', methods=['GET'])
def list_webhooks():
    """Listar webhooks registrados"""
    return jsonify({
        "webhooks": [
            {
                "id": "hook-001",
                "url": "http://localhost/api/webhooks/order-status-update.php",
                "event": "orders.v2",
                "active": True
            }
        ]
    })


@app.route('/webhooks', methods=['POST'])
def register_webhook():
    """Registrar novo webhook"""
    data = request.json

    return jsonify({
        "id": f"hook-{len(list_webhooks().json.get('webhooks', [])) + 1}",
        "url": data.get('url'),
        "event": data.get('event'),
        "active": True,
        "created_at": datetime.now().isoformat()
    }), 201


# ============================================================================
# ENDPOINTS DE STATUS
# ============================================================================

@app.route('/health', methods=['GET'])
def health():
    """Health check"""
    return jsonify({
        "status": "healthy",
        "timestamp": datetime.now().isoformat(),
        "orders_count": len(ORDERS_DB),
        "products_count": len(PRODUCTS_DB)
    })


@app.route('/status', methods=['GET'])
def status():
    """Status da API"""
    return jsonify({
        "api": "Olist Local",
        "version": "2.0",
        "environment": "development",
        "timestamp": datetime.now().isoformat(),
        "endpoints": {
            "orders": "/v2/orders",
            "products": "/v2/products",
            "auth": "/oauth/token",
            "webhooks": "/webhooks",
            "health": "/health"
        }
    })


# ============================================================================
# ERRO HANDLER
# ============================================================================

@app.errorhandler(404)
def not_found(error):
    return jsonify({"error": "Not found"}), 404


@app.errorhandler(500)
def internal_error(error):
    return jsonify({"error": "Internal server error"}), 500


# ============================================================================
# MAIN
# ============================================================================

if __name__ == '__main__':
    print("=" * 60)
    print("🚀 API Olist Local")
    print("=" * 60)
    print("")
    print("URLs disponíveis:")
    print("  • Health: http://localhost:5000/health")
    print("  • Status: http://localhost:5000/status")
    print("  • Orders: http://localhost:5000/v2/orders")
    print("  • Products: http://localhost:5000/v2/products")
    print("  • Webhooks: http://localhost:5000/webhooks")
    print("")
    print("Dados iniciais:")
    print(f"  • {len(ORDERS_DB)} pedidos")
    print(f"  • {len(PRODUCTS_DB)} produtos")
    print("")
    print("Executando em http://localhost:5000")
    print("Pressione CTRL+C para parar")
    print("=" * 60)
    print("")

    app.run(debug=True, port=5000, host='localhost')
