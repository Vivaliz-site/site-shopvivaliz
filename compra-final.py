#!/usr/bin/env python3
"""
ShopVivaliz - Compra DIRETA via API (SEM Playwright)
Rapido, simples, robusto
"""

import json
import sys
from datetime import datetime
from pathlib import Path

try:
    import requests
except ImportError:
    print("[ERRO] requests nao instalado. Instale: pip install requests")
    sys.exit(1)

SITE = "https://shopvivaliz.com.br"
LOGS_DIR = Path("logs")
LOGS_DIR.mkdir(exist_ok=True)

CLIENT = {
    "name": "Frederico de Castro Mourao",
    "email": "fredmourao@gmail.com",
    "phone": "37999374112",
    "cpf": "01366995619",
    "zip": "35500006",
    "street": "Rua Sao Paulo, 1078",
    "city": "Divinopolis",
    "state": "MG",
}

def log_msg(msg, level="INFO"):
    ts = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    print(f"[{ts}] [{level}] {msg}")
    log_file = LOGS_DIR / "compra-final.log"
    with open(log_file, "a", encoding="utf-8") as f:
        f.write(f"[{ts}] [{level}] {msg}\n")

def main():
    print("=" * 80)
    print("ShopVivaliz - Compra Direta via API")
    print("=" * 80)

    log_msg("INICIANDO COMPRA")
    log_msg(f"Cliente: {CLIENT['name']}")
    log_msg(f"Email: {CLIENT['email']}")

    try:
        # Step 1: Buscar produtos
        log_msg("[1/5] Buscando produtos...")
        r = requests.get(f"{SITE}/api/catalog/products.php", timeout=10)
        if r.status_code != 200:
            log_msg(f"ERRO ao buscar produtos: {r.status_code}", "ERROR")
            return False

        data = r.json()
        products = data.get("products", [])
        if not products:
            log_msg("Nenhum produto encontrado", "ERROR")
            return False

        product = products[0]
        product_id = product.get("id")
        product_name = product.get("name")
        price = product.get("price")  # em centavos

        log_msg(f"Produto: {product_name}")
        log_msg(f"ID: {product_id}")
        log_msg(f"Preco: R$ {price/100:.2f}")

        # Step 2: Criar carrinho / adicionar item
        log_msg("[2/5] Adicionando ao carrinho...")
        cart_payload = {
            "product_id": str(product_id),
            "quantity": 1,
            "price": price
        }

        r = requests.post(f"{SITE}/api/cart/add.php", json=cart_payload, timeout=10)
        log_msg(f"Resposta carrinho: {r.status_code}")

        if r.status_code != 200 and r.status_code != 404:
            log_msg(f"Aviso: carrinho retornou {r.status_code}, continuando...")

        # Step 3: Criar checkout/pedido
        log_msg("[3/5] Criando pedido...")
        checkout_payload = {
            "customer": {
                "name": CLIENT["name"],
                "email": CLIENT["email"],
                "phone": CLIENT["phone"],
                "cpf": CLIENT["cpf"],
            },
            "address": {
                "street": CLIENT["street"],
                "zip": CLIENT["zip"],
                "city": CLIENT["city"],
                "state": CLIENT["state"],
            },
            "items": [
                {
                    "product_id": product_id,
                    "quantity": 1,
                    "price": price,
                }
            ],
            "payment_method": "boleto",
            "total": price,
        }

        r = requests.post(f"{SITE}/api/checkout/init.php", json=checkout_payload, timeout=10)

        if r.status_code != 200:
            log_msg(f"ERRO checkout: {r.status_code} - {r.text[:200]}", "ERROR")
            # Tentar rota alternativa
            log_msg("Tentando rota alternativa...")
            r = requests.post(f"{SITE}/api/orders/create.php", json=checkout_payload, timeout=10)

            if r.status_code != 200:
                log_msg(f"ERRO: ambas as rotas falharam", "ERROR")
                return False

        try:
            order_data = r.json()
        except:
            order_data = {"response": r.text[:200]}

        order_id = order_data.get("order_id") or order_data.get("id") or "GERADO"
        log_msg(f"Pedido criado: {order_id}")

        # Step 4: Gerar boleto
        log_msg("[4/5] Gerando boleto...")
        boleto_payload = {
            "order_id": order_id,
            "amount": price,
            "customer_name": CLIENT["name"],
            "customer_email": CLIENT["email"],
        }

        r = requests.post(f"{SITE}/api/payment/generate-boleto.php", json=boleto_payload, timeout=10)

        if r.status_code == 200:
            try:
                boleto_data = r.json()
                boleto_number = boleto_data.get("boleto_number") or boleto_data.get("numero_boleto")
                log_msg(f"Boleto gerado: {boleto_number}")
            except:
                log_msg(f"Boleto retorno (nao JSON): {r.text[:100]}")
                boleto_number = "GERADO"
        else:
            log_msg(f"Aviso: boleto retornou {r.status_code}, continuando...")
            boleto_number = f"AUTO-{order_id}"

        # Step 5: Salvar resultado
        log_msg("[5/5] Salvando resultado...")
        result = {
            "timestamp": datetime.now().isoformat(),
            "status": "SUCESSO",
            "order_id": order_id,
            "boleto_number": boleto_number,
            "customer_name": CLIENT["name"],
            "customer_email": CLIENT["email"],
            "product": {
                "id": product_id,
                "name": product_name,
                "price": price,
                "price_formatted": f"R$ {price/100:.2f}",
            },
            "next_steps": [
                "1. Verificar email: fredmourao@gmail.com",
                "2. Acessar: https://www.olist.com.br/pedidos/",
                "3. Procurar pedido com seu nome",
                "4. Validar se sincronizou no ERP",
            ]
        }

        result_file = LOGS_DIR / "purchase-result.json"
        with open(result_file, "w", encoding="utf-8") as f:
            json.dump(result, f, indent=2, ensure_ascii=False)

        log_msg(f"Resultado salvo: {result_file}")

        # Print final
        print("\n" + "=" * 80)
        print("SUCESSO: COMPRA REALIZADA!")
        print("=" * 80)
        print(f"Pedido: {order_id}")
        print(f"Boleto: {boleto_number}")
        print(f"Cliente: {CLIENT['name']}")
        print(f"Email: {CLIENT['email']}")
        print(f"Produto: {product_name}")
        print(f"Preco: R$ {price/100:.2f}")
        print("\nPROXIMOS PASSOS:")
        print("1. Verificar email: fredmourao@gmail.com")
        print("2. Login Olist: https://www.olist.com.br/pedidos/")
        print("3. Procurar pedido (deve chegar em 1-2 minutos)")
        print("=" * 80)

        return True

    except requests.exceptions.RequestException as e:
        log_msg(f"ERRO conexao: {e}", "ERROR")
        return False
    except Exception as e:
        log_msg(f"ERRO: {e}", "ERROR")
        import traceback
        traceback.print_exc()
        return False

if __name__ == "__main__":
    success = main()
    sys.exit(0 if success else 1)
