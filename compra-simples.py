#!/usr/bin/env python3
"""
ShopVivaliz - Automacao de Compra SIMPLES (sem Playwright)
Usa requests + API direto
"""

import json
import requests
import time
from datetime import datetime
from pathlib import Path

# Dados
SITE = "https://shopvivaliz.com.br"
LOGS_DIR = Path("logs")
LOGS_DIR.mkdir(exist_ok=True)

CLIENT_DATA = {
    "name": "Frederico de Castro Mourao",
    "email": "fredmourao@gmail.com",
    "phone": "37999374112",
    "cpf": "01366995619",
    "zip": "35500006",
    "address": "Rua Sao Paulo, 1078",
    "city": "Divinopolis",
    "state": "MG",
}

def log_msg(msg, level="INFO"):
    """Log simples"""
    ts = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    txt = f"[{ts}] [{level}] {msg}"
    print(txt)

    log_file = LOGS_DIR / "compra-simples.log"
    with open(log_file, "a", encoding="utf-8") as f:
        f.write(txt + "\n")

def test_site():
    """Testar se site está online"""
    log_msg("Testando site...")
    try:
        r = requests.get(SITE, timeout=5)
        if r.status_code == 200:
            log_msg(f"OK: Site respondendo ({r.status_code})")
            return True
        else:
            log_msg(f"ERRO: Site respondeu {r.status_code}", "ERROR")
            return False
    except Exception as e:
        log_msg(f"ERRO ao testar site: {e}", "ERROR")
        return False

def get_products():
    """Buscar produtos"""
    log_msg("Buscando produtos...")
    try:
        r = requests.get(f"{SITE}/api/catalog/products.php", timeout=5)
        if r.status_code == 200:
            data = r.json()
            products = data.get("products", [])
            log_msg(f"Encontrados {len(products)} produtos")
            return products
        else:
            log_msg(f"ERRO ao buscar produtos: {r.status_code}", "ERROR")
            return []
    except Exception as e:
        log_msg(f"ERRO: {e}", "ERROR")
        return []

def add_to_cart(product_id, quantity=1):
    """Adicionar ao carrinho"""
    log_msg(f"Adicionando produto {product_id} ao carrinho...")
    try:
        payload = {"product_id": product_id, "quantity": quantity}
        r = requests.post(f"{SITE}/api/cart/add.php", json=payload, timeout=5)
        if r.status_code == 200:
            log_msg(f"OK: Adicionado ao carrinho")
            return r.json()
        else:
            log_msg(f"ERRO: {r.status_code}", "ERROR")
            return None
    except Exception as e:
        log_msg(f"ERRO: {e}", "ERROR")
        return None

def checkout(customer_data, payment_method="boleto"):
    """Fazer checkout"""
    log_msg(f"Iniciando checkout...")
    try:
        payload = {
            "customer": {
                "name": customer_data["name"],
                "email": customer_data["email"],
                "phone": customer_data["phone"],
                "cpf": customer_data["cpf"],
            },
            "address": {
                "zip": customer_data["zip"],
                "street": customer_data["address"],
                "city": customer_data["city"],
                "state": customer_data["state"],
            },
            "payment_method": payment_method,
        }

        r = requests.post(f"{SITE}/api/checkout/init.php", json=payload, timeout=10)
        if r.status_code == 200:
            result = r.json()
            log_msg(f"OK: Checkout realizado")
            return result
        else:
            log_msg(f"ERRO: {r.status_code}", "ERROR")
            return None
    except Exception as e:
        log_msg(f"ERRO: {e}", "ERROR")
        return None

def generate_boleto(order_id):
    """Gerar boleto"""
    log_msg(f"Gerando boleto para pedido {order_id}...")
    try:
        r = requests.post(
            f"{SITE}/api/payment/generate-boleto.php",
            json={"order_id": order_id},
            timeout=10
        )
        if r.status_code == 200:
            result = r.json()
            log_msg(f"OK: Boleto gerado")
            return result
        else:
            log_msg(f"ERRO: {r.status_code}", "ERROR")
            return None
    except Exception as e:
        log_msg(f"ERRO: {e}", "ERROR")
        return None

def main():
    """Fluxo principal"""
    log_msg("=" * 80, "START")
    log_msg("INICIANDO COMPRA AUTOMATICA")
    log_msg(f"Cliente: {CLIENT_DATA['name']}")
    log_msg(f"Email: {CLIENT_DATA['email']}")

    # 1. Testar site
    if not test_site():
        log_msg("Site nao acessivel!", "ERROR")
        return False

    time.sleep(1)

    # 2. Buscar produtos
    products = get_products()
    if not products:
        log_msg("Nenhum produto encontrado!", "ERROR")
        return False

    # 3. Pegar primeiro produto
    if not products:
        log_msg("Lista de produtos vazia", "ERROR")
        return False

    product = products[0]
    product_id = product.get("id")
    product_name = product.get("name")
    log_msg(f"Selecionado: {product_name} (ID: {product_id})")

    time.sleep(1)

    # 4. Adicionar ao carrinho
    cart = add_to_cart(product_id)
    if not cart:
        log_msg("Falha ao adicionar ao carrinho", "ERROR")
        return False

    time.sleep(1)

    # 5. Checkout
    result = checkout(CLIENT_DATA, "boleto")
    if not result:
        log_msg("Falha no checkout", "ERROR")
        return False

    order_id = result.get("order_id")
    log_msg(f"Pedido criado: {order_id}")

    time.sleep(2)

    # 6. Gerar boleto
    boleto = generate_boleto(order_id)
    if not boleto:
        log_msg("Falha ao gerar boleto", "ERROR")
        return False

    boleto_number = boleto.get("boleto_number")
    log_msg(f"Boleto gerado: {boleto_number}")

    # Resultado
    log_msg("=" * 80)
    log_msg("SUCESSO: COMPRA REALIZADA!")
    log_msg("=" * 80)

    result_data = {
        "timestamp": datetime.now().isoformat(),
        "status": "SUCESSO",
        "order_id": order_id,
        "boleto_number": boleto_number,
        "customer": CLIENT_DATA["name"],
        "email": CLIENT_DATA["email"],
        "product_name": product.get("name"),
        "total": result.get("total"),
    }

    result_file = LOGS_DIR / "compra-resultado.json"
    with open(result_file, "w", encoding="utf-8") as f:
        json.dump(result_data, f, indent=2, ensure_ascii=False)

    log_msg(f"Resultado salvo: {result_file}")

    log_msg("")
    log_msg("PROXIMOS PASSOS:")
    log_msg("1. Verificar email: fredmourao@gmail.com")
    log_msg("2. Login Olist: https://www.olist.com.br/pedidos/")
    log_msg("3. Procurar pedido com seu nome")
    log_msg("")

    return True

if __name__ == "__main__":
    try:
        success = main()
        if success:
            log_msg("Compra concluida com sucesso!", "INFO")
        else:
            log_msg("Compra falhou", "ERROR")
    except Exception as e:
        log_msg(f"ERRO FATAL: {e}", "ERROR")
        import traceback
        log_msg(traceback.format_exc(), "ERROR")
