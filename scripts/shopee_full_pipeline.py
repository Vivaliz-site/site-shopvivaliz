import time
import hmac
import hashlib
import requests
import os

# =========================
# CONFIG (JÁ PREENCHIDO)
# =========================

PARTNER_ID = 1237032
PARTNER_KEY = "shpk574f454f6a756e534e7476726b67727a5242554c76736d4b56567769554d"

BASE_URL = "https://partner.test-stable.shopeemobile.com"
AUTH_BASE_URL = "https://openplatform.sandbox.test-stable.shopee.sg"

REDIRECT = "https://shopvivaliz.com.br"

# =========================
# SIGN
# =========================
def generate_sign(path, timestamp):
    base_string = f"{PARTNER_ID}{path}{timestamp}"
    return hmac.new(
        PARTNER_KEY.encode("utf-8"),
        base_string.encode("utf-8"),
        hashlib.sha256
    ).hexdigest()

def generate_shop_sign(path, timestamp, access_token, shop_id):
    base_string = f"{PARTNER_ID}{path}{timestamp}{access_token}{shop_id}"
    return hmac.new(
        PARTNER_KEY.encode("utf-8"),
        base_string.encode("utf-8"),
        hashlib.sha256
    ).hexdigest()

# =========================
# STEP 1 - AUTH
# =========================
def get_auth_url():
    path = "/api/v2/shop/auth_partner"
    timestamp = int(time.time())
    sign = generate_sign(path, timestamp)

    url = (
        f"{AUTH_BASE_URL}{path}"
        f"?partner_id={PARTNER_ID}"
        f"&timestamp={timestamp}"
        f"&sign={sign}"
        f"&redirect={REDIRECT}"
    )

    print("\n=============================")
    print("🔗 ABRA NO NAVEGADOR:")
    print("=============================\n")
    print(url)
    print("\n=============================\n")

# =========================
# STEP 2 - TOKEN
# =========================
def get_token(code, shop_id):
    path = "/api/v2/auth/token/get"
    timestamp = int(time.time())
    sign = generate_sign(path, timestamp)

    url = (
        f"{BASE_URL}{path}"
        f"?partner_id={PARTNER_ID}"
        f"&timestamp={timestamp}"
        f"&sign={sign}"
    )

    payload = {
        "code": code,
        "shop_id": int(shop_id),
        "partner_id": PARTNER_ID
    }

    response = requests.post(url, json=payload)
    data = response.json()

    print("\n=============================")
    print("✅ TOKEN GERADO:")
    print("=============================\n")
    print(data)

    if "access_token" in data:
        with open("token.json", "w") as f:
            f.write(str(data))

    return data

# =========================
# STEP 3 - UPLOAD IMAGEM
# =========================
def upload_image(access_token, shop_id, image_path):
    path = "/api/v2/media_space/upload_image"
    timestamp = int(time.time())

    sign = generate_shop_sign(path, timestamp, access_token, shop_id)

    url = (
        f"{BASE_URL}{path}"
        f"?partner_id={PARTNER_ID}"
        f"&timestamp={timestamp}"
        f"&sign={sign}"
        f"&access_token={access_token}"
        f"&shop_id={shop_id}"
    )

    if not os.path.exists(image_path):
        print("❌ Arquivo não encontrado:", image_path)
        return

    files = {
        "image": open(image_path, "rb")
    }

    response = requests.post(url, files=files)
    data = response.json()

    print("\n=============================")
    print("📤 RESULTADO UPLOAD:")
    print("=============================\n")
    print(data)

    return data

# =========================
# STEP 4 - LISTAR PRODUTOS
# =========================
def list_products(access_token, shop_id):
    path = "/api/v2/product/get_item_list"
    timestamp = int(time.time())

    sign = generate_shop_sign(path, timestamp, access_token, shop_id)

    url = (
        f"{BASE_URL}{path}"
        f"?partner_id={PARTNER_ID}"
        f"&timestamp={timestamp}"
        f"&sign={sign}"
        f"&access_token={access_token}"
        f"&shop_id={shop_id}"
        f"&offset=0&limit=10&item_status=NORMAL"
    )

    response = requests.get(url)
    data = response.json()

    print("\n=============================")
    print("📦 PRODUTOS:")
    print("=============================\n")
    print(data)

    return data

# =========================
# MENU
# =========================
def menu():
    print("""
=============================
 SHOPEE PIPELINE FINAL
=============================

1 - Gerar link autorização
2 - Gerar token (code → token)
3 - Upload imagem
4 - Listar produtos
0 - Sair
""")

# =========================
# MAIN
# =========================
if __name__ == "__main__":
    while True:
        menu()
        op = input("Escolha: ")

        if op == "1":
            get_auth_url()

        elif op == "2":
            code = input("Cole o CODE: ")
            shop_id = input("Cole o SHOP_ID: ")
            get_token(code, shop_id)

        elif op == "3":
            token = input("Access Token: ")
            shop_id = input("Shop ID: ")
            img = input("Caminho da imagem: ")
            upload_image(token, shop_id, img)

        elif op == "4":
            token = input("Access Token: ")
            shop_id = input("Shop ID: ")
            list_products(token, shop_id)

        elif op == "0":
            break

        else:
            print("❌ Opção inválida")