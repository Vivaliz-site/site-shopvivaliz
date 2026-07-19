import requests
import json
import sys

url = "https://shopvivaliz.com.br/api/orders/create-v2.php"

payload = {
    "customer_name": "Test User",
    "customer_email": "test@shopvivaliz.com.br",
    "customer_phone": "11999999999",
    "address": "Rua Teste, 123",
    "cep": "01001-000",
    "items": [
        {
            "sku": "CONJ-10-RODIZIOS-35MM-GEL",
            "quantity": 1,
            "price": 77.98
        }
    ],
    "payment_method": "pix",
    "shipping_total": 10.00,
    "shipping_label": "Sedex",
    "total": 87.98
}

try:
    response = requests.post(url, json=payload, headers={"Content-Type": "application/json"}, timeout=15)
    print(f"Status Code: {response.status_code}")
    print(f"Response: {response.text}")
    if response.status_code in [200, 201]:
        print("[SUCESSO] Pedido criado com sucesso na API de Checkout!")
        sys.exit(0)
    else:
        print("[ERRO] Falha ao criar pedido via API.")
        sys.exit(1)
except Exception as e:
    print(f"[ERRO] Falha na requisicao: {e}")
    sys.exit(1)
